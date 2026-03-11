<?php
/**
 * Unit Tests for OutputSchema
 *
 * Tests the output functions that provide debug/logging messages.
 */

namespace Eiou\Tests\Schemas;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;

// Import the output schema functions
$filesRoot = defined('EIOU_FILES_ROOT') ? EIOU_FILES_ROOT : dirname(__DIR__, 3) . '/files';
require_once $filesRoot . '/src/schemas/OutputSchema.php';

#[CoversFunction('outputAddressContactIssue')]
#[CoversFunction('outputAddressOrContactIssue')]
#[CoversFunction('outputCalculatedContactHash')]
#[CoversFunction('outputContactMatched')]
#[CoversFunction('outputContactSuccessfullySynced')]
#[CoversFunction('outputContactNoResponseSync')]
#[CoversFunction('outputContactNoNeedSync')]
#[CoversFunction('outputContactNotFoundTryP2p')]
#[CoversFunction('outputContactRequestWasAccepted')]
#[CoversFunction('outputContactBlockedNoTransaction')]
#[CoversFunction('outputContactUnblockedAndAdded')]
#[CoversFunction('outputContactUnblockedAndAddedFailure')]
#[CoversFunction('outputContactUnblockedAndOverwritten')]
#[CoversFunction('outputContactUnblockedAndOverwrittenFailure')]
#[CoversFunction('outputContactUpdatedAddress')]
#[CoversFunction('outputContactUpdatedAddressFailure')]
#[CoversFunction('outputFailedContactInteraction')]
#[CoversFunction('outputFailedContactRequest')]
#[CoversFunction('outputLookedUpContactInfo')]
#[CoversFunction('outputSendContactAcceptedSuccesfullyMessage')]
#[CoversFunction('outputSyncContactDueToPendingStatus')]
#[CoversFunction('outputBuildingTransactionPayload')]
#[CoversFunction('outputEiouSend')]
#[CoversFunction('outputFeeInformation')]
#[CoversFunction('outputFeeRejection')]
#[CoversFunction('outputNoViableTransportAddress')]
#[CoversFunction('outputNoViableTransportMode')]
#[CoversFunction('outputBuildingP2pPayload')]
#[CoversFunction('outputGeneratedP2pHash')]
#[CoversFunction('outputInsertedP2p')]
#[CoversFunction('outputNoViableRouteP2p')]
#[CoversFunction('outputP2pComponents')]
#[CoversFunction('outputP2pExpired')]
#[CoversFunction('outputBuildingRp2pPayload')]
#[CoversFunction('outputFoundRp2pMatch')]
#[CoversFunction('outputInsertedRp2p')]
#[CoversFunction('outputNoSuppliedAddress')]
#[CoversFunction('outputMessageDeliveryCreated')]
#[CoversFunction('outputMessageDeliveryCompleted')]
#[CoversFunction('outputMessageDeliveryFailed')]
#[CoversFunction('outputSyncChainIntegrityFailed')]
#[CoversFunction('outputSyncChainRepaired')]
#[CoversFunction('outputSyncTransactionsSynced')]
class OutputSchemaTest extends TestCase
{
    // =========================================================================
    // Contact Output Function Tests
    // =========================================================================

    /**
     * Test outputAddressContactIssue includes address
     */
    public function testOutputAddressContactIssueIncludesAddress(): void
    {
        $address = 'http://example.onion';
        $result = outputAddressContactIssue($address);

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString($address, $result);
        $this->assertStringContainsString('No contact', $result);
    }

    /**
     * Test outputAddressOrContactIssue includes data
     */
    public function testOutputAddressOrContactIssueIncludesData(): void
    {
        $data = ['cmd', 'action', 'testname'];
        $result = outputAddressOrContactIssue($data);

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('testname', $result);
    }

    /**
     * Test outputCalculatedContactHash includes hash
     */
    public function testOutputCalculatedContactHashIncludesHash(): void
    {
        $hash = 'abc123def456';
        $result = outputCalculatedContactHash($hash);

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString($hash, $result);
        $this->assertStringContainsString('Calculated', $result);
    }

    /**
     * Test outputContactMatched includes hash
     */
    public function testOutputContactMatchedIncludesHash(): void
    {
        $hash = 'abc123def456';
        $result = outputContactMatched($hash);

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString($hash, $result);
        $this->assertStringContainsString('Matched', $result);
    }

    /**
     * Test outputContactSuccessfullySynced includes address
     */
    public function testOutputContactSuccessfullySyncedIncludesAddress(): void
    {
        $address = 'http://example.onion';
        $result = outputContactSuccessfullySynced($address);

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString($address, $result);
        $this->assertStringContainsString('synced', $result);
    }

    /**
     * Test outputContactNoResponseSync returns expected message
     */
    public function testOutputContactNoResponseSyncReturnsExpectedMessage(): void
    {
        $result = outputContactNoResponseSync();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('Did not respond', $result);
    }

    /**
     * Test outputContactNoNeedSync includes address
     */
    public function testOutputContactNoNeedSyncIncludesAddress(): void
    {
        $address = 'http://example.onion';
        $result = outputContactNoNeedSync($address);

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString($address, $result);
        $this->assertStringContainsString('no need for syncing', $result);
    }

    /**
     * Test outputContactNotFoundTryP2p includes request data
     */
    public function testOutputContactNotFoundTryP2pIncludesRequestData(): void
    {
        $request = ['address' => 'test.onion', 'amount' => 100];
        $result = outputContactNotFoundTryP2p($request);

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('Not found', $result);
        $this->assertStringContainsString('p2p', $result);
    }

    /**
     * Test outputContactRequestWasAccepted includes address
     */
    public function testOutputContactRequestWasAcceptedIncludesAddress(): void
    {
        $address = 'http://example.onion';
        $result = outputContactRequestWasAccepted($address);

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString($address, $result);
        $this->assertStringContainsString('accepted', $result);
    }

    /**
     * Test outputContactBlockedNoTransaction returns expected message
     */
    public function testOutputContactBlockedNoTransactionReturnsExpectedMessage(): void
    {
        $result = outputContactBlockedNoTransaction();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('blocked', $result);
        $this->assertStringContainsString('will not be sent', $result);
    }

    /**
     * Test outputContactUnblockedAndAdded returns expected message
     */
    public function testOutputContactUnblockedAndAddedReturnsExpectedMessage(): void
    {
        $result = outputContactUnblockedAndAdded();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('unblocked', $result);
    }

    /**
     * Test outputFailedContactInteraction returns expected message
     */
    public function testOutputFailedContactInteractionReturnsExpectedMessage(): void
    {
        $result = outputFailedContactInteraction();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('does not exist', $result);
        $this->assertStringContainsString('try again later', $result);
    }

    // =========================================================================
    // Transaction Output Function Tests
    // =========================================================================

    /**
     * Test outputBuildingTransactionPayload includes data
     */
    public function testOutputBuildingTransactionPayloadIncludesData(): void
    {
        $data = ['amount' => 100, 'currency' => 'USD'];
        $result = outputBuildingTransactionPayload($data);

        $this->assertIsString($result);
        $this->assertStringContainsString('[Transaction]', $result);
        $this->assertStringContainsString('Building payload', $result);
    }

    /**
     * Test outputEiouSend includes request
     */
    public function testOutputEiouSendIncludesRequest(): void
    {
        $request = ['to' => 'test.onion', 'amount' => 50];
        $result = outputEiouSend($request);

        $this->assertIsString($result);
        $this->assertStringContainsString('[Transaction]', $result);
        $this->assertStringContainsString('send eIOU', $result);
    }

    /**
     * Test outputFeeInformation includes fee details
     */
    public function testOutputFeeInformationIncludesFeeDetails(): void
    {
        $feePercent = '1.5';
        $request = ['hash' => 'abc123'];
        $maxFee = '2.0';

        $result = outputFeeInformation($feePercent, $request, $maxFee);

        $this->assertIsString($result);
        $this->assertStringContainsString('[Transaction]', $result);
        $this->assertStringContainsString($feePercent, $result);
        $this->assertStringContainsString($maxFee, $result);
        $this->assertStringContainsString('abc123', $result);
    }

    /**
     * Test outputFeeRejection returns expected message
     */
    public function testOutputFeeRejectionReturnsExpectedMessage(): void
    {
        $result = outputFeeRejection();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Transaction]', $result);
        $this->assertStringContainsString('reject', $result);
        $this->assertStringContainsString('fee', $result);
    }

    /**
     * Test outputNoViableTransportAddress returns expected message
     */
    public function testOutputNoViableTransportAddressReturnsExpectedMessage(): void
    {
        $result = outputNoViableTransportAddress();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Transaction]', $result);
        $this->assertStringContainsString('No viable transport address', $result);
    }

    /**
     * Test outputNoViableTransportMode returns expected message
     */
    public function testOutputNoViableTransportModeReturnsExpectedMessage(): void
    {
        $result = outputNoViableTransportMode();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Transaction]', $result);
        $this->assertStringContainsString('No viable transport mode', $result);
    }

    // =========================================================================
    // P2P Output Function Tests
    // =========================================================================

    /**
     * Test outputBuildingP2pPayload includes data
     */
    public function testOutputBuildingP2pPayloadIncludesData(): void
    {
        $data = ['receiver' => 'test.onion', 'amount' => 100];
        $result = outputBuildingP2pPayload($data);

        $this->assertIsString($result);
        $this->assertStringContainsString('[P2P]', $result);
        $this->assertStringContainsString('Building payload', $result);
    }

    /**
     * Test outputGeneratedP2pHash includes hash
     */
    public function testOutputGeneratedP2pHashIncludesHash(): void
    {
        $hash = 'abc123def456';
        $result = outputGeneratedP2pHash($hash);

        $this->assertIsString($result);
        $this->assertStringContainsString('[P2P]', $result);
        $this->assertStringContainsString($hash, $result);
        $this->assertStringContainsString('Generated hash', $result);
    }

    /**
     * Test outputInsertedP2p includes request hash
     */
    public function testOutputInsertedP2pIncludesRequestHash(): void
    {
        $request = ['hash' => 'abc123'];
        $result = outputInsertedP2p($request);

        $this->assertIsString($result);
        $this->assertStringContainsString('[P2P]', $result);
        $this->assertStringContainsString('Inserted', $result);
    }

    /**
     * Test outputNoViableRouteP2p includes hash
     */
    public function testOutputNoViableRouteP2pIncludesHash(): void
    {
        $hash = 'abc123';
        $result = outputNoViableRouteP2p($hash);

        $this->assertIsString($result);
        $this->assertStringContainsString('[P2P]', $result);
        $this->assertStringContainsString('No viable route', $result);
        $this->assertStringContainsString($hash, $result);
    }

    /**
     * Test outputP2pComponents includes all components
     */
    public function testOutputP2pComponentsIncludesAllComponents(): void
    {
        $data = [
            'receiverAddress' => 'test.onion',
            'salt' => 'random_salt',
            'time' => 1234567890
        ];
        $result = outputP2pComponents($data);

        $this->assertIsString($result);
        $this->assertStringContainsString('[P2P]', $result);
        $this->assertStringContainsString('test.onion', $result);
        $this->assertStringContainsString('random_salt', $result);
        $this->assertStringContainsString('1234567890', $result);
    }

    /**
     * Test outputP2pExpired includes hash
     */
    public function testOutputP2pExpiredIncludesHash(): void
    {
        $message = ['hash' => 'expired_hash_123'];
        $result = outputP2pExpired($message);

        $this->assertIsString($result);
        $this->assertStringContainsString('[P2P]', $result);
        $this->assertStringContainsString('expired_hash_123', $result);
        $this->assertStringContainsString('expired', $result);
    }

    // =========================================================================
    // RP2P Output Function Tests
    // =========================================================================

    /**
     * Test outputBuildingRp2pPayload includes data
     */
    public function testOutputBuildingRp2pPayloadIncludesData(): void
    {
        $data = ['receiver' => 'test.onion', 'amount' => 100];
        $result = outputBuildingRp2pPayload($data);

        $this->assertIsString($result);
        $this->assertStringContainsString('[RP2P]', $result);
        $this->assertStringContainsString('Building payload', $result);
    }

    /**
     * Test outputFoundRp2pMatch includes hash
     */
    public function testOutputFoundRp2pMatchIncludesHash(): void
    {
        $message = ['hash' => 'match_hash_123'];
        $result = outputFoundRp2pMatch($message);

        $this->assertIsString($result);
        $this->assertStringContainsString('[RP2P]', $result);
        $this->assertStringContainsString('Found match', $result);
        $this->assertStringContainsString('match_hash_123', $result);
    }

    /**
     * Test outputInsertedRp2p includes request hash
     */
    public function testOutputInsertedRp2pIncludesRequestHash(): void
    {
        $request = ['hash' => 'rp2p_hash_123'];
        $result = outputInsertedRp2p($request);

        $this->assertIsString($result);
        $this->assertStringContainsString('[RP2P]', $result);
        $this->assertStringContainsString('Inserted', $result);
    }

    // =========================================================================
    // Validation Output Function Tests
    // =========================================================================

    /**
     * Test outputNoSuppliedAddress returns expected message
     */
    public function testOutputNoSuppliedAddressReturnsExpectedMessage(): void
    {
        $result = outputNoSuppliedAddress();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Validation]', $result);
        $this->assertStringContainsString('No address', $result);
    }

    // =========================================================================
    // Message Delivery Output Function Tests
    // =========================================================================

    /**
     * Test outputMessageDeliveryCreated includes all parameters
     */
    public function testOutputMessageDeliveryCreatedIncludesAllParameters(): void
    {
        $result = outputMessageDeliveryCreated('transaction', 'tx123', 'test.onion');

        $this->assertIsString($result);
        $this->assertStringContainsString('[MessageDelivery]', $result);
        $this->assertStringContainsString('Created', $result);
        $this->assertStringContainsString('transaction', $result);
        $this->assertStringContainsString('tx123', $result);
        $this->assertStringContainsString('test.onion', $result);
    }

    /**
     * Test outputMessageDeliveryCompleted includes type and id
     */
    public function testOutputMessageDeliveryCompletedIncludesTypeAndId(): void
    {
        $result = outputMessageDeliveryCompleted('p2p', 'p2p_hash_123');

        $this->assertIsString($result);
        $this->assertStringContainsString('[MessageDelivery]', $result);
        $this->assertStringContainsString('Completed', $result);
        $this->assertStringContainsString('p2p', $result);
        $this->assertStringContainsString('p2p_hash_123', $result);
    }

    /**
     * Test outputMessageDeliveryFailed includes reason
     */
    public function testOutputMessageDeliveryFailedIncludesReason(): void
    {
        $result = outputMessageDeliveryFailed('contact', 'contact_123', 'Connection refused');

        $this->assertIsString($result);
        $this->assertStringContainsString('[MessageDelivery]', $result);
        $this->assertStringContainsString('Failed', $result);
        $this->assertStringContainsString('contact', $result);
        $this->assertStringContainsString('Connection refused', $result);
    }

    // =========================================================================
    // Sync Output Function Tests
    // =========================================================================

    /**
     * Test outputSyncChainIntegrityFailed includes gap count
     */
    public function testOutputSyncChainIntegrityFailedIncludesGapCount(): void
    {
        $result = outputSyncChainIntegrityFailed(5);

        $this->assertIsString($result);
        $this->assertStringContainsString('[Sync]', $result);
        $this->assertStringContainsString('5', $result);
        $this->assertStringContainsString('missing transactions', $result);
    }

    /**
     * Test outputSyncChainRepaired returns expected message
     */
    public function testOutputSyncChainRepairedReturnsExpectedMessage(): void
    {
        $result = outputSyncChainRepaired();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Sync]', $result);
        $this->assertStringContainsString('Chain sync completed', $result);
        $this->assertStringContainsString('valid', $result);
    }

    /**
     * Test outputSyncTransactionsSynced includes count
     */
    public function testOutputSyncTransactionsSyncedIncludesCount(): void
    {
        $result = outputSyncTransactionsSynced(10);

        $this->assertIsString($result);
        $this->assertStringContainsString('[Sync]', $result);
        $this->assertStringContainsString('Synced', $result);
        $this->assertStringContainsString('10', $result);
    }

    // =========================================================================
    // Message Format Tests
    // =========================================================================

    /**
     * Test all output messages end with newline
     */
    public function testAllOutputMessagesEndWithNewline(): void
    {
        $messages = [
            outputContactNoResponseSync(),
            outputContactBlockedNoTransaction(),
            outputFeeRejection(),
            outputNoViableTransportAddress(),
            outputNoSuppliedAddress(),
            outputSyncChainRepaired()
        ];

        foreach ($messages as $message) {
            $this->assertStringEndsWith("\n", $message, "Message should end with newline");
        }
    }

    /**
     * Test all output messages have category prefix in brackets
     */
    public function testAllOutputMessagesHaveCategoryPrefixInBrackets(): void
    {
        $categoryPattern = '/^\[.+?\]/';

        $messages = [
            outputContactNoResponseSync(),
            outputFeeRejection(),
            outputNoSuppliedAddress(),
            outputSyncChainRepaired()
        ];

        foreach ($messages as $message) {
            $this->assertMatchesRegularExpression(
                $categoryPattern,
                $message,
                "Message should start with bracketed category: {$message}"
            );
        }
    }
}
