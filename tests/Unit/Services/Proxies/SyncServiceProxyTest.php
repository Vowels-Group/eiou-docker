<?php
/**
 * Unit Tests for SyncServiceProxy
 *
 * Tests the lazy proxy pattern implementation for SyncService.
 * Verifies that:
 * - The proxy correctly delegates to SyncService
 * - Lazy loading behavior works correctly (service not resolved until first use)
 * - The instance is cached after first resolution
 */

namespace Eiou\Tests\Services\Proxies;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\Proxies\SyncServiceProxy;
use Eiou\Services\ServiceContainer;
use Eiou\Services\SyncService;
use Eiou\Contracts\SyncTriggerInterface;

#[CoversClass(SyncServiceProxy::class)]
class SyncServiceProxyTest extends TestCase
{
    private ServiceContainer $container;
    private SyncService $syncService;
    private SyncServiceProxy $proxy;

    protected function setUp(): void
    {
        // Create mock objects
        $this->container = $this->createMock(ServiceContainer::class);
        $this->syncService = $this->createMock(SyncService::class);

        // Create the proxy with mock container
        $this->proxy = new SyncServiceProxy($this->container);
    }

    // =========================================================================
    // Constructor and Interface Tests
    // =========================================================================

    /**
     * Test proxy implements SyncTriggerInterface
     */
    public function testImplementsSyncTriggerInterface(): void
    {
        $this->assertInstanceOf(SyncTriggerInterface::class, $this->proxy);
    }

    /**
     * Test proxy can be constructed with ServiceContainer
     */
    public function testCanBeConstructedWithServiceContainer(): void
    {
        $proxy = new SyncServiceProxy($this->container);

        $this->assertInstanceOf(SyncServiceProxy::class, $proxy);
    }

    // =========================================================================
    // Lazy Loading Tests
    // =========================================================================

    /**
     * Test service is not resolved on construction (lazy loading)
     */
    public function testServiceNotResolvedOnConstruction(): void
    {
        $proxy = new SyncServiceProxy($this->container);

        $this->assertFalse($proxy->isResolved());
    }

    /**
     * Test isResolved returns false before any method call
     */
    public function testIsResolvedReturnsFalseBeforeMethodCall(): void
    {
        // Container should NOT be called during construction
        $this->container->expects($this->never())
            ->method('getSyncService');

        $proxy = new SyncServiceProxy($this->container);

        $this->assertFalse($proxy->isResolved());
    }

    /**
     * Test service is resolved on first method call
     */
    public function testServiceResolvedOnFirstMethodCall(): void
    {
        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->once())
            ->method('syncSingleContact')
            ->willReturn(true);

        // Before call - not resolved
        $this->assertFalse($this->proxy->isResolved());

        // Make a method call
        $this->proxy->syncSingleContact('test-address');

        // After call - resolved
        $this->assertTrue($this->proxy->isResolved());
    }

    /**
     * Test service is only resolved once (cached)
     */
    public function testServiceOnlyResolvedOnce(): void
    {
        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->exactly(3))
            ->method('syncSingleContact')
            ->willReturn(true);

        // Make multiple calls
        $this->proxy->syncSingleContact('address1');
        $this->proxy->syncSingleContact('address2');
        $this->proxy->syncSingleContact('address3');

        // Service should only have been retrieved once
        $this->assertTrue($this->proxy->isResolved());
    }

    /**
     * Test resolve() method forces service resolution
     */
    public function testResolveMethodForcesServiceResolution(): void
    {
        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->assertFalse($this->proxy->isResolved());

        $this->proxy->resolve();

        $this->assertTrue($this->proxy->isResolved());
    }

    /**
     * Test resolve() only resolves once even if called multiple times
     */
    public function testResolveOnlyResolvesOnce(): void
    {
        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->proxy->resolve();
        $this->proxy->resolve();
        $this->proxy->resolve();

        $this->assertTrue($this->proxy->isResolved());
    }

    // =========================================================================
    // Method Delegation Tests - syncTransactionChain
    // =========================================================================

    /**
     * Test syncTransactionChain delegates to service
     */
    public function testSyncTransactionChainDelegatesToService(): void
    {
        $contactAddress = 'test-contact-address';
        $contactPublicKey = 'test-public-key';
        $expectedResult = [
            'success' => true,
            'synced_count' => 5,
            'latest_txid' => 'abc123'
        ];

        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->once())
            ->method('syncTransactionChain')
            ->with($contactAddress, $contactPublicKey, null)
            ->willReturn($expectedResult);

        $result = $this->proxy->syncTransactionChain($contactAddress, $contactPublicKey);

        $this->assertEquals($expectedResult, $result);
    }

    /**
     * Test syncTransactionChain with expectedTxid parameter
     */
    public function testSyncTransactionChainWithExpectedTxid(): void
    {
        $contactAddress = 'test-contact-address';
        $contactPublicKey = 'test-public-key';
        $expectedTxid = 'expected-txid-123';
        $expectedResult = ['success' => true, 'synced_count' => 1];

        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->once())
            ->method('syncTransactionChain')
            ->with($contactAddress, $contactPublicKey, $expectedTxid)
            ->willReturn($expectedResult);

        $result = $this->proxy->syncTransactionChain($contactAddress, $contactPublicKey, $expectedTxid);

        $this->assertEquals($expectedResult, $result);
    }

    /**
     * Test syncTransactionChain returns failure result
     */
    public function testSyncTransactionChainReturnsFailureResult(): void
    {
        $expectedResult = [
            'success' => false,
            'synced_count' => 0,
            'error' => 'Connection timeout'
        ];

        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->once())
            ->method('syncTransactionChain')
            ->willReturn($expectedResult);

        $result = $this->proxy->syncTransactionChain('address', 'pubkey');

        $this->assertFalse($result['success']);
        $this->assertEquals('Connection timeout', $result['error']);
    }

    // =========================================================================
    // Method Delegation Tests - syncContactBalance
    // =========================================================================

    /**
     * Test syncContactBalance delegates to service
     */
    public function testSyncContactBalanceDelegatesToService(): void
    {
        $contactPubkey = 'contact-public-key-123';
        $expectedResult = [
            'success' => true,
            'currencies' => ['USD' => 5000, 'EUR' => 3000]
        ];

        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->once())
            ->method('syncContactBalance')
            ->with($contactPubkey)
            ->willReturn($expectedResult);

        $result = $this->proxy->syncContactBalance($contactPubkey);

        $this->assertEquals($expectedResult, $result);
    }

    /**
     * Test syncContactBalance with empty balance
     */
    public function testSyncContactBalanceWithEmptyBalance(): void
    {
        $expectedResult = [
            'success' => true,
            'currencies' => []
        ];

        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->once())
            ->method('syncContactBalance')
            ->willReturn($expectedResult);

        $result = $this->proxy->syncContactBalance('pubkey');

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['currencies']);
    }

    // =========================================================================
    // Method Delegation Tests - syncSingleContact
    // =========================================================================

    /**
     * Test syncSingleContact delegates to service with default echo
     */
    public function testSyncSingleContactDelegatesToServiceWithDefaultEcho(): void
    {
        $contactAddress = 'contact-address-456';

        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->once())
            ->method('syncSingleContact')
            ->with($contactAddress, 'SILENT')
            ->willReturn(true);

        $result = $this->proxy->syncSingleContact($contactAddress);

        $this->assertTrue($result);
    }

    /**
     * Test syncSingleContact with ECHO mode
     */
    public function testSyncSingleContactWithEchoMode(): void
    {
        $contactAddress = 'contact-address-789';

        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->once())
            ->method('syncSingleContact')
            ->with($contactAddress, 'ECHO')
            ->willReturn(true);

        $result = $this->proxy->syncSingleContact($contactAddress, 'ECHO');

        $this->assertTrue($result);
    }

    /**
     * Test syncSingleContact returns false on failure
     */
    public function testSyncSingleContactReturnsFalseOnFailure(): void
    {
        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->once())
            ->method('syncSingleContact')
            ->willReturn(false);

        $result = $this->proxy->syncSingleContact('address');

        $this->assertFalse($result);
    }

    // =========================================================================
    // Method Delegation Tests - syncReaddedContact
    // =========================================================================

    /**
     * Test syncReaddedContact delegates to service
     */
    public function testSyncReaddedContactDelegatesToService(): void
    {
        $contactAddress = 'readded-contact-address';
        $contactPublicKey = 'readded-contact-pubkey';
        $expectedResult = [
            'success' => true,
            'transactions_synced' => 10,
            'balance_recalculated' => true
        ];

        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->once())
            ->method('syncReaddedContact')
            ->with($contactAddress, $contactPublicKey)
            ->willReturn($expectedResult);

        $result = $this->proxy->syncReaddedContact($contactAddress, $contactPublicKey);

        $this->assertEquals($expectedResult, $result);
    }

    /**
     * Test syncReaddedContact returns failure for invalid contact
     */
    public function testSyncReaddedContactReturnsFailureForInvalidContact(): void
    {
        $expectedResult = [
            'success' => false,
            'error' => 'Contact not found'
        ];

        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->once())
            ->method('syncReaddedContact')
            ->willReturn($expectedResult);

        $result = $this->proxy->syncReaddedContact('invalid', 'invalid');

        $this->assertFalse($result['success']);
        $this->assertEquals('Contact not found', $result['error']);
    }

    // =========================================================================
    // Method Delegation Tests - handleTransactionSyncRequest
    // =========================================================================

    /**
     * Test handleTransactionSyncRequest delegates to service
     */
    public function testHandleTransactionSyncRequestDelegatesToService(): void
    {
        $request = [
            'contact_address' => 'sender-address',
            'contact_pubkey' => 'sender-pubkey',
            'last_txid' => 'last-known-txid'
        ];

        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->once())
            ->method('handleTransactionSyncRequest')
            ->with($request);

        $this->proxy->handleTransactionSyncRequest($request);

        // Verify service was resolved
        $this->assertTrue($this->proxy->isResolved());
    }

    /**
     * Test handleTransactionSyncRequest with empty request
     */
    public function testHandleTransactionSyncRequestWithEmptyRequest(): void
    {
        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->once())
            ->method('handleTransactionSyncRequest')
            ->with([]);

        $this->proxy->handleTransactionSyncRequest([]);
    }

    // =========================================================================
    // Method Delegation Tests - verifyTransactionSignaturePublic
    // =========================================================================

    /**
     * Test verifyTransactionSignaturePublic delegates to service
     */
    public function testVerifyTransactionSignaturePublicDelegatesToService(): void
    {
        $transaction = [
            'txid' => 'test-txid',
            'sender_signature' => 'valid-signature',
            'signature_nonce' => 'nonce-123',
            'sender_pubkey' => 'sender-public-key'
        ];

        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->once())
            ->method('verifyTransactionSignaturePublic')
            ->with($transaction)
            ->willReturn(true);

        $result = $this->proxy->verifyTransactionSignaturePublic($transaction);

        $this->assertTrue($result);
    }

    /**
     * Test verifyTransactionSignaturePublic returns false for invalid signature
     */
    public function testVerifyTransactionSignaturePublicReturnsFalseForInvalidSignature(): void
    {
        $transaction = [
            'txid' => 'test-txid',
            'sender_signature' => 'invalid-signature',
            'signature_nonce' => 'nonce-123',
            'sender_pubkey' => 'sender-public-key'
        ];

        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->once())
            ->method('verifyTransactionSignaturePublic')
            ->with($transaction)
            ->willReturn(false);

        $result = $this->proxy->verifyTransactionSignaturePublic($transaction);

        $this->assertFalse($result);
    }

    /**
     * Test verifyTransactionSignaturePublic with missing fields
     */
    public function testVerifyTransactionSignaturePublicWithMissingFields(): void
    {
        $transaction = [
            'txid' => 'test-txid'
            // Missing sender_signature, signature_nonce
        ];

        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->once())
            ->method('verifyTransactionSignaturePublic')
            ->with($transaction)
            ->willReturn(false);

        $result = $this->proxy->verifyTransactionSignaturePublic($transaction);

        $this->assertFalse($result);
    }

    // =========================================================================
    // Proxy Pattern Behavior Tests
    // =========================================================================

    /**
     * Test calling different methods still only resolves service once
     */
    public function testDifferentMethodCallsOnlyResolveServiceOnce(): void
    {
        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->once())
            ->method('syncSingleContact')
            ->willReturn(true);

        $this->syncService->expects($this->once())
            ->method('syncContactBalance')
            ->willReturn(['success' => true]);

        $this->syncService->expects($this->once())
            ->method('verifyTransactionSignaturePublic')
            ->willReturn(true);

        // Call different methods
        $this->proxy->syncSingleContact('address');
        $this->proxy->syncContactBalance('pubkey');
        $this->proxy->verifyTransactionSignaturePublic(['txid' => 'test']);

        // Service should only be resolved once
        $this->assertTrue($this->proxy->isResolved());
    }

    /**
     * Test proxy maintains service instance across method calls
     */
    public function testProxyMaintainsServiceInstanceAcrossMethodCalls(): void
    {
        $callCount = 0;

        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return $this->syncService;
            });

        $this->syncService->method('syncSingleContact')->willReturn(true);

        // Multiple calls
        $this->proxy->syncSingleContact('addr1');
        $this->proxy->syncSingleContact('addr2');
        $this->proxy->syncSingleContact('addr3');

        // getSyncService should only be called once
        $this->assertEquals(1, $callCount);
    }

    /**
     * Test isResolved does not trigger service resolution
     */
    public function testIsResolvedDoesNotTriggerServiceResolution(): void
    {
        $this->container->expects($this->never())
            ->method('getSyncService');

        // Call isResolved multiple times
        $this->proxy->isResolved();
        $this->proxy->isResolved();
        $this->proxy->isResolved();

        $this->assertFalse($this->proxy->isResolved());
    }

    // =========================================================================
    // Edge Cases and Error Handling
    // =========================================================================

    /**
     * Test proxy handles null expectedTxid correctly
     */
    public function testProxyHandlesNullExpectedTxidCorrectly(): void
    {
        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        $this->syncService->expects($this->once())
            ->method('syncTransactionChain')
            ->with('address', 'pubkey', null)
            ->willReturn(['success' => true]);

        $result = $this->proxy->syncTransactionChain('address', 'pubkey', null);

        $this->assertTrue($result['success']);
    }

    /**
     * Test proxy correctly passes mixed contact address type
     */
    public function testProxyCorrectlyPassesMixedContactAddressType(): void
    {
        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturn($this->syncService);

        // Contact address can be mixed type according to interface
        $this->syncService->expects($this->once())
            ->method('syncSingleContact')
            ->with(['http' => 'http://test.com'], 'SILENT')
            ->willReturn(true);

        $result = $this->proxy->syncSingleContact(['http' => 'http://test.com']);

        $this->assertTrue($result);
    }

    /**
     * Test service is resolved before delegation
     */
    public function testServiceIsResolvedBeforeDelegation(): void
    {
        $resolved = false;

        $this->container->expects($this->once())
            ->method('getSyncService')
            ->willReturnCallback(function () use (&$resolved) {
                $resolved = true;
                return $this->syncService;
            });

        $this->syncService->expects($this->once())
            ->method('syncSingleContact')
            ->willReturnCallback(function () use (&$resolved) {
                // At this point, service should already be resolved
                $this->assertTrue($resolved);
                return true;
            });

        $this->proxy->syncSingleContact('address');
    }
}
