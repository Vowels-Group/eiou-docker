/**
 * Messages Plugin JavaScript
 *
 * Handles UI interactions for the messaging interface
 */

(function() {
    'use strict';

    // API Configuration
    const API_BASE = '/api/v1/messages';

    // State
    let currentConversationId = null;
    let conversations = [];
    let messages = [];
    let pollInterval = null;

    // DOM Elements
    const elements = {
        conversationsList: document.getElementById('conversations-list'),
        messagesList: document.getElementById('messages-list'),
        messageInput: document.getElementById('message-input'),
        sendBtn: document.getElementById('send-btn'),
        searchInput: document.getElementById('search-input'),
        newConversationBtn: document.getElementById('new-conversation-btn'),
        archiveBtn: document.getElementById('archive-btn'),
        recipientName: document.getElementById('recipient-name'),
        recipientKey: document.getElementById('recipient-key'),
        messageComposer: document.getElementById('message-composer'),
        emptyState: document.getElementById('empty-state'),

        // Modals
        newConversationModal: document.getElementById('new-conversation-modal'),
        modalClose: document.getElementById('modal-close'),
        modalCancel: document.getElementById('modal-cancel'),
        modalStart: document.getElementById('modal-start'),
        recipientKeyInput: document.getElementById('recipient-key-input'),

        searchResultsModal: document.getElementById('search-results-modal'),
        searchModalClose: document.getElementById('search-modal-close'),
        searchResults: document.getElementById('search-results')
    };

    // Initialize
    function init() {
        bindEvents();
        loadConversations();
        startPolling();
    }

    // Event Bindings
    function bindEvents() {
        // Send message
        elements.sendBtn.addEventListener('click', sendMessage);
        elements.messageInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        elements.messageInput.addEventListener('input', updateSendButton);

        // Search
        let searchTimeout;
        elements.searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            const query = e.target.value.trim();
            if (query.length >= 2) {
                searchTimeout = setTimeout(() => searchMessages(query), 300);
            }
        });

        // New conversation modal
        elements.newConversationBtn.addEventListener('click', () => {
            elements.newConversationModal.classList.add('active');
            elements.recipientKeyInput.focus();
        });
        elements.modalClose.addEventListener('click', closeNewConversationModal);
        elements.modalCancel.addEventListener('click', closeNewConversationModal);
        elements.modalStart.addEventListener('click', startNewConversation);
        elements.recipientKeyInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') startNewConversation();
        });

        // Search results modal
        elements.searchModalClose.addEventListener('click', () => {
            elements.searchResultsModal.classList.remove('active');
        });

        // Archive button
        elements.archiveBtn.addEventListener('click', archiveConversation);

        // Close modals on backdrop click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });
    }

    // API Calls
    async function apiCall(endpoint, options = {}) {
        const defaults = {
            headers: {
                'Content-Type': 'application/json'
            }
        };

        const response = await fetch(API_BASE + endpoint, { ...defaults, ...options });
        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error?.message || 'API request failed');
        }

        return data.data;
    }

    // Load Conversations
    async function loadConversations() {
        try {
            elements.conversationsList.innerHTML = '<div class="loading"><div class="loading-spinner"></div></div>';

            const result = await apiCall('/conversations');
            conversations = result.conversations || [];

            renderConversations();
        } catch (error) {
            elements.conversationsList.innerHTML = '<div class="loading">Failed to load conversations</div>';
        }
    }

    // Render Conversations
    function renderConversations() {
        if (conversations.length === 0) {
            elements.conversationsList.innerHTML = `
                <div class="loading">
                    <p>No conversations yet</p>
                    <p style="font-size: 0.85rem; margin-top: 8px;">Click + to start a new conversation</p>
                </div>
            `;
            return;
        }

        elements.conversationsList.innerHTML = conversations.map(conv => `
            <div class="conversation-item ${conv.conversation_id === currentConversationId ? 'active' : ''} ${conv.unread_count > 0 ? 'unread' : ''}"
                 data-id="${conv.conversation_id}"
                 onclick="MessagesApp.selectConversation('${conv.conversation_id}')">
                <div class="conversation-avatar">${getInitials(conv.other_party_name)}</div>
                <div class="conversation-content">
                    <div class="conversation-header">
                        <span class="conversation-name">${escapeHtml(conv.other_party_name)}</span>
                        <span class="conversation-time">${conv.last_message_time_ago || ''}</span>
                    </div>
                    <div class="conversation-preview">
                        ${escapeHtml(conv.last_message_preview || 'No messages yet')}
                        ${conv.unread_count > 0 ? `<span class="unread-badge">${conv.unread_count}</span>` : ''}
                    </div>
                </div>
            </div>
        `).join('');
    }

    // Select Conversation
    async function selectConversation(conversationId) {
        currentConversationId = conversationId;
        renderConversations();

        const conv = conversations.find(c => c.conversation_id === conversationId);
        if (conv) {
            elements.recipientName.textContent = conv.other_party_name;
            elements.recipientKey.textContent = conv.other_party_public_key?.substring(0, 16) + '...';
            elements.archiveBtn.style.display = 'block';
        }

        elements.emptyState.style.display = 'none';
        elements.messageComposer.style.display = 'flex';

        await loadMessages(conversationId);
    }

    // Load Messages
    async function loadMessages(conversationId) {
        try {
            elements.messagesList.innerHTML = '<div class="loading"><div class="loading-spinner"></div></div>';

            const result = await apiCall(`/conversations/${encodeURIComponent(conversationId)}?messages=true`);
            messages = result.messages || [];

            renderMessages();
            scrollToBottom();

            // Update unread count in sidebar
            const conv = conversations.find(c => c.conversation_id === conversationId);
            if (conv) {
                conv.unread_count = 0;
                renderConversations();
            }
        } catch (error) {
            elements.messagesList.innerHTML = '<div class="loading">Failed to load messages</div>';
        }
    }

    // Render Messages
    function renderMessages() {
        if (messages.length === 0) {
            elements.messagesList.innerHTML = `
                <div class="empty-state">
                    <div class="empty-icon">💬</div>
                    <p>No messages yet. Say hello!</p>
                </div>
            `;
            return;
        }

        let lastDate = null;
        let html = '';

        messages.forEach(msg => {
            const msgDate = new Date(msg.created_at).toDateString();
            if (msgDate !== lastDate) {
                html += `<div class="date-separator"><span>${formatDate(msg.created_at)}</span></div>`;
                lastDate = msgDate;
            }

            const bubbleClass = msg.is_own_message ? 'sent' : 'received';
            const readStatus = msg.is_own_message ? (msg.is_read ? '✓✓' : '✓') : '';

            html += `
                <div class="message-bubble ${bubbleClass}">
                    <div class="message-content">${escapeHtml(msg.content)}</div>
                    <div class="message-meta">
                        <span class="message-time">${formatTime(msg.created_at)}</span>
                        ${readStatus ? `<span class="read-status">${readStatus}</span>` : ''}
                    </div>
                </div>
            `;
        });

        elements.messagesList.innerHTML = html;
    }

    // Send Message
    async function sendMessage() {
        const content = elements.messageInput.value.trim();
        if (!content || !currentConversationId) return;

        const conv = conversations.find(c => c.conversation_id === currentConversationId);
        if (!conv) return;

        elements.sendBtn.disabled = true;
        elements.messageInput.disabled = true;

        try {
            await apiCall('/send', {
                method: 'POST',
                body: JSON.stringify({
                    recipient: conv.other_party_public_key,
                    content: content
                })
            });

            elements.messageInput.value = '';
            await loadMessages(currentConversationId);
            await loadConversations();
        } catch (error) {
            alert('Failed to send message: ' + error.message);
        } finally {
            elements.sendBtn.disabled = false;
            elements.messageInput.disabled = false;
            elements.messageInput.focus();
            updateSendButton();
        }
    }

    // Search Messages
    async function searchMessages(query) {
        try {
            const result = await apiCall(`/search?q=${encodeURIComponent(query)}`);

            if (result.messages && result.messages.length > 0) {
                renderSearchResults(result.messages, query);
                elements.searchResultsModal.classList.add('active');
            }
        } catch (error) {
            // Silent fail for search
        }
    }

    // Render Search Results
    function renderSearchResults(results, query) {
        elements.searchResults.innerHTML = results.map(msg => {
            const direction = msg.is_own_message ? 'To' : 'From';
            const otherKey = msg.is_own_message ? msg.recipient_public_key : msg.sender_public_key;
            const shortKey = otherKey?.substring(0, 12) + '...';

            // Highlight matching text
            const regex = new RegExp(`(${escapeRegex(query)})`, 'gi');
            const highlighted = escapeHtml(msg.content).replace(regex, '<mark>$1</mark>');

            return `
                <div class="search-result-item" onclick="MessagesApp.goToMessage('${msg.conversation_id}')">
                    <div class="search-result-header">
                        <span>${direction} ${shortKey}</span>
                        <span>${msg.time_ago}</span>
                    </div>
                    <div class="search-result-content">${highlighted}</div>
                </div>
            `;
        }).join('');
    }

    // Go to message from search
    function goToMessage(conversationId) {
        elements.searchResultsModal.classList.remove('active');
        elements.searchInput.value = '';
        selectConversation(conversationId);
    }

    // Start New Conversation
    async function startNewConversation() {
        const publicKey = elements.recipientKeyInput.value.trim();
        if (!publicKey) {
            alert('Please enter a public key');
            return;
        }

        try {
            const result = await apiCall('/conversations', {
                method: 'POST',
                body: JSON.stringify({ public_key: publicKey })
            });

            closeNewConversationModal();
            await loadConversations();

            if (result.conversation) {
                selectConversation(result.conversation.conversation_id);
            }
        } catch (error) {
            alert('Failed to start conversation: ' + error.message);
        }
    }

    // Archive Conversation
    async function archiveConversation() {
        if (!currentConversationId) return;

        if (!confirm('Archive this conversation?')) return;

        try {
            await apiCall(`/conversations/${encodeURIComponent(currentConversationId)}`, {
                method: 'DELETE'
            });

            currentConversationId = null;
            elements.recipientName.textContent = 'Select a conversation';
            elements.recipientKey.textContent = '';
            elements.archiveBtn.style.display = 'none';
            elements.messageComposer.style.display = 'none';
            elements.emptyState.style.display = 'flex';
            elements.messagesList.innerHTML = '';

            await loadConversations();
        } catch (error) {
            alert('Failed to archive: ' + error.message);
        }
    }

    // Close Modal
    function closeNewConversationModal() {
        elements.newConversationModal.classList.remove('active');
        elements.recipientKeyInput.value = '';
    }

    // Update Send Button State
    function updateSendButton() {
        const hasContent = elements.messageInput.value.trim().length > 0;
        elements.sendBtn.disabled = !hasContent || !currentConversationId;
    }

    // Polling for new messages
    function startPolling() {
        pollInterval = setInterval(async () => {
            if (document.hidden) return;

            await loadConversations();
            if (currentConversationId) {
                const prevCount = messages.length;
                await loadMessages(currentConversationId);
                if (messages.length > prevCount) {
                    scrollToBottom();
                }
            }
        }, 10000); // Poll every 10 seconds
    }

    // Scroll to bottom of messages
    function scrollToBottom() {
        elements.messagesList.scrollTop = elements.messagesList.scrollHeight;
    }

    // Utility Functions
    function getInitials(name) {
        if (!name) return '?';
        const parts = name.split(/[\s_-]+/);
        if (parts.length >= 2) {
            return (parts[0][0] + parts[1][0]).toUpperCase();
        }
        return name.substring(0, 2).toUpperCase();
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function escapeRegex(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function formatDate(dateStr) {
        const date = new Date(dateStr);
        const today = new Date();
        const yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);

        if (date.toDateString() === today.toDateString()) {
            return 'Today';
        } else if (date.toDateString() === yesterday.toDateString()) {
            return 'Yesterday';
        } else {
            return date.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: date.getFullYear() !== today.getFullYear() ? 'numeric' : undefined
            });
        }
    }

    function formatTime(dateStr) {
        const date = new Date(dateStr);
        return date.toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
    }

    // Public API
    window.MessagesApp = {
        selectConversation,
        goToMessage
    };

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
