/**
 * File Hosting Plugin JavaScript
 *
 * Client-side functionality for the file hosting interface
 */

(function() {
    'use strict';

    // API base URL
    const API_BASE = '/api/v1/file-hosting';

    // State
    let state = {
        currentPage: 1,
        totalPages: 1,
        selectedFile: null,
        pricing: null,
        storage: null,
        fileToUpload: null
    };

    // DOM Elements
    const elements = {};

    /**
     * Initialize the application
     */
    function init() {
        cacheElements();
        bindEvents();
        loadInitialData();
    }

    /**
     * Cache DOM elements
     */
    function cacheElements() {
        elements.uploadArea = document.getElementById('uploadArea');
        elements.fileInput = document.getElementById('fileInput');
        elements.uploadForm = document.getElementById('uploadForm');
        elements.selectedFileName = document.getElementById('selectedFileName');
        elements.selectedFileSize = document.getElementById('selectedFileSize');
        elements.clearFile = document.getElementById('clearFile');
        elements.storageDays = document.getElementById('storageDays');
        elements.isPublic = document.getElementById('isPublic');
        elements.accessPassword = document.getElementById('accessPassword');
        elements.fileDescription = document.getElementById('fileDescription');
        elements.estimatedCost = document.getElementById('estimatedCost');
        elements.freeIndicator = document.getElementById('freeIndicator');
        elements.uploadBtn = document.getElementById('uploadBtn');
        elements.filesList = document.getElementById('filesList');
        elements.showExpired = document.getElementById('showExpired');
        elements.refreshFiles = document.getElementById('refreshFiles');
        elements.pagination = document.getElementById('pagination');
        elements.prevPage = document.getElementById('prevPage');
        elements.nextPage = document.getElementById('nextPage');
        elements.pageInfo = document.getElementById('pageInfo');
        elements.fileModal = document.getElementById('fileModal');
        elements.closeModal = document.getElementById('closeModal');
        elements.fileDetails = document.getElementById('fileDetails');
        elements.downloadFileBtn = document.getElementById('downloadFileBtn');
        elements.extendStorageBtn = document.getElementById('extendStorageBtn');
        elements.deleteFileBtn = document.getElementById('deleteFileBtn');
        elements.extendModal = document.getElementById('extendModal');
        elements.closeExtendModal = document.getElementById('closeExtendModal');
        elements.extendFileName = document.getElementById('extendFileName');
        elements.extendDays = document.getElementById('extendDays');
        elements.extendCost = document.getElementById('extendCost');
        elements.cancelExtend = document.getElementById('cancelExtend');
        elements.confirmExtend = document.getElementById('confirmExtend');
        elements.paymentsList = document.getElementById('paymentsList');

        // Storage display elements
        elements.storageBarFill = document.getElementById('storageBarFill');
        elements.storageUsed = document.getElementById('storageUsed');
        elements.storageQuota = document.getElementById('storageQuota');
        elements.storagePercent = document.getElementById('storagePercent');
        elements.fileCount = document.getElementById('fileCount');
        elements.totalSpent = document.getElementById('totalSpent');

        // Pricing display elements
        elements.pricePerMbDay = document.getElementById('pricePerMbDay');
        elements.pricePerGbMonth = document.getElementById('pricePerGbMonth');
        elements.freeStorage = document.getElementById('freeStorage');
    }

    /**
     * Bind event listeners
     */
    function bindEvents() {
        // Upload area events
        elements.uploadArea.addEventListener('click', () => elements.fileInput.click());
        elements.uploadArea.addEventListener('dragover', handleDragOver);
        elements.uploadArea.addEventListener('dragleave', handleDragLeave);
        elements.uploadArea.addEventListener('drop', handleDrop);
        elements.fileInput.addEventListener('change', handleFileSelect);
        elements.clearFile.addEventListener('click', clearSelectedFile);
        elements.storageDays.addEventListener('change', updateCostEstimate);
        elements.uploadBtn.addEventListener('click', uploadFile);

        // Files list events
        elements.showExpired.addEventListener('change', () => loadFiles());
        elements.refreshFiles.addEventListener('click', () => loadFiles());
        elements.prevPage.addEventListener('click', () => changePage(-1));
        elements.nextPage.addEventListener('click', () => changePage(1));

        // Modal events
        elements.closeModal.addEventListener('click', closeFileModal);
        elements.downloadFileBtn.addEventListener('click', downloadFile);
        elements.extendStorageBtn.addEventListener('click', openExtendModal);
        elements.deleteFileBtn.addEventListener('click', deleteFile);
        elements.closeExtendModal.addEventListener('click', closeExtendModal);
        elements.cancelExtend.addEventListener('click', closeExtendModal);
        elements.extendDays.addEventListener('change', updateExtendCost);
        elements.confirmExtend.addEventListener('click', confirmExtendStorage);

        // Close modal on backdrop click
        elements.fileModal.addEventListener('click', (e) => {
            if (e.target === elements.fileModal) closeFileModal();
        });
        elements.extendModal.addEventListener('click', (e) => {
            if (e.target === elements.extendModal) closeExtendModal();
        });
    }

    /**
     * Load initial data
     */
    async function loadInitialData() {
        try {
            await Promise.all([
                loadPricing(),
                loadStorage(),
                loadFiles(),
                loadPayments()
            ]);
        } catch (error) {
            console.error('Failed to load initial data:', error);
        }
    }

    /**
     * API request helper
     */
    async function apiRequest(endpoint, options = {}) {
        const response = await fetch(`${API_BASE}${endpoint}`, {
            headers: {
                'Content-Type': 'application/json',
                ...options.headers
            },
            ...options
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error?.message || 'API request failed');
        }

        return data.data;
    }

    /**
     * Load pricing information
     */
    async function loadPricing() {
        try {
            const data = await apiRequest('/pricing');
            state.pricing = data.pricing;
            updatePricingDisplay();
        } catch (error) {
            console.error('Failed to load pricing:', error);
        }
    }

    /**
     * Load storage information
     */
    async function loadStorage() {
        try {
            const data = await apiRequest('/storage');
            state.storage = data.storage;
            updateStorageDisplay();
        } catch (error) {
            console.error('Failed to load storage:', error);
        }
    }

    /**
     * Load files list
     */
    async function loadFiles() {
        try {
            elements.filesList.innerHTML = '<div class="loading">Loading files...</div>';

            const expired = elements.showExpired.checked ? '&expired=true' : '';
            const data = await apiRequest(`/files?page=${state.currentPage}&per_page=20${expired}`);

            state.totalPages = data.total_pages || 1;
            renderFilesList(data.files);
            updatePagination();
        } catch (error) {
            elements.filesList.innerHTML = '<div class="empty-state">Failed to load files</div>';
            console.error('Failed to load files:', error);
        }
    }

    /**
     * Load payment history
     */
    async function loadPayments() {
        try {
            const data = await apiRequest('/payments?limit=10');
            renderPaymentsList(data.payments);
        } catch (error) {
            elements.paymentsList.innerHTML = '<div class="empty-state">Failed to load payments</div>';
            console.error('Failed to load payments:', error);
        }
    }

    /**
     * Update pricing display
     */
    function updatePricingDisplay() {
        if (!state.pricing) return;

        elements.pricePerMbDay.textContent = `${state.pricing.price_per_mb_per_day} eIOU`;
        elements.pricePerGbMonth.textContent = `${state.pricing.price_per_gb_per_month.toFixed(4)} eIOU`;
        elements.freeStorage.textContent = `${state.pricing.free_storage_mb} MB`;
    }

    /**
     * Update storage display
     */
    function updateStorageDisplay() {
        if (!state.storage) return;

        elements.storageBarFill.style.width = `${state.storage.usage_percentage}%`;
        elements.storageUsed.textContent = state.storage.used_human;
        elements.storageQuota.textContent = state.storage.quota_human;
        elements.storagePercent.textContent = `(${state.storage.usage_percentage.toFixed(1)}%)`;
        elements.fileCount.textContent = `${state.storage.file_count} files`;
        elements.totalSpent.textContent = `${state.storage.total_spent} eIOU spent`;
    }

    /**
     * Render files list
     */
    function renderFilesList(files) {
        if (!files || files.length === 0) {
            elements.filesList.innerHTML = '<div class="empty-state">No files found. Upload your first file!</div>';
            return;
        }

        elements.filesList.innerHTML = files.map(file => {
            const statusClass = file.is_expired ? 'badge-expired' :
                               (file.days_remaining <= 7 ? 'badge-expiring' : 'badge-active');
            const statusText = file.is_expired ? 'Expired' :
                              (file.days_remaining <= 7 ? `${file.days_remaining}d left` : 'Active');

            return `
                <div class="file-item ${file.is_expired ? 'expired' : ''}" data-file-id="${file.file_id}">
                    <div class="file-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                        </svg>
                    </div>
                    <div class="file-info">
                        <div class="file-info-name">${escapeHtml(file.filename)}</div>
                        <div class="file-info-meta">${file.size_human} - ${file.mime_type}</div>
                    </div>
                    <div class="file-status">
                        <span class="file-status-badge ${statusClass}">${statusText}</span>
                        <div class="file-downloads">${file.download_count} downloads</div>
                    </div>
                </div>
            `;
        }).join('');

        // Add click handlers
        elements.filesList.querySelectorAll('.file-item').forEach(item => {
            item.addEventListener('click', () => openFileModal(item.dataset.fileId));
        });
    }

    /**
     * Render payments list
     */
    function renderPaymentsList(payments) {
        if (!payments || payments.length === 0) {
            elements.paymentsList.innerHTML = '<div class="empty-state">No payment history</div>';
            return;
        }

        elements.paymentsList.innerHTML = payments.map(payment => {
            const statusClass = payment.status === 'completed' ? 'status-completed' :
                               (payment.status === 'pending' ? 'status-pending' : 'status-failed');

            return `
                <div class="payment-item">
                    <div class="payment-info">
                        <div class="payment-type">${formatPaymentType(payment.payment_type)}</div>
                        <div class="payment-date">${formatDate(payment.created_at)}</div>
                    </div>
                    <div class="payment-amount">${payment.amount} eIOU</div>
                    <span class="payment-status ${statusClass}">${payment.status}</span>
                </div>
            `;
        }).join('');
    }

    /**
     * Update pagination
     */
    function updatePagination() {
        elements.pagination.style.display = state.totalPages > 1 ? 'flex' : 'none';
        elements.pageInfo.textContent = `Page ${state.currentPage} of ${state.totalPages}`;
        elements.prevPage.disabled = state.currentPage <= 1;
        elements.nextPage.disabled = state.currentPage >= state.totalPages;
    }

    /**
     * Change page
     */
    function changePage(delta) {
        state.currentPage = Math.max(1, Math.min(state.totalPages, state.currentPage + delta));
        loadFiles();
    }

    // File upload handlers
    function handleDragOver(e) {
        e.preventDefault();
        elements.uploadArea.classList.add('dragover');
    }

    function handleDragLeave(e) {
        e.preventDefault();
        elements.uploadArea.classList.remove('dragover');
    }

    function handleDrop(e) {
        e.preventDefault();
        elements.uploadArea.classList.remove('dragover');

        const files = e.dataTransfer.files;
        if (files.length > 0) {
            handleFileSelected(files[0]);
        }
    }

    function handleFileSelect(e) {
        if (e.target.files.length > 0) {
            handleFileSelected(e.target.files[0]);
        }
    }

    function handleFileSelected(file) {
        state.fileToUpload = file;
        elements.selectedFileName.textContent = file.name;
        elements.selectedFileSize.textContent = formatBytes(file.size);
        elements.uploadForm.style.display = 'block';
        elements.uploadArea.style.display = 'none';
        updateCostEstimate();
    }

    function clearSelectedFile() {
        state.fileToUpload = null;
        elements.fileInput.value = '';
        elements.uploadForm.style.display = 'none';
        elements.uploadArea.style.display = 'block';
    }

    /**
     * Update cost estimate
     */
    async function updateCostEstimate() {
        if (!state.fileToUpload || !state.pricing) return;

        const days = parseInt(elements.storageDays.value) || 30;
        const sizeBytes = state.fileToUpload.size;

        try {
            const data = await apiRequest('/calculate', {
                method: 'POST',
                body: JSON.stringify({ size_bytes: sizeBytes, days: days })
            });

            elements.estimatedCost.textContent = `${data.cost.total_cost} eIOU`;

            // Check if within free tier
            const freeBytes = state.pricing.free_storage_mb * 1024 * 1024;
            const currentUsed = state.storage ? state.storage.used_bytes : 0;
            const isFree = (currentUsed + sizeBytes) <= freeBytes;

            elements.freeIndicator.style.display = isFree ? 'inline' : 'none';
        } catch (error) {
            console.error('Failed to calculate cost:', error);
        }
    }

    /**
     * Upload file
     */
    async function uploadFile() {
        if (!state.fileToUpload) return;

        const btnText = elements.uploadBtn.querySelector('.btn-text');
        const btnLoading = elements.uploadBtn.querySelector('.btn-loading');

        try {
            btnText.style.display = 'none';
            btnLoading.style.display = 'inline';
            elements.uploadBtn.disabled = true;

            const formData = new FormData();
            formData.append('file', state.fileToUpload);

            const params = new URLSearchParams({
                days: elements.storageDays.value,
                public: elements.isPublic.checked,
                description: elements.fileDescription.value
            });

            if (elements.accessPassword.value) {
                params.append('password', elements.accessPassword.value);
            }

            const response = await fetch(`${API_BASE}/upload?${params}`, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error?.message || 'Upload failed');
            }

            alert(`File uploaded successfully!\nFile ID: ${data.data.file.file_id}`);

            clearSelectedFile();
            loadFiles();
            loadStorage();
            loadPayments();

        } catch (error) {
            alert(`Upload failed: ${error.message}`);
        } finally {
            btnText.style.display = 'inline';
            btnLoading.style.display = 'none';
            elements.uploadBtn.disabled = false;
        }
    }

    /**
     * Open file details modal
     */
    async function openFileModal(fileId) {
        try {
            const data = await apiRequest(`/files/${fileId}`);
            state.selectedFile = data.file;
            renderFileDetails(data.file);
            elements.fileModal.classList.add('active');
        } catch (error) {
            alert(`Failed to load file details: ${error.message}`);
        }
    }

    /**
     * Render file details
     */
    function renderFileDetails(file) {
        elements.fileDetails.innerHTML = `
            <div class="detail-row">
                <span class="detail-label">File ID</span>
                <span class="detail-value">${file.file_id}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Filename</span>
                <span class="detail-value">${escapeHtml(file.filename)}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Size</span>
                <span class="detail-value">${file.size_human}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">MIME Type</span>
                <span class="detail-value">${file.mime_type}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Public</span>
                <span class="detail-value">${file.is_public ? 'Yes' : 'No'}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Password Protected</span>
                <span class="detail-value">${file.has_password ? 'Yes' : 'No'}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Downloads</span>
                <span class="detail-value">${file.download_count}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Created</span>
                <span class="detail-value">${formatDate(file.created_at)}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Expires</span>
                <span class="detail-value">${formatDate(file.expires_at)}</span>
            </div>
            ${file.days_remaining !== undefined ? `
                <div class="detail-row">
                    <span class="detail-label">Days Remaining</span>
                    <span class="detail-value">${file.days_remaining}</span>
                </div>
            ` : ''}
            ${file.description ? `
                <div class="detail-row">
                    <span class="detail-label">Description</span>
                    <span class="detail-value">${escapeHtml(file.description)}</span>
                </div>
            ` : ''}
        `;
    }

    /**
     * Close file modal
     */
    function closeFileModal() {
        elements.fileModal.classList.remove('active');
        state.selectedFile = null;
    }

    /**
     * Download file
     */
    function downloadFile() {
        if (!state.selectedFile) return;

        const url = `${API_BASE}/download/${state.selectedFile.file_id}`;
        window.open(url, '_blank');
    }

    /**
     * Open extend storage modal
     */
    function openExtendModal() {
        if (!state.selectedFile) return;

        elements.extendFileName.textContent = state.selectedFile.filename;
        elements.extendDays.value = 30;
        updateExtendCost();
        elements.extendModal.classList.add('active');
    }

    /**
     * Close extend modal
     */
    function closeExtendModal() {
        elements.extendModal.classList.remove('active');
    }

    /**
     * Update extend cost estimate
     */
    async function updateExtendCost() {
        if (!state.selectedFile || !state.pricing) return;

        const days = parseInt(elements.extendDays.value) || 30;
        const sizeBytes = state.selectedFile.size_bytes;

        try {
            const data = await apiRequest('/calculate', {
                method: 'POST',
                body: JSON.stringify({ size_bytes: sizeBytes, days: days })
            });

            elements.extendCost.textContent = `${data.cost.total_cost} eIOU`;
        } catch (error) {
            console.error('Failed to calculate extend cost:', error);
        }
    }

    /**
     * Confirm extend storage
     */
    async function confirmExtendStorage() {
        if (!state.selectedFile) return;

        const days = parseInt(elements.extendDays.value) || 30;

        try {
            await apiRequest(`/extend/${state.selectedFile.file_id}`, {
                method: 'POST',
                body: JSON.stringify({ days: days })
            });

            alert('Storage extended successfully!');
            closeExtendModal();
            closeFileModal();
            loadFiles();
            loadStorage();
            loadPayments();

        } catch (error) {
            alert(`Failed to extend storage: ${error.message}`);
        }
    }

    /**
     * Delete file
     */
    async function deleteFile() {
        if (!state.selectedFile) return;

        if (!confirm(`Are you sure you want to delete "${state.selectedFile.filename}"?`)) {
            return;
        }

        try {
            await apiRequest(`/files/${state.selectedFile.file_id}`, {
                method: 'DELETE'
            });

            alert('File deleted successfully!');
            closeFileModal();
            loadFiles();
            loadStorage();

        } catch (error) {
            alert(`Failed to delete file: ${error.message}`);
        }
    }

    // Utility functions
    function formatBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = 0;
        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024;
            i++;
        }
        return bytes.toFixed(2) + ' ' + units[i];
    }

    function formatDate(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleString();
    }

    function formatPaymentType(type) {
        const types = {
            'upload': 'File Upload',
            'extension': 'Storage Extension',
            'plan_upgrade': 'Plan Upgrade',
            'quota_increase': 'Quota Increase'
        };
        return types[type] || type;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
