<?php
/**
 * Unit Tests for TransactionValidationService
 *
 * Tests transaction validation logic including:
 * - Previous transaction ID validation for chain integrity
 * - Available funds checking for transaction authorization
 * - Full transaction possibility validation with proactive sync
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\TransactionValidationService;
use Eiou\Database\TransactionRepository;
use Eiou\Database\ContactRepository;
use Eiou\Database\TransactionChainRepository;
use Eiou\Services\Utilities\ValidationUtilityService;
use Eiou\Utils\InputValidator;
use Eiou\Utils\SecureLogger;
use Eiou\Schemas\Payloads\TransactionPayload;
use Eiou\Core\UserContext;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Contracts\TransactionServiceInterface;
use PDOException;
use RuntimeException;
use ReflectionClass;

#[CoversClass(TransactionValidationService::class)]
class TransactionValidationServiceTest extends TestCase
{
    private TransactionRepository $transactionRepository;
    private ContactRepository $contactRepository;
    private ValidationUtilityService $validationUtility;
    private InputValidator $inputValidator;
    private TransactionPayload $transactionPayload;
    private UserContext $userContext;
    private SecureLogger $secureLogger;
    private SyncTriggerInterface $syncTrigger;
    private TransactionServiceInterface $transactionService;
    private TransactionChainRepository $transactionChainRepository;
    private TransactionValidationService $validationService;

    protected function setUp(): void
    {
        // Create mock objects for all constructor dependencies
        $this->transactionRepository = $this->createMock(TransactionRepository::class);
        $this->contactRepository = $this->createMock(ContactRepository::class);
        $this->validationUtility = $this->createMock(ValidationUtilityService::class);
        $this->inputValidator = $this->createMock(InputValidator::class);
        $this->transactionPayload = $this->createMock(TransactionPayload::class);
        $this->userContext = $this->createMock(UserContext::class);
        // SecureLogger uses static methods, so we create a real instance
        // The static methods will be called but won't have side effects in tests
        $this->secureLogger = new SecureLogger();

        // Create mocks for setter-injected dependencies
        $this->syncTrigger = $this->createMock(SyncTriggerInterface::class);
        $this->transactionService = $this->createMock(TransactionServiceInterface::class);
        $this->transactionChainRepository = $this->createMock(TransactionChainRepository::class);

        // Create the service without calling the constructor to avoid database initialization
        $reflection = new ReflectionClass(TransactionValidationService::class);
        $this->validationService = $reflection->newInstanceWithoutConstructor();

        // Inject all dependencies via reflection
        $this->setPrivateProperty('transactionRepository', $this->transactionRepository);
        $this->setPrivateProperty('contactRepository', $this->contactRepository);
        $this->setPrivateProperty('validationUtility', $this->validationUtility);
        $this->setPrivateProperty('inputValidator', $this->inputValidator);
        $this->setPrivateProperty('transactionPayload', $this->transactionPayload);
        $this->setPrivateProperty('currentUser', $this->userContext);
        $this->setPrivateProperty('secureLogger', $this->secureLogger);
        $this->setPrivateProperty('transactionChainRepository', $this->transactionChainRepository);

        // Inject optional dependencies via setters
        $this->validationService->setSyncTrigger($this->syncTrigger);
        $this->validationService->setTransactionService($this->transactionService);
    }

    /**
     * Helper method to set private properties via reflection
     */
    private function setPrivateProperty(string $propertyName, mixed $value): void
    {
        $reflection = new ReflectionClass($this->validationService);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($this->validationService, $value);
    }

    // =========================================================================
    // checkPreviousTxid() Tests
    // =========================================================================

    /**
     * Test checkPreviousTxid returns false when senderPublicKey is missing
     */
    public function testCheckPreviousTxidReturnsFalseWhenSenderPublicKeyMissing(): void
    {
        $request = [
            'receiverPublicKey' => 'receiver-pubkey'
        ];

        $result = $this->validationService->checkPreviousTxid($request);

        $this->assertFalse($result);
    }

    /**
     * Test checkPreviousTxid returns false when receiverPublicKey is missing
     */
    public function testCheckPreviousTxidReturnsFalseWhenReceiverPublicKeyMissing(): void
    {
        $request = [
            'senderPublicKey' => 'sender-pubkey'
        ];

        $result = $this->validationService->checkPreviousTxid($request);

        $this->assertFalse($result);
    }

    /**
     * Test checkPreviousTxid returns true when both expected and received are null (first transaction)
     */
    public function testCheckPreviousTxidReturnsTrueForFirstTransaction(): void
    {
        $request = [
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey',
            'previousTxid' => null
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getPreviousTxid')
            ->with('sender-pubkey', 'receiver-pubkey')
            ->willReturn(null);

        $result = $this->validationService->checkPreviousTxid($request);

        $this->assertTrue($result);
    }

    /**
     * Test checkPreviousTxid returns true when expected and received match
     */
    public function testCheckPreviousTxidReturnsTrueWhenTxidsMatch(): void
    {
        $request = [
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey',
            'previousTxid' => 'txid-abc123'
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getPreviousTxid')
            ->with('sender-pubkey', 'receiver-pubkey')
            ->willReturn('txid-abc123');

        $result = $this->validationService->checkPreviousTxid($request);

        $this->assertTrue($result);
    }

    /**
     * Test checkPreviousTxid returns false when expected is null but received is not
     */
    public function testCheckPreviousTxidReturnsFalseWhenExpectedNullReceivedNotNull(): void
    {
        $request = [
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey',
            'previousTxid' => 'txid-abc123'
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getPreviousTxid')
            ->with('sender-pubkey', 'receiver-pubkey')
            ->willReturn(null);

        $result = $this->validationService->checkPreviousTxid($request);

        $this->assertFalse($result);
    }

    /**
     * Test checkPreviousTxid returns false when txids mismatch
     */
    public function testCheckPreviousTxidReturnsFalseWhenTxidsMismatch(): void
    {
        $request = [
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey',
            'previousTxid' => 'txid-abc123'
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getPreviousTxid')
            ->with('sender-pubkey', 'receiver-pubkey')
            ->willReturn('txid-xyz789');

        $result = $this->validationService->checkPreviousTxid($request);

        $this->assertFalse($result);
    }

    /**
     * Test checkPreviousTxid returns false when expected not null but received is null
     */
    public function testCheckPreviousTxidReturnsFalseWhenExpectedNotNullReceivedNull(): void
    {
        $request = [
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey'
            // previousTxid not set, defaults to null
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getPreviousTxid')
            ->with('sender-pubkey', 'receiver-pubkey')
            ->willReturn('txid-abc123');

        $result = $this->validationService->checkPreviousTxid($request);

        $this->assertFalse($result);
    }

    /**
     * Test checkPreviousTxid throws PDOException on database error
     */
    public function testCheckPreviousTxidThrowsPDOExceptionOnDatabaseError(): void
    {
        $request = [
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey'
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getPreviousTxid')
            ->willThrowException(new PDOException('Database error'));

        $this->expectException(PDOException::class);

        $this->validationService->checkPreviousTxid($request);
    }

    // =========================================================================
    // checkAvailableFundsTransaction() Tests
    // =========================================================================

    /**
     * Test checkAvailableFundsTransaction returns false when senderPublicKey is missing
     */
    public function testCheckAvailableFundsReturnsFalseWhenSenderPublicKeyMissing(): void
    {
        $request = [
            'amount' => 1000,
            'currency' => 'USD'
        ];

        $result = $this->validationService->checkAvailableFundsTransaction($request);

        $this->assertFalse($result);
    }

    /**
     * Test checkAvailableFundsTransaction returns false when amount is missing
     */
    public function testCheckAvailableFundsReturnsFalseWhenAmountMissing(): void
    {
        $request = [
            'senderPublicKey' => 'sender-pubkey',
            'currency' => 'USD'
        ];

        $result = $this->validationService->checkAvailableFundsTransaction($request);

        $this->assertFalse($result);
    }

    /**
     * Test checkAvailableFundsTransaction returns false when currency is missing
     */
    public function testCheckAvailableFundsReturnsFalseWhenCurrencyMissing(): void
    {
        $request = [
            'senderPublicKey' => 'sender-pubkey',
            'amount' => 1000
        ];

        $result = $this->validationService->checkAvailableFundsTransaction($request);

        $this->assertFalse($result);
    }

    /**
     * Test checkAvailableFundsTransaction returns true when sufficient funds available
     */
    public function testCheckAvailableFundsReturnsTrueWhenSufficientFunds(): void
    {
        $request = [
            'senderPublicKey' => 'sender-pubkey',
            'amount' => 1000,
            'currency' => 'USD'
        ];

        $this->validationUtility->expects($this->once())
            ->method('calculateAvailableFunds')
            ->willReturn(1500);

        $this->contactRepository->expects($this->once())
            ->method('getCreditLimit')
            ->with('sender-pubkey')
            ->willReturn(0.0);

        $result = $this->validationService->checkAvailableFundsTransaction($request);

        $this->assertTrue($result);
    }

    /**
     * Test checkAvailableFundsTransaction returns true when funds plus credit covers amount
     */
    public function testCheckAvailableFundsReturnsTrueWhenFundsPlusCreditCoversAmount(): void
    {
        $request = [
            'senderPublicKey' => 'sender-pubkey',
            'amount' => 1000,
            'currency' => 'USD'
        ];

        $this->validationUtility->expects($this->once())
            ->method('calculateAvailableFunds')
            ->willReturn(500);

        $this->contactRepository->expects($this->once())
            ->method('getCreditLimit')
            ->with('sender-pubkey')
            ->willReturn(600.0);

        $result = $this->validationService->checkAvailableFundsTransaction($request);

        $this->assertTrue($result);
    }

    /**
     * Test checkAvailableFundsTransaction returns false when insufficient funds
     */
    public function testCheckAvailableFundsReturnsFalseWhenInsufficientFunds(): void
    {
        $request = [
            'senderPublicKey' => 'sender-pubkey',
            'amount' => 1000,
            'currency' => 'USD'
        ];

        $this->validationUtility->expects($this->once())
            ->method('calculateAvailableFunds')
            ->willReturn(300);

        $this->contactRepository->expects($this->once())
            ->method('getCreditLimit')
            ->with('sender-pubkey')
            ->willReturn(500.0);

        $result = $this->validationService->checkAvailableFundsTransaction($request);

        $this->assertFalse($result);
    }

    /**
     * Test checkAvailableFundsTransaction returns true when exact funds available
     */
    public function testCheckAvailableFundsReturnsTrueWhenExactFundsAvailable(): void
    {
        $request = [
            'senderPublicKey' => 'sender-pubkey',
            'amount' => 1000,
            'currency' => 'USD'
        ];

        $this->validationUtility->expects($this->once())
            ->method('calculateAvailableFunds')
            ->willReturn(800);

        $this->contactRepository->expects($this->once())
            ->method('getCreditLimit')
            ->with('sender-pubkey')
            ->willReturn(200.0);

        $result = $this->validationService->checkAvailableFundsTransaction($request);

        $this->assertTrue($result);
    }

    /**
     * Test checkAvailableFundsTransaction returns false for invalid amount
     */
    public function testCheckAvailableFundsReturnsFalseForInvalidAmount(): void
    {
        $request = [
            'senderPublicKey' => 'sender-pubkey',
            'amount' => -100,
            'currency' => 'USD'
        ];

        $result = $this->validationService->checkAvailableFundsTransaction($request);

        $this->assertFalse($result);
    }

    /**
     * Test checkAvailableFundsTransaction returns false for zero amount
     */
    public function testCheckAvailableFundsReturnsFalseForZeroAmount(): void
    {
        $request = [
            'senderPublicKey' => 'sender-pubkey',
            'amount' => 0,
            'currency' => 'USD'
        ];

        $result = $this->validationService->checkAvailableFundsTransaction($request);

        $this->assertFalse($result);
    }

    /**
     * Test checkAvailableFundsTransaction returns false for non-numeric amount
     */
    public function testCheckAvailableFundsReturnsFalseForNonNumericAmount(): void
    {
        $request = [
            'senderPublicKey' => 'sender-pubkey',
            'amount' => 'not-a-number',
            'currency' => 'USD'
        ];

        $result = $this->validationService->checkAvailableFundsTransaction($request);

        $this->assertFalse($result);
    }

    /**
     * Test checkAvailableFundsTransaction throws PDOException on database error
     */
    public function testCheckAvailableFundsThrowsPDOExceptionOnDatabaseError(): void
    {
        $request = [
            'senderPublicKey' => 'sender-pubkey',
            'amount' => 1000,
            'currency' => 'USD'
        ];

        $this->validationUtility->expects($this->once())
            ->method('calculateAvailableFunds')
            ->willThrowException(new PDOException('Database error'));

        $this->expectException(PDOException::class);

        $this->validationService->checkAvailableFundsTransaction($request);
    }

    // =========================================================================
    // checkTransactionPossible() Tests
    // =========================================================================

    /**
     * Test checkTransactionPossible returns false when contact is blocked
     */
    public function testCheckTransactionPossibleReturnsFalseWhenContactBlocked(): void
    {
        $request = [
            'senderAddress' => 'http://sender.example.com',
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey',
            'amount' => 1000,
            'currency' => 'USD',
            'txid' => 'txid-123',
            'memo' => 'standard'
        ];

        $this->contactRepository->expects($this->once())
            ->method('isNotBlocked')
            ->with('sender-pubkey')
            ->willReturn(false);

        $this->transactionPayload->expects($this->once())
            ->method('buildRejection')
            ->with($request, 'contact_blocked')
            ->willReturn('{"status":"rejected","reason":"contact_blocked"}');

        ob_start();
        $result = $this->validationService->checkTransactionPossible($request);
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertStringContainsString('contact_blocked', $output);
    }

    /**
     * Test checkTransactionPossible returns false when contact is blocked (no echo)
     */
    public function testCheckTransactionPossibleReturnsFalseWhenContactBlockedNoEcho(): void
    {
        $request = [
            'senderAddress' => 'http://sender.example.com',
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey',
            'amount' => 1000,
            'currency' => 'USD',
            'txid' => 'txid-123',
            'memo' => 'standard'
        ];

        $this->contactRepository->expects($this->once())
            ->method('isNotBlocked')
            ->with('sender-pubkey')
            ->willReturn(false);

        $this->transactionPayload->expects($this->never())
            ->method('buildRejection');

        $result = $this->validationService->checkTransactionPossible($request, false);

        $this->assertFalse($result);
    }

    /**
     * Test checkTransactionPossible returns false when previous txid is invalid
     */
    public function testCheckTransactionPossibleReturnsFalseWhenInvalidPreviousTxid(): void
    {
        $request = [
            'senderAddress' => 'http://sender.example.com',
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey',
            'amount' => 1000,
            'currency' => 'USD',
            'txid' => 'txid-123',
            'previousTxid' => 'wrong-prev-txid',
            'memo' => 'standard'
        ];

        $this->contactRepository->expects($this->once())
            ->method('isNotBlocked')
            ->with('sender-pubkey')
            ->willReturn(true);

        // First call in checkPreviousTxid, second call for rejection message
        $this->transactionRepository->expects($this->exactly(2))
            ->method('getPreviousTxid')
            ->with('sender-pubkey', 'receiver-pubkey')
            ->willReturn('expected-prev-txid');

        // Sync is NOT triggered when received_previous_txid exists locally
        // but doesn't match expected (this is a chain fork scenario that
        // still triggers sync, but we need transactionExistsTxid to return true)
        $this->transactionRepository->expects($this->once())
            ->method('transactionExistsTxid')
            ->with('wrong-prev-txid')
            ->willReturn(true); // Exists locally, so it's a chain fork

        // Sync will be attempted for chain fork
        $this->syncTrigger->expects($this->once())
            ->method('syncTransactionChain')
            ->willReturn(['success' => false, 'synced_count' => 0]);

        $this->transactionPayload->expects($this->once())
            ->method('buildRejection')
            ->with($request, 'invalid_previous_txid', 'expected-prev-txid')
            ->willReturn('{"status":"rejected","reason":"invalid_previous_txid"}');

        ob_start();
        $result = $this->validationService->checkTransactionPossible($request);
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertStringContainsString('invalid_previous_txid', $output);
    }

    /**
     * Test checkTransactionPossible returns false when insufficient funds
     */
    public function testCheckTransactionPossibleReturnsFalseWhenInsufficientFunds(): void
    {
        $request = [
            'senderAddress' => 'http://sender.example.com',
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey',
            'amount' => 1000,
            'currency' => 'USD',
            'txid' => 'txid-123',
            'previousTxid' => 'prev-txid-123',
            'memo' => 'standard'
        ];

        $this->contactRepository->expects($this->once())
            ->method('isNotBlocked')
            ->with('sender-pubkey')
            ->willReturn(true);

        $this->transactionRepository->expects($this->once())
            ->method('getPreviousTxid')
            ->with('sender-pubkey', 'receiver-pubkey')
            ->willReturn('prev-txid-123');

        $this->validationUtility->expects($this->once())
            ->method('calculateAvailableFunds')
            ->willReturn(100);

        $this->contactRepository->expects($this->once())
            ->method('getCreditLimit')
            ->with('sender-pubkey')
            ->willReturn(100.0);

        $this->transactionPayload->expects($this->once())
            ->method('buildRejection')
            ->with($request, 'insufficient_funds')
            ->willReturn('{"status":"rejected","reason":"insufficient_funds"}');

        ob_start();
        $result = $this->validationService->checkTransactionPossible($request);
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertStringContainsString('insufficient_funds', $output);
    }

    /**
     * Test checkTransactionPossible returns false for duplicate standard transaction
     */
    public function testCheckTransactionPossibleReturnsFalseForDuplicateStandardTransaction(): void
    {
        $request = [
            'senderAddress' => 'http://sender.example.com',
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey',
            'amount' => 1000,
            'currency' => 'USD',
            'txid' => 'txid-123',
            'previousTxid' => 'prev-txid-123',
            'memo' => 'standard'
        ];

        $this->contactRepository->expects($this->once())
            ->method('isNotBlocked')
            ->with('sender-pubkey')
            ->willReturn(true);

        $this->transactionRepository->expects($this->once())
            ->method('getPreviousTxid')
            ->with('sender-pubkey', 'receiver-pubkey')
            ->willReturn('prev-txid-123');

        $this->validationUtility->expects($this->once())
            ->method('calculateAvailableFunds')
            ->willReturn(2000);

        $this->contactRepository->expects($this->once())
            ->method('getCreditLimit')
            ->with('sender-pubkey')
            ->willReturn(0.0);

        $this->transactionRepository->expects($this->once())
            ->method('transactionExistsTxid')
            ->with('txid-123')
            ->willReturn(true);

        // For duplicate handling, getByTxid is called to check chain conflict resolution
        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->with('txid-123')
            ->willReturn([['txid' => 'txid-123', 'previous_txid' => 'prev-txid-123']]);

        $this->transactionPayload->expects($this->once())
            ->method('buildRejection')
            ->with($request, 'duplicate')
            ->willReturn('{"status":"rejected","reason":"duplicate"}');

        ob_start();
        $result = $this->validationService->checkTransactionPossible($request);
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertStringContainsString('duplicate', $output);
    }

    /**
     * Test checkTransactionPossible returns false for duplicate P2P transaction (by memo)
     */
    public function testCheckTransactionPossibleReturnsFalseForDuplicateP2pTransaction(): void
    {
        $request = [
            'senderAddress' => 'http://sender.example.com',
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey',
            'amount' => 1000,
            'currency' => 'USD',
            'txid' => 'txid-123',
            'previousTxid' => 'prev-txid-123',
            'memo' => 'p2p-hash-abc123'
        ];

        $this->contactRepository->expects($this->once())
            ->method('isNotBlocked')
            ->with('sender-pubkey')
            ->willReturn(true);

        $this->transactionRepository->expects($this->once())
            ->method('getPreviousTxid')
            ->with('sender-pubkey', 'receiver-pubkey')
            ->willReturn('prev-txid-123');

        $this->validationUtility->expects($this->once())
            ->method('calculateAvailableFunds')
            ->willReturn(2000);

        $this->contactRepository->expects($this->once())
            ->method('getCreditLimit')
            ->with('sender-pubkey')
            ->willReturn(0.0);

        $this->transactionRepository->expects($this->once())
            ->method('transactionExistsMemo')
            ->with('p2p-hash-abc123')
            ->willReturn(true);

        $this->transactionPayload->expects($this->once())
            ->method('buildRejection')
            ->with($request, 'duplicate')
            ->willReturn('{"status":"rejected","reason":"duplicate"}');

        ob_start();
        $result = $this->validationService->checkTransactionPossible($request);
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertStringContainsString('duplicate', $output);
    }

    /**
     * Test checkTransactionPossible processes and accepts valid transaction
     */
    public function testCheckTransactionPossibleProcessesAndAcceptsValidTransaction(): void
    {
        $request = [
            'senderAddress' => 'http://sender.example.com',
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey',
            'amount' => 1000,
            'currency' => 'USD',
            'txid' => 'txid-123',
            'previousTxid' => 'prev-txid-123',
            'memo' => 'standard'
        ];

        $this->contactRepository->expects($this->once())
            ->method('isNotBlocked')
            ->with('sender-pubkey')
            ->willReturn(true);

        $this->transactionRepository->expects($this->once())
            ->method('getPreviousTxid')
            ->with('sender-pubkey', 'receiver-pubkey')
            ->willReturn('prev-txid-123');

        $this->validationUtility->expects($this->once())
            ->method('calculateAvailableFunds')
            ->willReturn(2000);

        $this->contactRepository->expects($this->once())
            ->method('getCreditLimit')
            ->with('sender-pubkey')
            ->willReturn(0.0);

        $this->transactionRepository->expects($this->once())
            ->method('transactionExistsTxid')
            ->with('txid-123')
            ->willReturn(false);

        $this->transactionPayload->expects($this->once())
            ->method('generateRecipientSignature')
            ->with($this->anything())
            ->willReturn('recipient-signature-base64');

        $this->transactionService->expects($this->once())
            ->method('processTransaction')
            ->with($this->callback(function ($arg) {
                return isset($arg['recipientSignature']) && $arg['recipientSignature'] === 'recipient-signature-base64';
            }));

        $this->transactionPayload->expects($this->once())
            ->method('buildAcceptance')
            ->with($this->anything())
            ->willReturn('{"status":"accepted","txid":"txid-123"}');

        ob_start();
        $result = $this->validationService->checkTransactionPossible($request);
        $output = ob_get_clean();

        // Returns false to prevent caller from calling processTransaction again
        $this->assertFalse($result);
        $this->assertStringContainsString('accepted', $output);
    }

    /**
     * Test checkTransactionPossible with proactive sync on chain mismatch
     */
    public function testCheckTransactionPossibleTriggersProactiveSyncOnChainMismatch(): void
    {
        $request = [
            'senderAddress' => 'http://sender.example.com',
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey',
            'amount' => 1000,
            'currency' => 'USD',
            'txid' => 'txid-123',
            'previousTxid' => 'remote-prev-txid',
            'memo' => 'standard'
        ];

        $this->contactRepository->expects($this->once())
            ->method('isNotBlocked')
            ->with('sender-pubkey')
            ->willReturn(true);

        // First check returns null (receiver has no history)
        $this->transactionRepository->expects($this->exactly(2))
            ->method('getPreviousTxid')
            ->with('sender-pubkey', 'receiver-pubkey')
            ->willReturn(null);

        // Sync should be triggered since we have no history but sender has previous_txid
        $this->syncTrigger->expects($this->once())
            ->method('syncTransactionChain')
            ->with('http://sender.example.com', 'sender-pubkey')
            ->willReturn(['success' => false, 'synced_count' => 0]);

        $this->transactionPayload->expects($this->once())
            ->method('buildRejection')
            ->with($request, 'invalid_previous_txid', null)
            ->willReturn('{"status":"rejected","reason":"invalid_previous_txid"}');

        ob_start();
        $result = $this->validationService->checkTransactionPossible($request);
        $output = ob_get_clean();

        $this->assertFalse($result);
    }

    /**
     * Test checkTransactionPossible handles successful proactive sync
     *
     * Note: This test is complex due to the re-validation path after sync.
     * After sync succeeds, checkPreviousTxid is called again which needs
     * the mock to return the synced value on the second call.
     */
    public function testCheckTransactionPossibleHandlesSuccessfulProactiveSync(): void
    {
        $request = [
            'senderAddress' => 'http://sender.example.com',
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey',
            'receiverAddress' => 'http://receiver.example.com',
            'amount' => 1000,
            'currency' => 'USD',
            'txid' => 'txid-123',
            'previousTxid' => 'remote-prev-txid',
            'memo' => 'standard',
            'time' => time()
        ];

        $this->contactRepository->expects($this->once())
            ->method('isNotBlocked')
            ->with('sender-pubkey')
            ->willReturn(true);

        // The getPreviousTxid is called multiple times:
        // 1. First in checkPreviousTxid (returns null - mismatch)
        // 2. After mismatch, again to get expected txid for sync decision (returns null)
        // 3. After successful sync, checkPreviousTxid is called again (returns 'remote-prev-txid' - match)
        $this->transactionRepository->expects($this->exactly(3))
            ->method('getPreviousTxid')
            ->with('sender-pubkey', 'receiver-pubkey')
            ->willReturnOnConsecutiveCalls(
                null,                // First call: checkPreviousTxid - mismatch
                null,                // Second call: getting expected for sync logic
                'remote-prev-txid'   // Third call: after sync, matches the request
            );

        // Sync triggers and succeeds
        $this->syncTrigger->expects($this->once())
            ->method('syncTransactionChain')
            ->with('http://sender.example.com', 'sender-pubkey')
            ->willReturn(['success' => true, 'synced_count' => 5]);

        $this->syncTrigger->expects($this->once())
            ->method('syncContactBalance')
            ->with('sender-pubkey');

        // After sync, validation continues
        $this->validationUtility->expects($this->once())
            ->method('calculateAvailableFunds')
            ->willReturn(2000);

        $this->contactRepository->expects($this->once())
            ->method('getCreditLimit')
            ->with('sender-pubkey')
            ->willReturn(0.0);

        $this->transactionRepository->expects($this->once())
            ->method('transactionExistsTxid')
            ->with('txid-123')
            ->willReturn(false);

        $this->transactionPayload->expects($this->once())
            ->method('generateRecipientSignature')
            ->willReturn('signature');

        $this->transactionService->expects($this->once())
            ->method('processTransaction');

        $this->transactionPayload->expects($this->once())
            ->method('buildAcceptance')
            ->willReturn('{"status":"accepted"}');

        ob_start();
        $result = $this->validationService->checkTransactionPossible($request);
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertStringContainsString('accepted', $output);
    }

    // =========================================================================
    // Setter Injection Tests
    // =========================================================================

    /**
     * Test setSyncTrigger properly injects dependency
     */
    public function testSetSyncTriggerInjectsDependency(): void
    {
        // Create a fresh service without sync trigger
        $reflection = new ReflectionClass(TransactionValidationService::class);
        $newService = $reflection->newInstanceWithoutConstructor();

        $syncTrigger = $this->createMock(SyncTriggerInterface::class);
        $newService->setSyncTrigger($syncTrigger);

        // Use reflection to verify the property was set
        $property = $reflection->getProperty('syncTrigger');
        $property->setAccessible(true);

        $this->assertSame($syncTrigger, $property->getValue($newService));
    }

    /**
     * Test setTransactionService properly injects dependency
     */
    public function testSetTransactionServiceInjectsDependency(): void
    {
        // Create a fresh service without transaction service
        $reflection = new ReflectionClass(TransactionValidationService::class);
        $newService = $reflection->newInstanceWithoutConstructor();

        $transactionService = $this->createMock(TransactionServiceInterface::class);
        $newService->setTransactionService($transactionService);

        // Use reflection to verify the property was set
        $property = $reflection->getProperty('transactionService');
        $property->setAccessible(true);

        $this->assertSame($transactionService, $property->getValue($newService));
    }

    // =========================================================================
    // Edge Cases and Error Handling
    // =========================================================================

    /**
     * Test checkTransactionPossible handles PDOException during existence check
     */
    public function testCheckTransactionPossibleHandlesPDOExceptionDuringExistenceCheck(): void
    {
        $request = [
            'senderAddress' => 'http://sender.example.com',
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey',
            'amount' => 1000,
            'currency' => 'USD',
            'txid' => 'txid-123',
            'previousTxid' => 'prev-txid-123',
            'memo' => 'standard'
        ];

        $this->contactRepository->expects($this->once())
            ->method('isNotBlocked')
            ->willReturn(true);

        $this->transactionRepository->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn('prev-txid-123');

        $this->validationUtility->expects($this->once())
            ->method('calculateAvailableFunds')
            ->willReturn(2000);

        $this->contactRepository->expects($this->once())
            ->method('getCreditLimit')
            ->willReturn(0.0);

        $this->transactionRepository->expects($this->once())
            ->method('transactionExistsTxid')
            ->willThrowException(new PDOException('Database error'));

        ob_start();
        $result = $this->validationService->checkTransactionPossible($request);
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertStringContainsString('500', $output);
    }

    /**
     * Test checkTransactionPossible with existing recipientSignature in request
     */
    public function testCheckTransactionPossibleUsesExistingRecipientSignature(): void
    {
        $request = [
            'senderAddress' => 'http://sender.example.com',
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey',
            'amount' => 1000,
            'currency' => 'USD',
            'txid' => 'txid-123',
            'previousTxid' => 'prev-txid-123',
            'memo' => 'standard',
            'recipientSignature' => 'existing-signature'
        ];

        $this->contactRepository->expects($this->once())
            ->method('isNotBlocked')
            ->willReturn(true);

        $this->transactionRepository->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn('prev-txid-123');

        $this->validationUtility->expects($this->once())
            ->method('calculateAvailableFunds')
            ->willReturn(2000);

        $this->contactRepository->expects($this->once())
            ->method('getCreditLimit')
            ->willReturn(0.0);

        $this->transactionRepository->expects($this->once())
            ->method('transactionExistsTxid')
            ->willReturn(false);

        // Should NOT call generateRecipientSignature since it already exists
        $this->transactionPayload->expects($this->never())
            ->method('generateRecipientSignature');

        $this->transactionService->expects($this->once())
            ->method('processTransaction')
            ->with($this->callback(function ($arg) {
                return isset($arg['recipientSignature']) && $arg['recipientSignature'] === 'existing-signature';
            }));

        $this->transactionPayload->expects($this->once())
            ->method('buildAcceptance')
            ->willReturn('{"status":"accepted"}');

        ob_start();
        $result = $this->validationService->checkTransactionPossible($request);
        ob_get_clean();

        $this->assertFalse($result);
    }

    /**
     * Test checkTransactionPossible handles chain conflict resolution for duplicate txid
     */
    public function testCheckTransactionPossibleHandlesChainConflictResolution(): void
    {
        $request = [
            'senderAddress' => 'http://sender.example.com',
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey',
            'receiverAddress' => 'http://receiver.example.com',
            'amount' => 1000,
            'currency' => 'USD',
            'txid' => 'txid-123',
            'previousTxid' => 'new-prev-txid',  // Different from existing
            'memo' => 'standard',
            'senderSignature' => 'new-signature',
            'signatureNonce' => '12345'
        ];

        $this->contactRepository->expects($this->once())
            ->method('isNotBlocked')
            ->willReturn(true);

        $this->transactionRepository->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn('new-prev-txid');

        $this->validationUtility->expects($this->once())
            ->method('calculateAvailableFunds')
            ->willReturn(2000);

        $this->contactRepository->expects($this->once())
            ->method('getCreditLimit')
            ->willReturn(0.0);

        // Transaction exists (duplicate)
        $this->transactionRepository->expects($this->once())
            ->method('transactionExistsTxid')
            ->with('txid-123')
            ->willReturn(true);

        // getByTxid returns existing transaction with different previous_txid
        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->with('txid-123')
            ->willReturn([[
                'txid' => 'txid-123',
                'previous_txid' => 'old-prev-txid'  // Different from request
            ]]);

        // Signature verification passes
        $this->syncTrigger->expects($this->once())
            ->method('verifyTransactionSignaturePublic')
            ->willReturn(true);

        // Chain conflict resolution update is called
        $this->transactionChainRepository->expects($this->once())
            ->method('updateChainConflictResolution')
            ->with('txid-123', 'new-prev-txid', 'new-signature', '12345')
            ->willReturn(true);

        $this->transactionPayload->expects($this->once())
            ->method('buildAcceptance')
            ->willReturn('{"status":"accepted"}');

        ob_start();
        $result = $this->validationService->checkTransactionPossible($request);
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertStringContainsString('accepted', $output);
    }

    /**
     * Test checkTransactionPossible rejects chain conflict with invalid signature
     */
    public function testCheckTransactionPossibleRejectsChainConflictWithInvalidSignature(): void
    {
        $request = [
            'senderAddress' => 'http://sender.example.com',
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey',
            'receiverAddress' => 'http://receiver.example.com',
            'amount' => 1000,
            'currency' => 'USD',
            'txid' => 'txid-123',
            'previousTxid' => 'new-prev-txid',
            'memo' => 'standard',
            'senderSignature' => 'invalid-signature',
            'signatureNonce' => '12345'
        ];

        $this->contactRepository->expects($this->once())
            ->method('isNotBlocked')
            ->willReturn(true);

        $this->transactionRepository->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn('new-prev-txid');

        $this->validationUtility->expects($this->once())
            ->method('calculateAvailableFunds')
            ->willReturn(2000);

        $this->contactRepository->expects($this->once())
            ->method('getCreditLimit')
            ->willReturn(0.0);

        $this->transactionRepository->expects($this->once())
            ->method('transactionExistsTxid')
            ->willReturn(true);

        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->willReturn([[
                'txid' => 'txid-123',
                'previous_txid' => 'old-prev-txid'
            ]]);

        // Signature verification fails
        $this->syncTrigger->expects($this->once())
            ->method('verifyTransactionSignaturePublic')
            ->willReturn(false);

        $this->transactionPayload->expects($this->once())
            ->method('buildRejection')
            ->with($request, 'duplicate')
            ->willReturn('{"status":"rejected","reason":"duplicate"}');

        ob_start();
        $result = $this->validationService->checkTransactionPossible($request);
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertStringContainsString('duplicate', $output);
    }

    /**
     * Test checkTransactionPossible propagates processing exception
     *
     * NOTE: The TransactionValidationService has `catch (Exception $e)` blocks
     * but does NOT import Exception from the global namespace. This means
     * PHP looks for `Eiou\Services\Exception` which doesn't exist, so
     * exceptions are NOT caught. This test verifies the current behavior
     * where exceptions propagate up to the caller.
     *
     * TODO: The service should use `catch (\Exception $e)` or add
     * `use Exception;` at the top of the file to properly catch exceptions.
     */
    public function testCheckTransactionPossiblePropagatesProcessingException(): void
    {
        $request = [
            'senderAddress' => 'http://sender.example.com',
            'senderPublicKey' => 'sender-pubkey',
            'receiverPublicKey' => 'receiver-pubkey',
            'amount' => 1000,
            'currency' => 'USD',
            'txid' => 'txid-123',
            'previousTxid' => 'prev-txid-123',
            'memo' => 'standard'
        ];

        $this->contactRepository->expects($this->once())
            ->method('isNotBlocked')
            ->willReturn(true);

        $this->transactionRepository->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn('prev-txid-123');

        $this->validationUtility->expects($this->once())
            ->method('calculateAvailableFunds')
            ->willReturn(2000);

        $this->contactRepository->expects($this->once())
            ->method('getCreditLimit')
            ->willReturn(0.0);

        $this->transactionRepository->expects($this->once())
            ->method('transactionExistsTxid')
            ->willReturn(false);

        $this->transactionPayload->expects($this->once())
            ->method('generateRecipientSignature')
            ->willReturn('signature');

        // Processing throws exception
        $this->transactionService->expects($this->once())
            ->method('processTransaction')
            ->willThrowException(new \Exception('Processing failed'));

        // Expect the exception to propagate since the catch block doesn't work
        // due to unimported Exception class in the service
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Processing failed');

        $this->validationService->checkTransactionPossible($request);
    }
}
