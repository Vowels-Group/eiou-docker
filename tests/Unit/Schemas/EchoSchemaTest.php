<?php
/**
 * Unit Tests for EchoSchema
 *
 * Tests the echo/return functions that provide user-facing messages.
 */

namespace Eiou\Tests\Schemas;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;

// Import the echo schema functions
$filesRoot = defined('EIOU_FILES_ROOT') ? EIOU_FILES_ROOT : dirname(__DIR__, 3) . '/files';
require_once $filesRoot . '/src/schemas/EchoSchema.php';

#[CoversFunction('returnContactAccepted')]
#[CoversFunction('returnContactAcceptanceFailed')]
#[CoversFunction('returnContactAddInvalidInput')]
#[CoversFunction('returnContactCreationSuccessful')]
#[CoversFunction('returnContactCreationFailed')]
#[CoversFunction('returnContactRequestAlreadyInserted')]
#[CoversFunction('returnContactCreationWarning')]
#[CoversFunction('returnContactDeletedSuccesfully')]
#[CoversFunction('returnContactDetails')]
#[CoversFunction('returnContactExists')]
#[CoversFunction('returnContactNotFound')]
#[CoversFunction('returnContactNotFoundNoAction')]
#[CoversFunction('returnContactUpdate')]
#[CoversFunction('returnContactUpdateInvalidInput')]
#[CoversFunction('returnContactUpdateInvalidInputParameters')]
#[CoversFunction('returnContactReadInvalidInput')]
#[CoversFunction('returnContactRejected')]
#[CoversFunction('returnContactSearchNoResults')]
#[CoversFunction('returnContactSearchResults')]
#[CoversFunction('returnNoWalletExists')]
#[CoversFunction('returnOverwritingExistingWallet')]
#[CoversFunction('returnOverwritingExistingWalletCancelled')]
#[CoversFunction('returnUserInputRequestOverwritingWallet')]
#[CoversFunction('returnWalletAlreadyExists')]
#[CoversFunction('returnWalletUpdatedSuccesfully')]
#[CoversFunction('returnHostnameSaved')]
#[CoversFunction('returnInstanceAlreadyRunning')]
#[CoversFunction('returnInvalidHostnameFormat')]
#[CoversFunction('returnLockfileCreation')]
#[CoversFunction('returnTorSaved')]
#[CoversFunction('returnInvalidSendRequest')]
#[CoversFunction('returnInvalidAmountSendRequest')]
#[CoversFunction('returnInvalidCurrencySendRequest')]
#[CoversFunction('returnNotProvidedCurrencySendRequest')]
class EchoSchemaTest extends TestCase
{
    // =========================================================================
    // Contact Echo Function Tests
    // =========================================================================

    /**
     * Test returnContactAccepted returns expected message
     */
    public function testReturnContactAcceptedReturnsExpectedMessage(): void
    {
        $result = returnContactAccepted();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('Accepted', $result);
    }

    /**
     * Test returnContactAcceptanceFailed returns expected message
     */
    public function testReturnContactAcceptanceFailedReturnsExpectedMessage(): void
    {
        $result = returnContactAcceptanceFailed();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('Failed', $result);
    }

    /**
     * Test returnContactAddInvalidInput returns help text
     */
    public function testReturnContactAddInvalidInputReturnsHelpText(): void
    {
        $result = returnContactAddInvalidInput();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('Invalid input', $result);
        $this->assertStringContainsString('Example command:', $result);
        $this->assertStringContainsString('eiou add', $result);
    }

    /**
     * Test returnContactCreationSuccessful returns expected message
     */
    public function testReturnContactCreationSuccessfulReturnsExpectedMessage(): void
    {
        $result = returnContactCreationSuccessful();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('Created successfully', $result);
    }

    /**
     * Test returnContactCreationFailed returns expected message
     */
    public function testReturnContactCreationFailedReturnsExpectedMessage(): void
    {
        $result = returnContactCreationFailed();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('Failed to create', $result);
    }

    /**
     * Test returnContactRequestAlreadyInserted returns warning message
     */
    public function testReturnContactRequestAlreadyInsertedReturnsWarningMessage(): void
    {
        $result = returnContactRequestAlreadyInserted();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('Warning', $result);
        $this->assertStringContainsString('resync', $result);
    }

    /**
     * Test returnContactCreationWarning includes custom message
     */
    public function testReturnContactCreationWarningIncludesCustomMessage(): void
    {
        $customMessage = 'Custom warning message';
        $result = returnContactCreationWarning($customMessage);

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('Warning', $result);
        $this->assertStringContainsString($customMessage, $result);
        $this->assertStringContainsString('deleted', $result);
    }

    /**
     * Test returnContactDeletedSuccesfully returns expected message
     */
    public function testReturnContactDeletedSuccesfullyReturnsExpectedMessage(): void
    {
        $result = returnContactDeletedSuccesfully();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('Deleted successfully', $result);
    }

    /**
     * Test returnContactExists returns expected message
     */
    public function testReturnContactExistsReturnsExpectedMessage(): void
    {
        $result = returnContactExists();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('Already added', $result);
    }

    /**
     * Test returnContactNotFound returns expected message
     */
    public function testReturnContactNotFoundReturnsExpectedMessage(): void
    {
        $result = returnContactNotFound();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('Not found', $result);
    }

    /**
     * Test returnContactNotFoundNoAction returns expected message
     */
    public function testReturnContactNotFoundNoActionReturnsExpectedMessage(): void
    {
        $result = returnContactNotFoundNoAction();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('Not found', $result);
        $this->assertStringContainsString('no action', $result);
    }

    /**
     * Test returnContactUpdate returns success message
     */
    public function testReturnContactUpdateReturnsSuccessMessage(): void
    {
        $result = returnContactUpdate();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('Updated successfully', $result);
    }

    /**
     * Test returnContactUpdateInvalidInput returns help text
     */
    public function testReturnContactUpdateInvalidInputReturnsHelpText(): void
    {
        $result = returnContactUpdateInvalidInput();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('Incorrect field', $result);
        $this->assertStringContainsString('Example command:', $result);
        $this->assertStringContainsString('name', $result);
        $this->assertStringContainsString('fee', $result);
        $this->assertStringContainsString('credit', $result);
    }

    /**
     * Test returnContactUpdateInvalidInputParameters returns detailed help
     */
    public function testReturnContactUpdateInvalidInputParametersReturnsDetailedHelp(): void
    {
        $result = returnContactUpdateInvalidInputParameters();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('Incorrect amount of parameters', $result);
        $this->assertStringContainsString('1 parameter', $result);
        $this->assertStringContainsString('3 parameters', $result);
    }

    /**
     * Test returnContactReadInvalidInput returns example command
     */
    public function testReturnContactReadInvalidInputReturnsExampleCommand(): void
    {
        $result = returnContactReadInvalidInput();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('Invalid input', $result);
        $this->assertStringContainsString('eiou viewcontact', $result);
    }

    /**
     * Test returnContactRejected includes data dump
     */
    public function testReturnContactRejectedIncludesDataDump(): void
    {
        $data = ['address' => 'test.onion', 'reason' => 'rejected'];
        $result = returnContactRejected($data);

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('not accepted', $result);
        $this->assertStringContainsString('test.onion', $result);
    }

    /**
     * Test returnContactSearchNoResults returns expected message
     */
    public function testReturnContactSearchNoResultsReturnsExpectedMessage(): void
    {
        $result = returnContactSearchNoResults();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('No contacts found', $result);
    }

    /**
     * Test returnContactSearchResults formats contact list
     */
    public function testReturnContactSearchResultsFormatsContactList(): void
    {
        $contacts = [
            [
                'http' => 'http://example.com',
                'tor' => 'http://example.onion',
                'name' => 'Test Contact',
                'fee_percent' => 100,
                'credit_limit' => 50000,
                'currency' => 'USD'
            ]
        ];

        $result = returnContactSearchResults($contacts);

        $this->assertIsString($result);
        $this->assertStringContainsString('[Contact]', $result);
        $this->assertStringContainsString('Search Results', $result);
        $this->assertStringContainsString('Test Contact', $result);
        $this->assertStringContainsString('Total contacts found: 1', $result);
    }

    // =========================================================================
    // Wallet Echo Function Tests
    // =========================================================================

    /**
     * Test returnNoWalletExists returns expected message
     */
    public function testReturnNoWalletExistsReturnsExpectedMessage(): void
    {
        $result = returnNoWalletExists();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Wallet]', $result);
        $this->assertStringContainsString('No wallet found', $result);
        $this->assertStringContainsString('eiou generate', $result);
        $this->assertStringContainsString('eiou restore', $result);
    }

    /**
     * Test returnOverwritingExistingWallet returns confirmation message
     */
    public function testReturnOverwritingExistingWalletReturnsConfirmationMessage(): void
    {
        $result = returnOverwritingExistingWallet();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Wallet]', $result);
        $this->assertStringContainsString('overwritten', $result);
    }

    /**
     * Test returnOverwritingExistingWalletCancelled returns cancellation message
     */
    public function testReturnOverwritingExistingWalletCancelledReturnsCancellationMessage(): void
    {
        $result = returnOverwritingExistingWalletCancelled();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Wallet]', $result);
        $this->assertStringContainsString('Will not be overwritten', $result);
    }

    /**
     * Test returnUserInputRequestOverwritingWallet returns warning prompt
     */
    public function testReturnUserInputRequestOverwritingWalletReturnsWarningPrompt(): void
    {
        $result = returnUserInputRequestOverwritingWallet();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Wallet]', $result);
        $this->assertStringContainsString('already exists', $result);
        $this->assertStringContainsString('WARNING', $result);
        $this->assertStringContainsString('irreversible', $result);
        $this->assertStringContainsString("'y'", $result);
    }

    /**
     * Test returnWalletAlreadyExists returns expected message
     */
    public function testReturnWalletAlreadyExistsReturnsExpectedMessage(): void
    {
        $result = returnWalletAlreadyExists();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Wallet]', $result);
        $this->assertStringContainsString('Already exists', $result);
    }

    /**
     * Test returnWalletUpdatedSuccesfully includes key name
     */
    public function testReturnWalletUpdatedSuccesfullyIncludesKeyName(): void
    {
        $result = returnWalletUpdatedSuccesfully('hostname');

        $this->assertIsString($result);
        $this->assertStringContainsString('[Wallet]', $result);
        $this->assertStringContainsString('hostname', $result);
        $this->assertStringContainsString('updated successfully', $result);
    }

    // =========================================================================
    // System Echo Function Tests
    // =========================================================================

    /**
     * Test returnHostnameSaved includes hostname
     */
    public function testReturnHostnameSavedIncludesHostname(): void
    {
        $hostname = 'https://example.com';
        $result = returnHostnameSaved($hostname);

        $this->assertIsString($result);
        $this->assertStringContainsString('[System]', $result);
        $this->assertStringContainsString('Hostname saved', $result);
        $this->assertStringContainsString($hostname, $result);
    }

    /**
     * Test returnInstanceAlreadyRunning returns expected message
     */
    public function testReturnInstanceAlreadyRunningReturnsExpectedMessage(): void
    {
        $result = returnInstanceAlreadyRunning();

        $this->assertIsString($result);
        $this->assertStringContainsString('[System]', $result);
        $this->assertStringContainsString('Another instance', $result);
        $this->assertStringContainsString('already running', $result);
    }

    /**
     * Test returnInvalidHostnameFormat returns expected message
     */
    public function testReturnInvalidHostnameFormatReturnsExpectedMessage(): void
    {
        $result = returnInvalidHostnameFormat();

        $this->assertIsString($result);
        $this->assertStringContainsString('[System]', $result);
        $this->assertStringContainsString('Invalid hostname format', $result);
    }

    /**
     * Test returnLockfileCreation includes path and PID
     */
    public function testReturnLockfileCreationIncludesPathAndPid(): void
    {
        $lockfile = '/var/run/eiou.lock';
        $pid = 12345;
        $result = returnLockfileCreation($lockfile, $pid);

        $this->assertIsString($result);
        $this->assertStringContainsString('[System]', $result);
        $this->assertStringContainsString($lockfile, $result);
        $this->assertStringContainsString((string)$pid, $result);
    }

    /**
     * Test returnTorSaved includes tor address
     */
    public function testReturnTorSavedIncludesTorAddress(): void
    {
        $torAddress = 'abc123xyz.onion';
        $result = returnTorSaved($torAddress);

        $this->assertIsString($result);
        $this->assertStringContainsString('[System]', $result);
        $this->assertStringContainsString('Tor saved', $result);
        $this->assertStringContainsString($torAddress, $result);
    }

    // =========================================================================
    // Transaction Echo Function Tests
    // =========================================================================

    /**
     * Test returnInvalidSendRequest returns usage help
     */
    public function testReturnInvalidSendRequestReturnsUsageHelp(): void
    {
        $result = returnInvalidSendRequest();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Transaction]', $result);
        $this->assertStringContainsString('Incorrect usage', $result);
        $this->assertStringContainsString('eiou send', $result);
        $this->assertStringContainsString('Example:', $result);
    }

    /**
     * Test returnInvalidAmountSendRequest returns expected message
     */
    public function testReturnInvalidAmountSendRequestReturnsExpectedMessage(): void
    {
        $result = returnInvalidAmountSendRequest();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Transaction]', $result);
        $this->assertStringContainsString('Invalid amount', $result);
        $this->assertStringContainsString('positive number', $result);
    }

    /**
     * Test returnInvalidCurrencySendRequest returns expected message
     */
    public function testReturnInvalidCurrencySendRequestReturnsExpectedMessage(): void
    {
        $result = returnInvalidCurrencySendRequest();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Transaction]', $result);
        $this->assertStringContainsString('Invalid currency', $result);
        $this->assertStringContainsString('3-letter', $result);
    }

    /**
     * Test returnNotProvidedCurrencySendRequest returns expected message
     */
    public function testReturnNotProvidedCurrencySendRequestReturnsExpectedMessage(): void
    {
        $result = returnNotProvidedCurrencySendRequest();

        $this->assertIsString($result);
        $this->assertStringContainsString('[Transaction]', $result);
        $this->assertStringContainsString('Currency not provided', $result);
    }

    // =========================================================================
    // Message Format Tests
    // =========================================================================

    /**
     * Test all messages end with newline
     */
    public function testAllMessagesEndWithNewline(): void
    {
        $messages = [
            returnContactAccepted(),
            returnContactAcceptanceFailed(),
            returnContactCreationSuccessful(),
            returnContactNotFound(),
            returnNoWalletExists(),
            returnInstanceAlreadyRunning(),
            returnInvalidSendRequest()
        ];

        foreach ($messages as $message) {
            $this->assertStringEndsWith("\n", $message, "Message should end with newline: {$message}");
        }
    }

    /**
     * Test all messages have category prefix
     */
    public function testAllMessagesHaveCategoryPrefix(): void
    {
        $messages = [
            returnContactAccepted() => '[Contact]',
            returnNoWalletExists() => '[Wallet]',
            returnInstanceAlreadyRunning() => '[System]',
            returnInvalidSendRequest() => '[Transaction]'
        ];

        foreach ($messages as $message => $prefix) {
            $this->assertStringContainsString($prefix, $message, "Message should contain prefix: {$prefix}");
        }
    }
}
