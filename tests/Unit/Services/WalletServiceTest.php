<?php
/**
 * Unit Tests for WalletService
 *
 * Tests wallet service functionality including wallet existence checks.
 *
 * Note: The WalletService::checkWalletExists() method checks if hasKeys() returns null,
 * but UserContext::hasKeys() is typed to return bool. This means the current implementation
 * will never throw the FatalServiceException since null === false evaluates to false.
 * These tests document the current behavior of the code as written.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\WalletService;
use Eiou\Core\UserContext;
use Eiou\Core\ErrorCodes;
use Eiou\Exceptions\FatalServiceException;

#[CoversClass(WalletService::class)]
class WalletServiceTest extends TestCase
{
    private UserContext $userContext;
    private WalletService $walletService;

    protected function setUp(): void
    {
        // Create mock object for UserContext dependency
        $this->userContext = $this->createMock(UserContext::class);
        $this->walletService = new WalletService($this->userContext);
    }

    // =========================================================================
    // checkWalletExists() Tests - Wallet exists scenarios
    // =========================================================================

    /**
     * Test checkWalletExists does not throw when wallet has keys (hasKeys returns true)
     */
    public function testCheckWalletExistsDoesNotThrowWhenWalletHasKeys(): void
    {
        $this->userContext->expects($this->once())
            ->method('hasKeys')
            ->willReturn(true);

        // Should not throw any exception
        $this->walletService->checkWalletExists('send');

        // If we get here without exception, test passes
        $this->assertTrue(true);
    }

    /**
     * Test checkWalletExists does not throw for various requests when wallet exists
     */
    public function testCheckWalletExistsPassesForAllRequestsWhenWalletExists(): void
    {
        $requests = ['send', 'receive', 'balance', 'history', 'contacts', 'settings'];

        foreach ($requests as $request) {
            $userContext = $this->createMock(UserContext::class);
            $userContext->expects($this->once())
                ->method('hasKeys')
                ->willReturn(true);

            $walletService = new WalletService($userContext);

            // Should not throw
            $walletService->checkWalletExists($request);
        }

        $this->assertTrue(true);
    }

    /**
     * Test checkWalletExists does not throw when hasKeys returns false
     *
     * Note: The WalletService checks for null === hasKeys(), but since hasKeys()
     * returns bool (not bool|null), false !== null, so no exception is thrown.
     * This documents the current behavior of the code.
     */
    public function testCheckWalletExistsDoesNotThrowWhenHasKeysReturnsFalse(): void
    {
        $this->userContext->expects($this->once())
            ->method('hasKeys')
            ->willReturn(false);

        // hasKeys() returning false is NOT null, so no exception thrown
        // The condition (null === false) is false
        $this->walletService->checkWalletExists('send');

        $this->assertTrue(true);
    }

    // =========================================================================
    // checkWalletExists() Tests - Generate and Restore requests
    // =========================================================================

    /**
     * Test checkWalletExists does not throw for 'generate' request when wallet has keys
     */
    public function testCheckWalletExistsDoesNotThrowForGenerateRequestWhenWalletExists(): void
    {
        $this->userContext->expects($this->once())
            ->method('hasKeys')
            ->willReturn(true);

        $this->walletService->checkWalletExists('generate');

        $this->assertTrue(true);
    }

    /**
     * Test checkWalletExists does not throw for 'restore' request when wallet has keys
     */
    public function testCheckWalletExistsDoesNotThrowForRestoreRequestWhenWalletExists(): void
    {
        $this->userContext->expects($this->once())
            ->method('hasKeys')
            ->willReturn(true);

        $this->walletService->checkWalletExists('restore');

        $this->assertTrue(true);
    }

    /**
     * Test checkWalletExists for 'generate' request when hasKeys returns false
     *
     * Since hasKeys() returns bool, not null, the condition is never met.
     */
    public function testCheckWalletExistsForGenerateRequestWhenHasKeysFalse(): void
    {
        $this->userContext->expects($this->once())
            ->method('hasKeys')
            ->willReturn(false);

        // No exception because false !== null
        $this->walletService->checkWalletExists('generate');

        $this->assertTrue(true);
    }

    /**
     * Test checkWalletExists for 'restore' request when hasKeys returns false
     *
     * Since hasKeys() returns bool, not null, the condition is never met.
     */
    public function testCheckWalletExistsForRestoreRequestWhenHasKeysFalse(): void
    {
        $this->userContext->expects($this->once())
            ->method('hasKeys')
            ->willReturn(false);

        // No exception because false !== null
        $this->walletService->checkWalletExists('restore');

        $this->assertTrue(true);
    }

    // =========================================================================
    // checkWalletExists() Tests - Various request types
    // =========================================================================

    /**
     * Test checkWalletExists with 'balance' request when wallet exists
     */
    public function testCheckWalletExistsWithBalanceRequest(): void
    {
        $this->userContext->expects($this->once())
            ->method('hasKeys')
            ->willReturn(true);

        $this->walletService->checkWalletExists('balance');

        $this->assertTrue(true);
    }

    /**
     * Test checkWalletExists with 'history' request when wallet exists
     */
    public function testCheckWalletExistsWithHistoryRequest(): void
    {
        $this->userContext->expects($this->once())
            ->method('hasKeys')
            ->willReturn(true);

        $this->walletService->checkWalletExists('history');

        $this->assertTrue(true);
    }

    /**
     * Test checkWalletExists with 'contacts' request when wallet exists
     */
    public function testCheckWalletExistsWithContactsRequest(): void
    {
        $this->userContext->expects($this->once())
            ->method('hasKeys')
            ->willReturn(true);

        $this->walletService->checkWalletExists('contacts');

        $this->assertTrue(true);
    }

    /**
     * Test checkWalletExists with 'settings' request when wallet exists
     */
    public function testCheckWalletExistsWithSettingsRequest(): void
    {
        $this->userContext->expects($this->once())
            ->method('hasKeys')
            ->willReturn(true);

        $this->walletService->checkWalletExists('settings');

        $this->assertTrue(true);
    }

    /**
     * Test checkWalletExists with 'sync' request when wallet exists
     */
    public function testCheckWalletExistsWithSyncRequest(): void
    {
        $this->userContext->expects($this->once())
            ->method('hasKeys')
            ->willReturn(true);

        $this->walletService->checkWalletExists('sync');

        $this->assertTrue(true);
    }

    // =========================================================================
    // checkWalletExists() Tests - Edge cases
    // =========================================================================

    /**
     * Test checkWalletExists with empty string request when wallet exists
     */
    public function testCheckWalletExistsWithEmptyRequestWhenWalletExists(): void
    {
        $this->userContext->expects($this->once())
            ->method('hasKeys')
            ->willReturn(true);

        $this->walletService->checkWalletExists('');

        $this->assertTrue(true);
    }

    /**
     * Test checkWalletExists with uppercase 'GENERATE' when wallet exists
     */
    public function testCheckWalletExistsWithUppercaseGenerateWhenWalletExists(): void
    {
        $this->userContext->expects($this->once())
            ->method('hasKeys')
            ->willReturn(true);

        // Case sensitivity doesn't matter when wallet exists
        $this->walletService->checkWalletExists('GENERATE');

        $this->assertTrue(true);
    }

    /**
     * Test checkWalletExists with uppercase 'RESTORE' when wallet exists
     */
    public function testCheckWalletExistsWithUppercaseRestoreWhenWalletExists(): void
    {
        $this->userContext->expects($this->once())
            ->method('hasKeys')
            ->willReturn(true);

        // Case sensitivity doesn't matter when wallet exists
        $this->walletService->checkWalletExists('RESTORE');

        $this->assertTrue(true);
    }

    /**
     * Test checkWalletExists with mixed case request when wallet exists
     */
    public function testCheckWalletExistsWithMixedCaseRequestWhenWalletExists(): void
    {
        $this->userContext->expects($this->once())
            ->method('hasKeys')
            ->willReturn(true);

        $this->walletService->checkWalletExists('SeNd');

        $this->assertTrue(true);
    }

    /**
     * Test checkWalletExists with whitespace in request when wallet exists
     */
    public function testCheckWalletExistsWithWhitespaceRequestWhenWalletExists(): void
    {
        $this->userContext->expects($this->once())
            ->method('hasKeys')
            ->willReturn(true);

        $this->walletService->checkWalletExists('  send  ');

        $this->assertTrue(true);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test WalletService can be instantiated with UserContext
     */
    public function testWalletServiceCanBeInstantiated(): void
    {
        $userContext = $this->createMock(UserContext::class);
        $walletService = new WalletService($userContext);

        $this->assertInstanceOf(WalletService::class, $walletService);
    }

    /**
     * Test WalletService implements WalletServiceInterface
     */
    public function testWalletServiceImplementsInterface(): void
    {
        $userContext = $this->createMock(UserContext::class);
        $walletService = new WalletService($userContext);

        $this->assertInstanceOf(\Eiou\Contracts\WalletServiceInterface::class, $walletService);
    }

    // =========================================================================
    // Documentation Tests - Behavior Analysis
    // =========================================================================

    /**
     * Test that documents the expected behavior when hasKeys returns false
     *
     * This test documents that due to the type mismatch between:
     * - WalletService checking: null === hasKeys()
     * - UserContext::hasKeys() returning: bool (not bool|null)
     *
     * The exception will never be thrown because false !== null and true !== null.
     */
    public function testDocumentsHasKeysTypeMismatchBehavior(): void
    {
        $requests = ['send', 'receive', 'balance', 'history'];

        foreach ($requests as $request) {
            $userContext = $this->createMock(UserContext::class);
            $userContext->expects($this->once())
                ->method('hasKeys')
                ->willReturn(false);

            $walletService = new WalletService($userContext);

            // No exception thrown despite hasKeys() returning false
            // because the code checks for null, not false
            $walletService->checkWalletExists($request);
        }

        $this->assertTrue(true);
    }

    /**
     * Test that hasKeys is called exactly once per checkWalletExists call
     */
    public function testHasKeysIsCalledExactlyOnce(): void
    {
        $this->userContext->expects($this->once())
            ->method('hasKeys')
            ->willReturn(true);

        $this->walletService->checkWalletExists('send');

        // Assertion is in the mock expectation
    }

    /**
     * Test multiple checkWalletExists calls use the same UserContext
     */
    public function testMultipleCallsUseSameUserContext(): void
    {
        $this->userContext->expects($this->exactly(3))
            ->method('hasKeys')
            ->willReturn(true);

        $this->walletService->checkWalletExists('send');
        $this->walletService->checkWalletExists('balance');
        $this->walletService->checkWalletExists('history');

        // Assertion is in the mock expectation
    }
}
