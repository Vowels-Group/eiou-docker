// Copyright 2025
// Toast Notification System

/**
 * Toast notification manager
 */
const Toast = {
    /**
     * Default options
     */
    defaults: {
        duration: 5000, // 5 seconds
        dismissible: true,
        progressBar: true
    },

    /**
     * Container for toasts
     */
    container: null,

    /**
     * Initialize toast container
     */
    init: function() {
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.className = 'toast-container';
            this.container.setAttribute('role', 'region');
            this.container.setAttribute('aria-label', 'Notifications');
            document.body.appendChild(this.container);
        }
    },

    /**
     * Show a toast notification
     */
    show: function(message, type = 'info', options = {}) {
        this.init();

        // Merge options with defaults
        const opts = { ...this.defaults, ...options };

        // Create toast element
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.setAttribute('aria-atomic', 'true');

        // Get icon based on type
        const icon = this.getIcon(type);

        // Build toast HTML
        toast.innerHTML = `
            <div class="toast-content">
                <div class="toast-icon">${icon}</div>
                <div class="toast-message">${message}</div>
            </div>
            ${opts.dismissible ? '<button class="toast-close" aria-label="Close notification">&times;</button>' : ''}
            ${opts.progressBar && opts.duration > 0 ? '<div class="toast-progress"></div>' : ''}
        `;

        // Set progress bar animation duration
        if (opts.progressBar && opts.duration > 0) {
            const progressBar = toast.querySelector('.toast-progress');
            if (progressBar) {
                progressBar.style.animationDuration = `${opts.duration}ms`;
            }
        }

        // Add close button event
        if (opts.dismissible) {
            const closeBtn = toast.querySelector('.toast-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.hide(toast);
                });
            }
        }

        // Click to dismiss
        toast.addEventListener('click', () => {
            this.hide(toast);
        });

        // Add to container
        this.container.appendChild(toast);

        // Auto-dismiss after duration
        if (opts.duration > 0) {
            setTimeout(() => {
                this.hide(toast);
            }, opts.duration);
        }

        return toast;
    },

    /**
     * Hide a toast
     */
    hide: function(toast) {
        if (!toast) return;

        toast.classList.add('toast-hiding');

        // Remove after animation
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    },

    /**
     * Get icon for toast type
     */
    getIcon: function(type) {
        const icons = {
            success: '<i class="fas fa-check-circle"></i>',
            error: '<i class="fas fa-exclamation-circle"></i>',
            warning: '<i class="fas fa-exclamation-triangle"></i>',
            info: '<i class="fas fa-info-circle"></i>'
        };

        return icons[type] || icons.info;
    },

    /**
     * Shorthand methods
     */
    success: function(message, options = {}) {
        return this.show(message, 'success', options);
    },

    error: function(message, options = {}) {
        return this.show(message, 'error', options);
    },

    warning: function(message, options = {}) {
        return this.show(message, 'warning', options);
    },

    info: function(message, options = {}) {
        return this.show(message, 'info', options);
    },

    /**
     * Clear all toasts
     */
    clearAll: function() {
        if (this.container) {
            while (this.container.firstChild) {
                this.container.removeChild(this.container.firstChild);
            }
        }
    }
};

/**
 * Handle API response and show appropriate toast
 */
function handleApiResponse(response, successMessage = null) {
    if (response.success) {
        Toast.success(successMessage || response.message);
    } else {
        Toast.error(response.message || 'An error occurred');
    }
    return response;
}

/**
 * Initialize toasts on page load
 */
document.addEventListener('DOMContentLoaded', function() {
    Toast.init();
});
