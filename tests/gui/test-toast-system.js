/**
 * Toast System Integration Tests
 *
 * These tests verify the toast notification system works correctly
 * Run in browser console or with Node.js test framework
 */

// Test Suite
const ToastSystemTests = {
    results: [],

    /**
     * Run all tests
     */
    async runAll() {
        console.log('Starting Toast System Tests...\n');

        await this.testBasicToasts();
        await this.testToastStacking();
        await this.testCustomTimeouts();
        await this.testErrorHandler();
        await this.testRetryLogic();
        await this.testFormHandler();

        this.printResults();
    },

    /**
     * Test basic toast notifications
     */
    async testBasicToasts() {
        console.log('Test: Basic Toast Notifications');

        try {
            // Test all toast types
            Toast.success('Success test');
            Toast.error('Error test');
            Toast.warning('Warning test');
            Toast.info('Info test');

            // Verify toasts were created
            const toasts = document.querySelectorAll('.toast');
            if (toasts.length === 4) {
                this.pass('Basic toasts created successfully');
            } else {
                this.fail('Expected 4 toasts, found ' + toasts.length);
            }

            // Clear for next test
            Toast.clearAll();
            await this.sleep(500);

        } catch (error) {
            this.fail('Basic toast test failed: ' + error.message);
        }
    },

    /**
     * Test toast stacking (max 5)
     */
    async testToastStacking() {
        console.log('Test: Toast Stacking');

        try {
            // Create 10 toasts
            for (let i = 0; i < 10; i++) {
                Toast.info('Message ' + (i + 1));
            }

            await this.sleep(100);

            // Should only have 5 toasts (max)
            const toasts = document.querySelectorAll('.toast');
            if (toasts.length <= 5) {
                this.pass('Toast stacking works (max 5 toasts enforced)');
            } else {
                this.fail('Too many toasts visible: ' + toasts.length);
            }

            Toast.clearAll();
            await this.sleep(500);

        } catch (error) {
            this.fail('Toast stacking test failed: ' + error.message);
        }
    },

    /**
     * Test custom timeouts
     */
    async testCustomTimeouts() {
        console.log('Test: Custom Timeouts');

        try {
            // Create toast with 1 second timeout
            Toast.info('Quick message', 1000);
            await this.sleep(100);

            let toasts = document.querySelectorAll('.toast');
            if (toasts.length === 1) {
                this.pass('Custom timeout toast created');
            } else {
                this.fail('Toast not created');
            }

            // Wait for auto-dismiss
            await this.sleep(1500);

            toasts = document.querySelectorAll('.toast');
            if (toasts.length === 0) {
                this.pass('Toast auto-dismissed after timeout');
            } else {
                this.fail('Toast did not auto-dismiss');
            }

        } catch (error) {
            this.fail('Custom timeout test failed: ' + error.message);
        }
    },

    /**
     * Test error handler
     */
    async testErrorHandler() {
        console.log('Test: Error Handler');

        try {
            // Test user-friendly messages
            const messages = {
                400: 'Invalid request',
                401: 'Authentication required',
                404: 'Resource not found',
                500: 'Server error'
            };

            let passCount = 0;
            for (const [status, expectedText] of Object.entries(messages)) {
                const message = ErrorHandler.getUserFriendlyMessage(parseInt(status));
                if (message.includes(expectedText)) {
                    passCount++;
                }
            }

            if (passCount === Object.keys(messages).length) {
                this.pass('Error handler returns user-friendly messages');
            } else {
                this.fail('Some error messages incorrect (' + passCount + '/' + Object.keys(messages).length + ')');
            }

            // Test transient error detection
            const mockResponse503 = new Response('Service unavailable', { status: 503 });
            const mockResponse400 = new Response('Bad request', { status: 400 });

            if (ErrorHandler.isTransientError(mockResponse503) &&
                !ErrorHandler.isTransientError(mockResponse400)) {
                this.pass('Transient error detection works correctly');
            } else {
                this.fail('Transient error detection failed');
            }

        } catch (error) {
            this.fail('Error handler test failed: ' + error.message);
        }
    },

    /**
     * Test retry logic
     */
    async testRetryLogic() {
        console.log('Test: Retry Logic');

        try {
            let attemptCount = 0;

            // Test successful retry (succeeds on 2nd attempt)
            const result = await RetryHandler.retryFetch(
                async () => {
                    attemptCount++;
                    if (attemptCount === 2) {
                        return new Response(JSON.stringify({success: true}), {
                            status: 200,
                            headers: {'Content-Type': 'application/json'}
                        });
                    } else {
                        return new Response('Service unavailable', { status: 503 });
                    }
                },
                {
                    maxAttempts: 3,
                    initialDelay: 100
                }
            );

            if (result.ok && attemptCount === 2) {
                this.pass('Retry logic succeeded on 2nd attempt');
            } else {
                this.fail('Retry logic did not work as expected');
            }

            // Test non-retryable error (should fail immediately)
            attemptCount = 0;
            try {
                await RetryHandler.retryFetch(
                    async () => {
                        attemptCount++;
                        return new Response('Bad request', { status: 400 });
                    },
                    {
                        maxAttempts: 3,
                        initialDelay: 100
                    }
                );
                this.fail('Non-retryable error should have thrown');
            } catch (error) {
                if (attemptCount === 1) {
                    this.pass('Non-retryable error failed immediately (no retry)');
                } else {
                    this.fail('Non-retryable error retried ' + attemptCount + ' times');
                }
            }

            Toast.clearAll();

        } catch (error) {
            this.fail('Retry logic test failed: ' + error.message);
        }
    },

    /**
     * Test form handler (if available)
     */
    async testFormHandler() {
        console.log('Test: Form Handler');

        // Check if FormHandler is available
        if (typeof FormHandler === 'undefined') {
            this.skip('FormHandler not loaded');
            return;
        }

        try {
            // Create mock form
            const form = document.createElement('form');
            form.innerHTML = `
                <input type="text" name="test" value="test">
                <button type="submit">Submit</button>
            `;
            document.body.appendChild(form);

            // Mock successful submission
            const originalFetch = window.fetch;
            window.fetch = async () => {
                return new Response(JSON.stringify({success: true}), {
                    status: 200,
                    headers: {'Content-Type': 'application/json'}
                });
            };

            await FormHandler.submitForm(form, {
                url: '/api/test',
                successMessage: 'Test successful',
                resetForm: false
            });

            // Restore fetch
            window.fetch = originalFetch;

            // Check if success toast was shown
            const toasts = document.querySelectorAll('.toast-success');
            if (toasts.length > 0) {
                this.pass('Form handler shows success toast');
            } else {
                this.fail('Form handler did not show success toast');
            }

            // Cleanup
            document.body.removeChild(form);
            Toast.clearAll();

        } catch (error) {
            this.fail('Form handler test failed: ' + error.message);
        }
    },

    /**
     * Sleep helper
     */
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    },

    /**
     * Record pass
     */
    pass(message) {
        this.results.push({ status: 'PASS', message });
        console.log('✅ PASS:', message);
    },

    /**
     * Record fail
     */
    fail(message) {
        this.results.push({ status: 'FAIL', message });
        console.log('❌ FAIL:', message);
    },

    /**
     * Record skip
     */
    skip(message) {
        this.results.push({ status: 'SKIP', message });
        console.log('⊘ SKIP:', message);
    },

    /**
     * Print test results summary
     */
    printResults() {
        console.log('\n' + '='.repeat(60));
        console.log('Toast System Test Results');
        console.log('='.repeat(60));

        const passed = this.results.filter(r => r.status === 'PASS').length;
        const failed = this.results.filter(r => r.status === 'FAIL').length;
        const skipped = this.results.filter(r => r.status === 'SKIP').length;

        console.log('Total Tests:', this.results.length);
        console.log('Passed:', passed);
        console.log('Failed:', failed);
        console.log('Skipped:', skipped);

        if (failed === 0) {
            console.log('\n✅ All tests passed!');
        } else {
            console.log('\n❌ Some tests failed. See details above.');
        }

        console.log('='.repeat(60) + '\n');
    }
};

// Auto-run if in browser environment
if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    // Wait for DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            // Run tests after a short delay to ensure all scripts loaded
            setTimeout(() => {
                ToastSystemTests.runAll();
            }, 500);
        });
    } else {
        // DOM already ready, run tests
        setTimeout(() => {
            ToastSystemTests.runAll();
        }, 500);
    }
}

// Export for Node.js testing frameworks
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ToastSystemTests;
}
