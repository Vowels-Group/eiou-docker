// Copyright 2025

/**
 * AJAX Form Handler
 *
 * Provides async form submission with loading indicators and better UX.
 * Compatible with Tor Browser (no external dependencies).
 */

// Configuration
const AJAX_CONFIG = {
    // Path to AJAX handler (will be adjusted for Docker environment)
    endpoint: '/api/ajax-handler.php',
    // Timeout for requests (30 seconds)
    timeout: 30000,
    // Whether to reload page after successful submission
    reloadOnSuccess: true,
    // Delay before reload (to show success message)
    reloadDelay: 1500
};

/**
 * Show loading indicator on form
 * @param {HTMLFormElement} form The form element
 */
function showFormLoading(form) {
    // Disable all form inputs
    const inputs = form.querySelectorAll('input, select, textarea, button');
    inputs.forEach(input => {
        input.disabled = true;
        input.dataset.wasDisabled = input.disabled;
    });

    // Find submit button and add loading state
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
        submitBtn.dataset.originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        submitBtn.classList.add('btn-loading');
    }

    // Add loading overlay to form
    const overlay = document.createElement('div');
    overlay.className = 'form-loading-overlay';
    overlay.innerHTML = '<div class="form-loading-spinner"><i class="fas fa-circle-notch fa-spin"></i></div>';
    form.style.position = 'relative';
    form.appendChild(overlay);
}

/**
 * Hide loading indicator on form
 * @param {HTMLFormElement} form The form element
 */
function hideFormLoading(form) {
    // Re-enable all form inputs
    const inputs = form.querySelectorAll('input, select, textarea, button');
    inputs.forEach(input => {
        if (input.dataset.wasDisabled !== 'true') {
            input.disabled = false;
        }
        delete input.dataset.wasDisabled;
    });

    // Restore submit button
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn && submitBtn.dataset.originalText) {
        submitBtn.innerHTML = submitBtn.dataset.originalText;
        submitBtn.classList.remove('btn-loading');
        delete submitBtn.dataset.originalText;
    }

    // Remove loading overlay
    const overlay = form.querySelector('.form-loading-overlay');
    if (overlay) {
        overlay.remove();
    }
}

/**
 * Show notification message to user
 * @param {string} message The message to display
 * @param {string} type Message type: 'success', 'error', 'warning', 'info'
 * @param {number} duration Duration in milliseconds (0 = permanent)
 */
function showNotification(message, type = 'info', duration = 5000) {
    // Remove any existing notifications
    const existing = document.querySelectorAll('.ajax-notification');
    existing.forEach(n => n.remove());

    // Create notification element
    const notification = document.createElement('div');
    notification.className = `ajax-notification ajax-notification-${type}`;

    // Add icon based on type
    const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    const icon = icons[type] || icons.info;

    notification.innerHTML = `
        <div class="ajax-notification-content">
            <i class="fas ${icon}"></i>
            <span class="ajax-notification-message">${escapeHtml(message)}</span>
            <button class="ajax-notification-close" onclick="this.parentElement.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;

    // Add to page
    document.body.appendChild(notification);

    // Trigger animation
    setTimeout(() => notification.classList.add('show'), 10);

    // Auto-remove after duration (if not permanent)
    if (duration > 0) {
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        }, duration);
    }
}

/**
 * Escape HTML to prevent XSS
 * @param {string} text The text to escape
 * @returns {string} Escaped text
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Submit form via AJAX
 * @param {HTMLFormElement} form The form to submit
 * @param {Function} onSuccess Optional success callback
 * @param {Function} onError Optional error callback
 */
async function submitFormAjax(form, onSuccess, onError) {
    // Show loading state
    showFormLoading(form);

    // Get form data
    const formData = new FormData(form);

    // Create timeout promise
    const timeoutPromise = new Promise((_, reject) => {
        setTimeout(() => reject(new Error('Request timeout')), AJAX_CONFIG.timeout);
    });

    // Create fetch promise
    const fetchPromise = fetch(AJAX_CONFIG.endpoint, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    });

    try {
        // Race between fetch and timeout
        const response = await Promise.race([fetchPromise, timeoutPromise]);

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();

        // Hide loading state
        hideFormLoading(form);

        if (data.success) {
            // Show success message
            showNotification(data.message, 'success');

            // Call success callback if provided
            if (typeof onSuccess === 'function') {
                onSuccess(data);
            }

            // Reload page after delay (to show success message)
            if (AJAX_CONFIG.reloadOnSuccess) {
                setTimeout(() => {
                    // Preserve auth code when refreshing
                    const url = new URL(window.location.href);
                    window.location.href = url.toString();
                }, AJAX_CONFIG.reloadDelay);
            }
        } else {
            // Show error message
            showNotification(data.message, 'error');

            // Call error callback if provided
            if (typeof onError === 'function') {
                onError(data);
            }
        }
    } catch (error) {
        // Hide loading state
        hideFormLoading(form);

        // Show error message
        const errorMessage = error.message === 'Request timeout'
            ? 'Request timed out. Please try again.'
            : 'An error occurred. Please try again.';

        showNotification(errorMessage, 'error');

        // Call error callback if provided
        if (typeof onError === 'function') {
            onError({ success: false, message: errorMessage });
        }

        console.error('AJAX form submission error:', error);
    }
}

/**
 * Initialize AJAX forms on page load
 */
function initializeAjaxForms() {
    // Find all forms with data-ajax="true" attribute
    const ajaxForms = document.querySelectorAll('form[data-ajax="true"]');

    ajaxForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            // Get optional callbacks from data attributes
            const onSuccessName = form.dataset.onSuccess;
            const onErrorName = form.dataset.onError;

            const onSuccess = onSuccessName && window[onSuccessName]
                ? window[onSuccessName]
                : null;

            const onError = onErrorName && window[onErrorName]
                ? window[onErrorName]
                : null;

            // Submit form via AJAX
            submitFormAjax(form, onSuccess, onError);
        });
    });
}

/**
 * Initialize on page load
 */
document.addEventListener('DOMContentLoaded', function() {
    initializeAjaxForms();
});

/**
 * Fallback: Enable AJAX for all forms that have action=""
 * This ensures backward compatibility
 */
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('form[method="POST"]:not([data-ajax])');

    forms.forEach(form => {
        // Only enable AJAX for forms that submit to current page (action="" or no action)
        const action = form.getAttribute('action');
        if (!action || action === '' || action === window.location.pathname) {
            form.setAttribute('data-ajax', 'true');
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                submitFormAjax(form);
            });
        }
    });
});
