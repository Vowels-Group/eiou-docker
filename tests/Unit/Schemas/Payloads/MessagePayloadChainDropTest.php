<?php
/**
 * Unit Tests for MessagePayload Chain Drop Methods
 *
 * Tests the chain drop payload builder methods:
 * - buildChainDropProposal
 * - buildChainDropAcceptance
 * - buildChainDropRejection
 * - buildChainDropAcknowledgment
 */

namespace Eiou\Tests\Schemas\Payloads;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Schemas\Payloads\MessagePayload;
use Eiou\Core\UserContext;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\ValidationUtilityService;

#[CoversClass(MessagePayload::class)]
class MessagePayloadChainDropTest extends TestCase
{
    private UserContext $userContext;
    private UtilityServiceContainer $utilityContainer;
    private TransportUtilityService $transportUtility;
    private MessagePayload $messagePayload;

    private const TEST_ADDRESS = 'http://contact.example.com';
    private const TEST_RESOLVED_ADDRESS = 'http://myaddress.example.com';
    private const TEST_PUBLIC_KEY = 'test-public-key-abc123def456789012345678901234567890';
    private const TEST_PROPOSAL_ID = 'cdp-test-proposal-id-123';
    private const TEST_MISSING_TXID = 'missing_txid_abc123';
    private const TEST_BROKEN_TXID = 'broken_txid_xyz789';
    private const TEST_PREVIOUS_TXID = 'prev_txid_000111';

    protected function setUp(): void
    {
        // Create mock objects for dependencies
        $this->userContext = $this->createMock(UserContext::class);
        $this->utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->transportUtility = $this->createMock(TransportUtilityService::class);

        // Create mocks for other utility services (required by BasePayload constructor)
        $currencyUtility = $this->createMock(CurrencyUtilityService::class);
        $timeUtility = $this->createMock(TimeUtilityService::class);
        $validationUtility = $this->createMock(ValidationUtilityService::class);

        // Configure utility container to return mock services
        $this->utilityContainer->expects($this->any())
            ->method('getCurrencyUtility')
            ->willReturn($currencyUtility);

        $this->utilityContainer->expects($this->any())
            ->method('getTimeUtility')
            ->willReturn($timeUtility);

        $this->utilityContainer->expects($this->any())
            ->method('getValidationUtility')
            ->willReturn($validationUtility);

        $this->utilityContainer->expects($this->any())
            ->method('getTransportUtility')
            ->willReturn($this->transportUtility);

        // Default mock behaviors
        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn(self::TEST_RESOLVED_ADDRESS);

        $this->userContext->method('getPublicKey')
            ->willReturn(self::TEST_PUBLIC_KEY);

        // Create the MessagePayload instance with mocked dependencies
        $this->messagePayload = new MessagePayload(
            $this->userContext,
            $this->utilityContainer
        );
    }

    // =========================================================================
    // buildChainDropProposal() Tests
    // =========================================================================

    /**
     * Test buildChainDropProposal returns correct array structure
     */
    public function testBuildChainDropProposal(): void
    {
        $gapContext = ['chain_transaction_count' => 10];

        $result = $this->messagePayload->buildChainDropProposal(
            self::TEST_ADDRESS,
            self::TEST_PROPOSAL_ID,
            self::TEST_MISSING_TXID,
            self::TEST_BROKEN_TXID,
            self::TEST_PREVIOUS_TXID,
            $gapContext
        );

        $this->assertIsArray($result);
        $this->assertEquals('message', $result['type']);
        $this->assertEquals('chain_drop', $result['typeMessage']);
        $this->assertEquals('propose', $result['action']);
        $this->assertEquals(self::TEST_PROPOSAL_ID, $result['proposalId']);
        $this->assertEquals(self::TEST_MISSING_TXID, $result['missingTxid']);
        $this->assertEquals(self::TEST_BROKEN_TXID, $result['brokenTxid']);
        $this->assertEquals(self::TEST_PREVIOUS_TXID, $result['previousTxidBeforeGap']);
        $this->assertEquals($gapContext, $result['gapContext']);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
        $this->assertStringContainsString('Chain drop proposal', $result['message']);
    }

    /**
     * Test buildChainDropProposal with null previousTxidBeforeGap
     */
    public function testBuildChainDropProposalWithNullPreviousTxid(): void
    {
        $result = $this->messagePayload->buildChainDropProposal(
            self::TEST_ADDRESS,
            self::TEST_PROPOSAL_ID,
            self::TEST_MISSING_TXID,
            self::TEST_BROKEN_TXID,
            null,
            []
        );

        $this->assertNull($result['previousTxidBeforeGap']);
        $this->assertEquals('propose', $result['action']);
    }

    // =========================================================================
    // buildChainDropAcceptance() Tests
    // =========================================================================

    /**
     * Test buildChainDropAcceptance returns correct structure with resignedTransactions
     */
    public function testBuildChainDropAcceptance(): void
    {
        $resignedTransactions = [
            [
                'txid' => 'resigned_tx_1',
                'previous_txid' => self::TEST_PREVIOUS_TXID,
                'sender_signature' => 'new_sig_abc',
                'signature_nonce' => 42
            ]
        ];

        $result = $this->messagePayload->buildChainDropAcceptance(
            self::TEST_ADDRESS,
            self::TEST_PROPOSAL_ID,
            $resignedTransactions
        );

        $this->assertIsArray($result);
        $this->assertEquals('message', $result['type']);
        $this->assertEquals('chain_drop', $result['typeMessage']);
        $this->assertEquals('accept', $result['action']);
        $this->assertEquals(self::TEST_PROPOSAL_ID, $result['proposalId']);
        $this->assertEquals($resignedTransactions, $result['resignedTransactions']);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
        $this->assertStringContainsString('accepted', $result['message']);
    }

    /**
     * Test buildChainDropAcceptance with empty resignedTransactions
     */
    public function testBuildChainDropAcceptanceWithEmptyResignedTransactions(): void
    {
        $result = $this->messagePayload->buildChainDropAcceptance(
            self::TEST_ADDRESS,
            self::TEST_PROPOSAL_ID,
            []
        );

        $this->assertEmpty($result['resignedTransactions']);
        $this->assertEquals('accept', $result['action']);
    }

    // =========================================================================
    // buildChainDropRejection() Tests
    // =========================================================================

    /**
     * Test buildChainDropRejection returns correct structure with reason
     */
    public function testBuildChainDropRejection(): void
    {
        $reason = 'transaction_exists_locally';

        $result = $this->messagePayload->buildChainDropRejection(
            self::TEST_ADDRESS,
            self::TEST_PROPOSAL_ID,
            $reason
        );

        $this->assertIsArray($result);
        $this->assertEquals('message', $result['type']);
        $this->assertEquals('chain_drop', $result['typeMessage']);
        $this->assertEquals('reject', $result['action']);
        $this->assertEquals(self::TEST_PROPOSAL_ID, $result['proposalId']);
        $this->assertEquals($reason, $result['reason']);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
        $this->assertStringContainsString('rejected', $result['message']);
        $this->assertStringContainsString($reason, $result['message']);
    }

    // =========================================================================
    // buildChainDropAcknowledgment() Tests
    // =========================================================================

    /**
     * Test buildChainDropAcknowledgment returns correct structure
     */
    public function testBuildChainDropAcknowledgment(): void
    {
        $resignedTransactions = [
            [
                'txid' => 'resigned_tx_ack_1',
                'previous_txid' => self::TEST_PREVIOUS_TXID,
                'sender_signature' => 'ack_sig_abc',
                'signature_nonce' => 99
            ]
        ];

        $result = $this->messagePayload->buildChainDropAcknowledgment(
            self::TEST_ADDRESS,
            self::TEST_PROPOSAL_ID,
            $resignedTransactions
        );

        $this->assertIsArray($result);
        $this->assertEquals('message', $result['type']);
        $this->assertEquals('chain_drop', $result['typeMessage']);
        $this->assertEquals('acknowledge', $result['action']);
        $this->assertEquals(self::TEST_PROPOSAL_ID, $result['proposalId']);
        $this->assertEquals($resignedTransactions, $result['resignedTransactions']);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
        $this->assertStringContainsString('acknowledgment', $result['message']);
    }

    /**
     * Test buildChainDropAcknowledgment with empty resignedTransactions
     */
    public function testBuildChainDropAcknowledgmentWithEmptyResignedTransactions(): void
    {
        $result = $this->messagePayload->buildChainDropAcknowledgment(
            self::TEST_ADDRESS,
            self::TEST_PROPOSAL_ID,
            []
        );

        $this->assertIsArray($result);
        $this->assertEmpty($result['resignedTransactions']);
        $this->assertEquals('acknowledge', $result['action']);
        $this->assertEquals(self::TEST_PROPOSAL_ID, $result['proposalId']);
    }
}
