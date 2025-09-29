   // Copyright 2025
    
    // Simple script to show/hide the floating action button
    // This is minimal JavaScript that should work in Tor Browser
    window.addEventListener('scroll', function() {
        const fab = document.getElementById('backToTop');
        if (window.pageYOffset > 300) {
            fab.classList.remove('hidden');
        } else {
            fab.classList.add('hidden');
        }
    });

    // Hide FAB initially
    document.addEventListener('DOMContentLoaded', function() {
        const fab = document.getElementById('backToTop');
        fab.classList.add('hidden');
        
        // Initialize polling for updates (Tor Browser compatible)
        initializePolling();
    });

    // Simple polling mechanism that works with Tor Browser
    function initializePolling() {
        // Only poll if user is actively using the page
        let isPageActive = true;
        let pollInterval = null;
        let isUserInteracting = false;
        let interactionTimeout = null;
        
        // Start polling when page becomes visible
        function startPolling() {
            if (pollInterval) return; // Already polling
            
            pollInterval = setInterval(function() {
                if (isPageActive && !isUserInteracting) {
                    checkForUpdates();
                }
            }, 10000); // Poll every 10 seconds
        }
        
        // Stop polling when page is hidden
        function stopPolling() {
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
        }
        
        // Pause polling when user is interacting with forms
        function pausePollingForInteraction() {
            isUserInteracting = true;
            updatePollingStatus();
            
            // Clear existing timeout
            if (interactionTimeout) {
                clearTimeout(interactionTimeout);
            }
            
            // Resume polling after 30 seconds of no interaction
            interactionTimeout = setTimeout(function() {
                isUserInteracting = false;
                updatePollingStatus();
            }, 30000);
        }
        
        // Update the polling status indicator
        function updatePollingStatus() {
            const statusElement = document.getElementById('polling-status');
            if (statusElement) {
                if (isUserInteracting) {
                    statusElement.innerHTML = '<i class="fas fa-pause"></i> Auto-refresh paused (form interaction detected)';
                    statusElement.style.color = '#ffc107';
                } else {
                    statusElement.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Auto-refresh active';
                    statusElement.style.color = '#28a745';
                }
            }
        }
        
        // Check for updates using a simple fetch
        function checkForUpdates() {
            const currentUrl = window.location.href;
            
            // Create a simple request to check for updates
            fetch(currentUrl + '&check_updates=1', {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.text())
            .then(data => {
                // Parse the response for updates
                if (data.includes('new_transaction') || data.includes('new_contact_request')) {
                    // Show notification and refresh page
                    showUpdateNotification();
                    setTimeout(() => {
                        // Preserve auth code when refreshing
                        const url = new URL(window.location.href);
                        window.location.href = url.toString();
                    }, 2000);
                }
            })
            .catch(error => {
                // Silently fail - don't show errors to user
                console.log('Update check failed (normal in Tor)');
            });
        }
        
        // Show a simple notification
        function showUpdateNotification() {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: #0d6efd;
                color: white;
                padding: 1rem;
                border-radius: 4px;
                z-index: 10000;
                box-shadow: 0 2px 10px rgba(0,0,0,0.3);
                animation: slideIn 0.3s ease-out;
            `;
            notification.innerHTML = '<i class="fas fa-sync-alt"></i> New updates available! Refreshing...';
            document.body.appendChild(notification);
            
            // Remove after 3 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 3000);
        }
        
        // Page visibility API (works in Tor Browser)
        if (typeof document.hidden !== "undefined") {
            document.addEventListener("visibilitychange", function() {
                if (document.hidden) {
                    isPageActive = false;
                    stopPolling();
                } else {
                    isPageActive = true;
                    startPolling();
                }
            });
        }
        
                            // Add event listeners for form interactions
        function addFormInteractionListeners() {
            // Listen for form input events
            const formInputs = document.querySelectorAll('input, select, textarea');
            formInputs.forEach(input => {
                input.addEventListener('focus', pausePollingForInteraction);
                input.addEventListener('input', pausePollingForInteraction);
                input.addEventListener('change', pausePollingForInteraction);
            });
            
            // Listen for form submission
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function() {
                    isUserInteracting = true; // Keep paused during submission
                });
            });
            
            // Listen for mouse clicks on form elements
            document.addEventListener('click', function(e) {
                if (e.target.matches('input, select, textarea, button, .btn')) {
                    pausePollingForInteraction();
                }
            });
        }
        
        // Start polling initially
        startPolling();
        
        // Add form interaction listeners
        addFormInteractionListeners();
    }

// Manual refresh function (Tor Browser compatible)
function refreshWalletData() {
    const refreshBtn = document.getElementById('manualRefresh');
    const icon = refreshBtn.querySelector('i');
    
    // Show loading state
    icon.className = 'fas fa-spinner fa-spin';
    refreshBtn.disabled = true;
    
    // Preserve auth code when refreshing
    const url = new URL(window.location.href);
    window.location.href = url.toString();
}

// Send eIOU form handling
function initializeSendForm() {
    const recipientSelect = document.getElementById('recipient');
    const manualAddressGroup = document.getElementById('manual-address-group');
    const manualAddressInput = document.getElementById('manual-address');
    const transactionTypeIndicator = document.getElementById('transaction-type-indicator');
    const transactionTypeText = document.getElementById('transaction-type-text');
    
    // Set initial state - manual address is visible by default
    manualAddressInput.required = true;
    recipientSelect.required = false;
    transactionTypeIndicator.style.display = 'block';
    transactionTypeText.textContent = 'P2P Transaction (routed through contacts)';
    transactionTypeText.style.color = '#ffc107';
    
    if (recipientSelect) {
        recipientSelect.addEventListener('change', function() {
            const selectedValue = this.value;
            
            if (selectedValue === '') {
                // Show manual address input (default state)
                manualAddressGroup.style.display = 'block';
                manualAddressInput.required = true;
                recipientSelect.required = false;
                transactionTypeIndicator.style.display = 'block';
                transactionTypeText.textContent = 'P2P Transaction (routed through contacts)';
                transactionTypeText.style.color = '#ffc107';
            } else {
                // Hide manual address input when contact is selected
                manualAddressGroup.style.display = 'none';
                manualAddressInput.required = false;
                recipientSelect.required = true;
                transactionTypeIndicator.style.display = 'block';
                transactionTypeText.textContent = 'Direct Transaction (to contact)';
                transactionTypeText.style.color = '#28a745';
            }
        });
    }
    
    // Handle manual address input
    if (manualAddressInput) {
        manualAddressInput.addEventListener('input', function() {
            const address = this.value.trim();
            if (address) {
                transactionTypeIndicator.style.display = 'block';
                if (address.includes('.onion') || address.startsWith('http')) {
                    transactionTypeText.textContent = 'P2P Transaction (routed through contacts)';
                    transactionTypeText.style.color = '#ffc107';
                } else {
                    transactionTypeText.textContent = 'P2P Transaction (address format detected)';
                    transactionTypeText.style.color = '#ffc107';
                }
            } else {
                transactionTypeIndicator.style.display = 'none';
            }
        });
    }
}

// Initialize send form when page loads
document.addEventListener('DOMContentLoaded', function() {
    initializeSendForm();
});

// Edit contact modal functions
function openEditContactModal(address, name, fee, credit, currency) {
    // Populate the form fields
    document.getElementById('edit_contact_address').value = address;
    document.getElementById('edit_contact_name').value = name;
    document.getElementById('edit_contact_fee').value = fee;
    document.getElementById('edit_contact_credit').value = credit;
    document.getElementById('edit_contact_currency').value = currency;
    
    // Show the modal
    document.getElementById('editContactModal').style.display = 'flex';
    
    // Pause polling during form interaction
    if (typeof pausePollingForInteraction === 'function') {
        pausePollingForInteraction();
    }
}

function closeEditContactModal() {
    document.getElementById('editContactModal').style.display = 'none';
    
    // Resume polling after modal is closed
    if (typeof resumePollingAfterInteraction === 'function') {
        setTimeout(resumePollingAfterInteraction, 1000);
    }
}

// Close modal when clicking outside of it
window.onclick = function(event) {
    const modal = document.getElementById('editContactModal');
    if (event.target === modal) {
        closeEditContactModal();
    }
}

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeEditContactModal();
    }
});

$(function () {
  var includeHtml = $('[include-html]')
  $.each(includeHtml, function () {
    var file = 'src/wallet/html-parts/' + $(this).data('include') + '.html'
    $(this).load(file)
  })
});