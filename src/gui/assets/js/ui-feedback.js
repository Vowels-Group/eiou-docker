// Copyright 2025

/**
 * UI Feedback and Loading States Module
 *
 * This module provides comprehensive visual feedback for all user interactions:
 * - Loading states for async operations
 * - Button state management during processing
 * - Form validation feedback
 * - Success/error animations
 * - Toast notifications
 * - Progress tracking
 *
 * Design Principles:
 * - Immediate feedback for every action
 * - Clear communication of system state
 * - Graceful degradation for Tor Browser
 * - Accessible (keyboard navigation, screen readers)
 */

// ========================================
// UTILITY FUNCTIONS
// ========================================

/**
 * Safely query an element (returns null if not found)
 */
function safeQuery(selector) {
    try {
        return document.querySelector(selector);
    } catch (e) {
        console.warn('Invalid selector:', selector);
        return null;
    }
}

/**
 * Safely query all elements (returns empty array if not found)
 */
function safeQueryAll(selector) {
    try {
        return Array.from(document.querySelectorAll(selector));
    } catch (e) {
        console.warn('Invalid selector:', selector);
        return [];
    }
}

// ========================================
// BUTTON LOADING STATES
// ========================================

/**
 * Show loading state on a button
 * @param {HTMLElement|string} button - Button element or selector
 * @param {string} loadingText - Optional text to show while loading
 */
function showButtonLoading(button, loadingText = null) {
    const btn = typeof button === 'string' ? safeQuery(button) : button;
    if (!btn) return;

    // Store original text and state
    btn.dataset.originalText = btn.innerHTML;
    btn.dataset.wasDisabled = btn.disabled;

    // Add loading class and disable button
    btn.classList.add('btn-loading');
    btn.disabled = true;

    // Update text if provided
    if (loadingText) {
        btn.innerHTML = loadingText;
    }

    // Add aria-busy for screen readers
    btn.setAttribute('aria-busy', 'true');
}

/**
 * Hide loading state on a button
 * @param {HTMLElement|string} button - Button element or selector
 */
function hideButtonLoading(button) {
    const btn = typeof button === 'string' ? safeQuery(button) : button;
    if (!btn) return;

    // Remove loading class
    btn.classList.remove('btn-loading');

    // Restore original text if it was stored
    if (btn.dataset.originalText) {
        btn.innerHTML = btn.dataset.originalText;
        delete btn.dataset.originalText;
    }

    // Restore disabled state
    if (btn.dataset.wasDisabled !== 'true') {
        btn.disabled = false;
    }
    delete btn.dataset.wasDisabled;

    // Remove aria-busy
    btn.removeAttribute('aria-busy');
}

// ========================================
// FORM VALIDATION FEEDBACK
// ========================================

/**
 * Show validation success on form field
 * @param {HTMLElement|string} field - Form field element or selector
 * @param {string} message - Success message
 */
function showFieldSuccess(field, message = '') {
    const input = typeof field === 'string' ? safeQuery(field) : field;
    if (!input) return;

    // Remove other states
    input.classList.remove('form-control-invalid', 'form-control-loading');
    input.classList.add('form-control-valid');

    // Update or create feedback element
    updateFieldFeedback(input, message, 'valid');
}

/**
 * Show validation error on form field
 * @param {HTMLElement|string} field - Form field element or selector
 * @param {string} message - Error message
 */
function showFieldError(field, message = '') {
    const input = typeof field === 'string' ? safeQuery(field) : field;
    if (!input) return;

    // Remove other states
    input.classList.remove('form-control-valid', 'form-control-loading');
    input.classList.add('form-control-invalid');

    // Update or create feedback element
    updateFieldFeedback(input, message, 'invalid');
}

/**
 * Show loading state on form field
 * @param {HTMLElement|string} field - Form field element or selector
 * @param {string} message - Loading message
 */
function showFieldLoading(field, message = 'Validating...') {
    const input = typeof field === 'string' ? safeQuery(field) : field;
    if (!input) return;

    // Remove other states
    input.classList.remove('form-control-valid', 'form-control-invalid');
    input.classList.add('form-control-loading');

    // Update or create feedback element
    updateFieldFeedback(input, message, 'loading');
}

/**
 * Clear validation state on form field
 * @param {HTMLElement|string} field - Form field element or selector
 */
function clearFieldFeedback(field) {
    const input = typeof field === 'string' ? safeQuery(field) : field;
    if (!input) return;

    // Remove all validation classes
    input.classList.remove('form-control-valid', 'form-control-invalid', 'form-control-loading');

    // Remove feedback element
    const feedbackId = 'feedback-' + (input.id || input.name);
    const feedback = safeQuery('#' + feedbackId);
    if (feedback) {
        feedback.remove();
    }
}

/**
 * Update or create feedback element for form field
 * @private
 */
function updateFieldFeedback(input, message, type) {
    if (!message) return;

    const feedbackId = 'feedback-' + (input.id || input.name);
    let feedback = safeQuery('#' + feedbackId);

    if (!feedback) {
        feedback = document.createElement('div');
        feedback.id = feedbackId;
        feedback.className = 'form-feedback';
        input.parentNode.insertBefore(feedback, input.nextSibling);
    }

    // Update classes
    feedback.className = 'form-feedback form-feedback-' + type;

    // Update message
    feedback.textContent = message;

    // Add icon
    const icon = document.createElement('i');
    if (type === 'valid') {
        icon.className = 'fas fa-check-circle';
    } else if (type === 'invalid') {
        icon.className = 'fas fa-exclamation-circle';
    } else if (type === 'loading') {
        icon.className = 'fas fa-spinner fa-spin';
    }
    feedback.prepend(icon);
    feedback.prepend(document.createTextNode(' '));
}

// ========================================
// TOAST NOTIFICATIONS
// ========================================

let toastContainer = null;
let toastCounter = 0;

/**
 * Initialize toast container
 * @private
 */
function initToastContainer() {
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.className = 'toast-container';
        toastContainer.setAttribute('aria-live', 'polite');
        toastContainer.setAttribute('aria-atomic', 'true');
        document.body.appendChild(toastContainer);
    }
    return toastContainer;
}

/**
 * Show a toast notification
 * @param {string} message - Message to display
 * @param {string} type - Toast type: 'success', 'error', 'warning', 'info'
 * @param {number} duration - Duration in milliseconds (0 = no auto-close)
 * @param {string} title - Optional title
 */
function showToast(message, type = 'info', duration = 5000, title = '') {
    const container = initToastContainer();
    const toastId = 'toast-' + (++toastCounter);

    // Create toast element
    const toast = document.createElement('div');
    toast.id = toastId;
    toast.className = 'toast toast-' + type;
    toast.setAttribute('role', 'alert');

    // Create icon
    const icon = document.createElement('div');
    icon.className = 'toast-icon';
    const iconElement = document.createElement('i');
    if (type === 'success') {
        iconElement.className = 'fas fa-check-circle';
    } else if (type === 'error') {
        iconElement.className = 'fas fa-times-circle';
    } else if (type === 'warning') {
        iconElement.className = 'fas fa-exclamation-triangle';
    } else {
        iconElement.className = 'fas fa-info-circle';
    }
    icon.appendChild(iconElement);

    // Create content
    const content = document.createElement('div');
    content.className = 'toast-content';

    if (title) {
        const titleElement = document.createElement('div');
        titleElement.className = 'toast-title';
        titleElement.textContent = title;
        content.appendChild(titleElement);
    }

    const messageElement = document.createElement('div');
    messageElement.className = 'toast-message';
    messageElement.textContent = message;
    content.appendChild(messageElement);

    // Create close button
    const closeBtn = document.createElement('button');
    closeBtn.className = 'toast-close';
    closeBtn.innerHTML = '&times;';
    closeBtn.setAttribute('aria-label', 'Close notification');
    closeBtn.onclick = function() {
        removeToast(toastId);
    };

    // Assemble toast
    toast.appendChild(icon);
    toast.appendChild(content);
    toast.appendChild(closeBtn);

    // Add to container
    container.appendChild(toast);

    // Auto-remove after duration
    if (duration > 0) {
        setTimeout(function() {
            removeToast(toastId);
        }, duration);
    }

    return toastId;
}

/**
 * Remove a toast notification
 * @param {string} toastId - ID of toast to remove
 */
function removeToast(toastId) {
    const toast = safeQuery('#' + toastId);
    if (!toast) return;

    // Add slide-out animation
    toast.classList.add('toast-slide-out');

    // Remove after animation
    setTimeout(function() {
        if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
        }
    }, 300);
}

// ========================================
// LOADING OVERLAY
// ========================================

let loadingOverlay = null;

/**
 * Show full-screen loading overlay
 * @param {string} message - Loading message
 */
function showLoadingOverlay(message = 'Loading...') {
    // Remove existing overlay
    hideLoadingOverlay();

    // Create overlay
    loadingOverlay = document.createElement('div');
    loadingOverlay.className = 'loading-overlay';
    loadingOverlay.setAttribute('role', 'status');
    loadingOverlay.setAttribute('aria-live', 'polite');

    // Create content
    const content = document.createElement('div');
    content.className = 'loading-overlay-content';

    // Create spinner
    const spinner = document.createElement('div');
    spinner.className = 'spinner spinner-large loading-overlay-spinner';

    // Create text
    const text = document.createElement('p');
    text.className = 'loading-overlay-text';
    text.textContent = message;

    // Assemble
    content.appendChild(spinner);
    content.appendChild(text);
    loadingOverlay.appendChild(content);

    // Add to body
    document.body.appendChild(loadingOverlay);
}

/**
 * Hide full-screen loading overlay
 */
function hideLoadingOverlay() {
    if (loadingOverlay && loadingOverlay.parentNode) {
        loadingOverlay.parentNode.removeChild(loadingOverlay);
        loadingOverlay = null;
    }
}

// ========================================
// INLINE LOADING STATE
// ========================================

/**
 * Show loading state on an element
 * @param {HTMLElement|string} element - Element or selector
 */
function showInlineLoading(element) {
    const el = typeof element === 'string' ? safeQuery(element) : element;
    if (!el) return;

    el.classList.add('loading-inline');
    el.setAttribute('aria-busy', 'true');
}

/**
 * Hide loading state on an element
 * @param {HTMLElement|string} element - Element or selector
 */
function hideInlineLoading(element) {
    const el = typeof element === 'string' ? safeQuery(element) : element;
    if (!el) return;

    el.classList.remove('loading-inline');
    el.removeAttribute('aria-busy');
}

// ========================================
// PROGRESS BAR
// ========================================

/**
 * Create or update a progress bar
 * @param {HTMLElement|string} container - Container element or selector
 * @param {number} percentage - Progress percentage (0-100)
 * @param {string} type - Progress type: 'primary', 'success', 'warning', 'error'
 */
function updateProgress(container, percentage, type = 'primary') {
    const el = typeof container === 'string' ? safeQuery(container) : container;
    if (!el) return;

    // Clamp percentage
    percentage = Math.max(0, Math.min(100, percentage));

    // Find or create progress bar
    let progressBar = el.querySelector('.progress-bar');
    if (!progressBar) {
        const progress = document.createElement('div');
        progress.className = 'progress';
        progressBar = document.createElement('div');
        progressBar.className = 'progress-bar';
        progress.appendChild(progressBar);
        el.appendChild(progress);
    }

    // Update type class
    progressBar.className = 'progress-bar progress-bar-' + type;

    // Update width
    progressBar.style.width = percentage + '%';
    progressBar.setAttribute('aria-valuenow', percentage);
    progressBar.setAttribute('aria-valuemin', '0');
    progressBar.setAttribute('aria-valuemax', '100');

    return progressBar;
}

/**
 * Show indeterminate progress bar
 * @param {HTMLElement|string} container - Container element or selector
 */
function showIndeterminateProgress(container) {
    const el = typeof container === 'string' ? safeQuery(container) : container;
    if (!el) return;

    // Find or create progress bar
    let progress = el.querySelector('.progress');
    if (!progress) {
        progress = document.createElement('div');
        progress.className = 'progress';
        el.appendChild(progress);
    }

    // Clear existing bar
    progress.innerHTML = '';

    // Create indeterminate bar
    const progressBar = document.createElement('div');
    progressBar.className = 'progress-bar progress-bar-indeterminate';
    progressBar.setAttribute('role', 'progressbar');
    progressBar.setAttribute('aria-label', 'Loading');
    progress.appendChild(progressBar);

    return progressBar;
}

// ========================================
// FORM ENHANCEMENT
// ========================================

/**
 * Enhance all forms with loading states
 */
function enhanceForms() {
    const forms = safeQueryAll('form');

    forms.forEach(function(form) {
        // Skip if already enhanced
        if (form.dataset.enhanced === 'true') return;
        form.dataset.enhanced = 'true';

        // Add submit handler
        form.addEventListener('submit', function(e) {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn && !submitBtn.disabled) {
                showButtonLoading(submitBtn);
            }

            // Show loading overlay for long operations
            const isLongOperation = form.dataset.longOperation === 'true';
            if (isLongOperation) {
                const loadingMessage = form.dataset.loadingMessage || 'Processing...';
                showLoadingOverlay(loadingMessage);
            }
        });

        // Add real-time validation on blur
        const inputs = form.querySelectorAll('input, select, textarea');
        inputs.forEach(function(input) {
            // Skip if already enhanced
            if (input.dataset.enhanced === 'true') return;
            input.dataset.enhanced = 'true';

            input.addEventListener('blur', function() {
                // Only validate if field is required and has value
                if (input.required && input.value) {
                    validateField(input);
                }
            });

            // Clear feedback on focus
            input.addEventListener('focus', function() {
                clearFieldFeedback(input);
            });
        });
    });
}

/**
 * Validate a form field
 * @private
 */
function validateField(input) {
    if (!input.checkValidity()) {
        showFieldError(input, input.validationMessage);
        return false;
    } else {
        showFieldSuccess(input);
        return true;
    }
}

// ========================================
// INITIALIZATION
// ========================================

/**
 * Initialize UI feedback on page load
 */
function initUIFeedback() {
    // Enhance all existing forms
    enhanceForms();

    // Re-enhance forms when DOM changes (for dynamic content)
    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length) {
                    enhanceForms();
                }
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initUIFeedback);
} else {
    initUIFeedback();
}

// ========================================
// EXPORT API
// ========================================

// Make functions available globally
window.UIFeedback = {
    // Button states
    showButtonLoading: showButtonLoading,
    hideButtonLoading: hideButtonLoading,

    // Field validation
    showFieldSuccess: showFieldSuccess,
    showFieldError: showFieldError,
    showFieldLoading: showFieldLoading,
    clearFieldFeedback: clearFieldFeedback,

    // Toast notifications
    showToast: showToast,
    removeToast: removeToast,

    // Loading overlays
    showLoadingOverlay: showLoadingOverlay,
    hideLoadingOverlay: hideLoadingOverlay,

    // Inline loading
    showInlineLoading: showInlineLoading,
    hideInlineLoading: hideInlineLoading,

    // Progress bars
    updateProgress: updateProgress,
    showIndeterminateProgress: showIndeterminateProgress,

    // Form enhancement
    enhanceForms: enhanceForms
};
