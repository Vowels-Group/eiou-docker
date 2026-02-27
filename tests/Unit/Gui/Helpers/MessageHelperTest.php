<?php
/**
 * Unit Tests for MessageHelper
 *
 * Tests message parsing, formatting, and display utilities.
 */

namespace Eiou\Tests\Gui\Helpers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Gui\Helpers\MessageHelper;

#[CoversClass(MessageHelper::class)]
class MessageHelperTest extends TestCase
{
    // ========================================================================
    // parseContactOutput() Tests
    // ========================================================================

    /**
     * Test parseContactOutput identifies contact accepted message
     */
    public function testParseContactOutputIdentifiesContactAccepted(): void
    {
        $result = MessageHelper::parseContactOutput('Contact accepted.');

        $this->assertEquals('Contact accepted.', $result['message']);
        $this->assertEquals('contact-accepted', $result['type']);
    }

    /**
     * Test parseContactOutput identifies contact accepted case insensitive
     */
    public function testParseContactOutputIdentifiesContactAcceptedCaseInsensitive(): void
    {
        $result = MessageHelper::parseContactOutput('CONTACT ACCEPTED.');

        $this->assertEquals('CONTACT ACCEPTED.', $result['message']);
        $this->assertEquals('contact-accepted', $result['type']);
    }

    /**
     * Test parseContactOutput identifies general success message
     */
    public function testParseContactOutputIdentifiesSuccessMessage(): void
    {
        $result = MessageHelper::parseContactOutput('Operation completed successfully');

        $this->assertEquals('Operation completed successfully', $result['message']);
        $this->assertEquals('success', $result['type']);
    }

    /**
     * Test parseContactOutput identifies already added warning
     */
    public function testParseContactOutputIdentifiesAlreadyAddedWarning(): void
    {
        $result = MessageHelper::parseContactOutput('Contact has already been added or accepted');

        $this->assertEquals('Contact has already been added or accepted', $result['message']);
        $this->assertEquals('warning', $result['type']);
    }

    /**
     * Test parseContactOutput identifies warning prefix
     */
    public function testParseContactOutputIdentifiesWarningPrefix(): void
    {
        $result = MessageHelper::parseContactOutput('Warning: something might be wrong');

        $this->assertEquals('Warning: something might be wrong', $result['message']);
        $this->assertEquals('warning', $result['type']);
    }

    /**
     * Test parseContactOutput identifies failed error and appends retry message
     */
    public function testParseContactOutputIdentifiesFailedError(): void
    {
        $result = MessageHelper::parseContactOutput('Operation failed');

        $this->assertEquals('Operation failed Please try again.', $result['message']);
        $this->assertEquals('error', $result['type']);
    }

    /**
     * Test parseContactOutput identifies not accepted error
     */
    public function testParseContactOutputIdentifiesNotAcceptedError(): void
    {
        $result = MessageHelper::parseContactOutput('Request was not accepted by the recipient');

        $this->assertStringContainsString('not accepted by the recipient', $result['message']);
        $this->assertStringContainsString('Please try again or contact the recipient directly.', $result['message']);
        $this->assertEquals('error', $result['type']);
    }

    /**
     * Test parseContactOutput identifies not found error
     */
    public function testParseContactOutputIdentifiesNotFoundError(): void
    {
        $result = MessageHelper::parseContactOutput('Contact not found');

        $this->assertEquals('Contact not found', $result['message']);
        $this->assertEquals('error', $result['type']);
    }

    /**
     * Test parseContactOutput identifies no results found error
     */
    public function testParseContactOutputIdentifiesNoResultsFoundError(): void
    {
        $result = MessageHelper::parseContactOutput('No results found.');

        $this->assertEquals('No results found.', $result['message']);
        $this->assertEquals('error', $result['type']);
    }

    /**
     * Test parseContactOutput identifies generic error message
     */
    public function testParseContactOutputIdentifiesGenericError(): void
    {
        $result = MessageHelper::parseContactOutput('Error occurred during processing');

        $this->assertEquals('Error occurred during processing', $result['message']);
        $this->assertEquals('error', $result['type']);
    }

    /**
     * Test parseContactOutput defaults to success for unknown messages
     */
    public function testParseContactOutputDefaultsToSuccessForUnknownMessages(): void
    {
        $result = MessageHelper::parseContactOutput('Some random message');

        $this->assertEquals('Some random message', $result['message']);
        $this->assertEquals('success', $result['type']);
    }

    /**
     * Test parseContactOutput trims whitespace
     */
    public function testParseContactOutputTrimsWhitespace(): void
    {
        $result = MessageHelper::parseContactOutput('   Contact accepted.   ');

        $this->assertEquals('Contact accepted.', $result['message']);
        $this->assertEquals('contact-accepted', $result['type']);
    }

    // ========================================================================
    // formatMessage() Tests
    // ========================================================================

    /**
     * Test formatMessage creates HTML div with correct class
     */
    public function testFormatMessageCreatesHtmlDivWithCorrectClass(): void
    {
        $result = MessageHelper::formatMessage('Test message', 'success');

        $this->assertStringContainsString('<div class="message message-success">', $result);
        $this->assertStringContainsString('Test message', $result);
        $this->assertStringContainsString('</div>', $result);
    }

    /**
     * Test formatMessage escapes HTML in message
     */
    public function testFormatMessageEscapesHtmlInMessage(): void
    {
        $result = MessageHelper::formatMessage('<script>alert("xss")</script>', 'info');

        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringNotContainsString('<script>', $result);
    }

    /**
     * Test formatMessage includes icon
     */
    public function testFormatMessageIncludesIcon(): void
    {
        $result = MessageHelper::formatMessage('Test', 'success');
        $this->assertStringContainsString('✓', $result);

        $result = MessageHelper::formatMessage('Test', 'error');
        $this->assertStringContainsString('✗', $result);

        $result = MessageHelper::formatMessage('Test', 'warning');
        $this->assertStringContainsString('⚠', $result);

        $result = MessageHelper::formatMessage('Test', 'info');
        $this->assertStringContainsString('ℹ', $result);
    }

    /**
     * Test formatMessage defaults to info type
     */
    public function testFormatMessageDefaultsToInfoType(): void
    {
        $result = MessageHelper::formatMessage('Test message');

        $this->assertStringContainsString('message-info', $result);
        $this->assertStringContainsString('ℹ', $result);
    }

    // ========================================================================
    // getMessageClass() Tests
    // ========================================================================

    /**
     * Test getMessageClass returns correct class for each type
     */
    public function testGetMessageClassReturnsCorrectClassForEachType(): void
    {
        $this->assertEquals('message-success', MessageHelper::getMessageClass('success'));
        $this->assertEquals('message-error', MessageHelper::getMessageClass('error'));
        $this->assertEquals('message-warning', MessageHelper::getMessageClass('warning'));
        $this->assertEquals('message-info', MessageHelper::getMessageClass('info'));
        $this->assertEquals('message-success', MessageHelper::getMessageClass('contact-accepted'));
    }

    /**
     * Test getMessageClass is case insensitive
     */
    public function testGetMessageClassIsCaseInsensitive(): void
    {
        $this->assertEquals('message-success', MessageHelper::getMessageClass('SUCCESS'));
        $this->assertEquals('message-error', MessageHelper::getMessageClass('ERROR'));
        $this->assertEquals('message-warning', MessageHelper::getMessageClass('Warning'));
    }

    /**
     * Test getMessageClass defaults to info for unknown types
     */
    public function testGetMessageClassDefaultsToInfoForUnknownTypes(): void
    {
        $this->assertEquals('message-info', MessageHelper::getMessageClass('unknown'));
        $this->assertEquals('message-info', MessageHelper::getMessageClass('custom'));
        $this->assertEquals('message-info', MessageHelper::getMessageClass(''));
    }

    // ========================================================================
    // getMessageIcon() Tests
    // ========================================================================

    /**
     * Test getMessageIcon returns correct icon for each type
     */
    public function testGetMessageIconReturnsCorrectIconForEachType(): void
    {
        $this->assertEquals('✓', MessageHelper::getMessageIcon('success'));
        $this->assertEquals('✗', MessageHelper::getMessageIcon('error'));
        $this->assertEquals('⚠', MessageHelper::getMessageIcon('warning'));
        $this->assertEquals('ℹ', MessageHelper::getMessageIcon('info'));
        $this->assertEquals('✓', MessageHelper::getMessageIcon('contact-accepted'));
    }

    /**
     * Test getMessageIcon is case insensitive
     */
    public function testGetMessageIconIsCaseInsensitive(): void
    {
        $this->assertEquals('✓', MessageHelper::getMessageIcon('SUCCESS'));
        $this->assertEquals('✗', MessageHelper::getMessageIcon('ERROR'));
        $this->assertEquals('⚠', MessageHelper::getMessageIcon('Warning'));
    }

    /**
     * Test getMessageIcon defaults to info icon for unknown types
     */
    public function testGetMessageIconDefaultsToInfoIconForUnknownTypes(): void
    {
        $this->assertEquals('ℹ', MessageHelper::getMessageIcon('unknown'));
        $this->assertEquals('ℹ', MessageHelper::getMessageIcon('custom'));
        $this->assertEquals('ℹ', MessageHelper::getMessageIcon(''));
    }

    // ========================================================================
    // sanitizeMessage() Tests
    // ========================================================================

    /**
     * Test sanitizeMessage strips HTML tags
     */
    public function testSanitizeMessageStripsHtmlTags(): void
    {
        $result = MessageHelper::sanitizeMessage('<p>Hello <b>World</b></p>');

        $this->assertEquals('Hello World', $result);
    }

    /**
     * Test sanitizeMessage strips script tags
     */
    public function testSanitizeMessageStripsScriptTags(): void
    {
        $result = MessageHelper::sanitizeMessage('<script>alert("xss")</script>Test');

        $this->assertEquals('alert("xss")Test', $result);
    }

    /**
     * Test sanitizeMessage trims whitespace
     */
    public function testSanitizeMessageTrimsWhitespace(): void
    {
        $result = MessageHelper::sanitizeMessage('   Hello World   ');

        $this->assertEquals('Hello World', $result);
    }

    /**
     * Test sanitizeMessage truncates long messages
     */
    public function testSanitizeMessageTruncatesLongMessages(): void
    {
        $longMessage = str_repeat('a', 600);
        $result = MessageHelper::sanitizeMessage($longMessage);

        $this->assertEquals(503, strlen($result)); // 500 + '...'
        $this->assertStringEndsWith('...', $result);
    }

    /**
     * Test sanitizeMessage respects custom max length
     */
    public function testSanitizeMessageRespectsCustomMaxLength(): void
    {
        $message = 'Hello World';
        $result = MessageHelper::sanitizeMessage($message, 5);

        $this->assertEquals('Hello...', $result);
    }

    /**
     * Test sanitizeMessage preserves messages within limit
     */
    public function testSanitizeMessagePreservesMessagesWithinLimit(): void
    {
        $message = 'Short message';
        $result = MessageHelper::sanitizeMessage($message);

        $this->assertEquals('Short message', $result);
    }

    // ========================================================================
    // successMessage() Tests
    // ========================================================================

    /**
     * Test successMessage creates formatted success message
     */
    public function testSuccessMessageCreatesFormattedSuccessMessage(): void
    {
        $result = MessageHelper::successMessage('created', 'Contact');

        $this->assertEquals('Contact created successfully', $result);
    }

    /**
     * Test successMessage with different actions
     */
    public function testSuccessMessageWithDifferentActions(): void
    {
        $this->assertEquals('User deleted successfully', MessageHelper::successMessage('deleted', 'User'));
        $this->assertEquals('Settings updated successfully', MessageHelper::successMessage('updated', 'Settings'));
        $this->assertEquals('File uploaded successfully', MessageHelper::successMessage('uploaded', 'File'));
    }

    // ========================================================================
    // errorMessage() Tests
    // ========================================================================

    /**
     * Test errorMessage creates formatted error message without reason
     */
    public function testErrorMessageCreatesFormattedErrorMessageWithoutReason(): void
    {
        $result = MessageHelper::errorMessage('create', 'contact');

        $this->assertEquals('Failed to create contact', $result);
    }

    /**
     * Test errorMessage creates formatted error message with reason
     */
    public function testErrorMessageCreatesFormattedErrorMessageWithReason(): void
    {
        $result = MessageHelper::errorMessage('create', 'contact', 'Invalid address');

        $this->assertEquals('Failed to create contact: Invalid address', $result);
    }

    /**
     * Test errorMessage with null reason
     */
    public function testErrorMessageWithNullReason(): void
    {
        $result = MessageHelper::errorMessage('update', 'user', null);

        $this->assertEquals('Failed to update user', $result);
    }

    // ========================================================================
    // warningMessage() Tests
    // ========================================================================

    /**
     * Test warningMessage prepends warning prefix
     */
    public function testWarningMessagePrependsWarningPrefix(): void
    {
        $result = MessageHelper::warningMessage('This action cannot be undone');

        $this->assertEquals('Warning: This action cannot be undone', $result);
    }

    // ========================================================================
    // renderMessageList() Tests
    // ========================================================================

    /**
     * Test renderMessageList renders multiple messages
     */
    public function testRenderMessageListRendersMultipleMessages(): void
    {
        $messages = [
            ['message' => 'First message', 'type' => 'success'],
            ['message' => 'Second message', 'type' => 'error'],
            ['message' => 'Third message', 'type' => 'warning'],
        ];

        $result = MessageHelper::renderMessageList($messages);

        $this->assertStringContainsString('<div class="message-list">', $result);
        $this->assertStringContainsString('First message', $result);
        $this->assertStringContainsString('Second message', $result);
        $this->assertStringContainsString('Third message', $result);
        $this->assertStringContainsString('message-success', $result);
        $this->assertStringContainsString('message-error', $result);
        $this->assertStringContainsString('message-warning', $result);
        $this->assertStringContainsString('</div>', $result);
    }

    /**
     * Test renderMessageList returns empty string for empty array
     */
    public function testRenderMessageListReturnsEmptyStringForEmptyArray(): void
    {
        $result = MessageHelper::renderMessageList([]);

        $this->assertEquals('', $result);
    }

    /**
     * Test renderMessageList with single message
     */
    public function testRenderMessageListWithSingleMessage(): void
    {
        $messages = [
            ['message' => 'Only message', 'type' => 'info'],
        ];

        $result = MessageHelper::renderMessageList($messages);

        $this->assertStringContainsString('Only message', $result);
        $this->assertStringContainsString('message-info', $result);
    }

    // ========================================================================
    // isErrorMessage() Tests
    // ========================================================================

    /**
     * Test isErrorMessage returns true for error types
     */
    public function testIsErrorMessageReturnsTrueForErrorTypes(): void
    {
        $this->assertTrue(MessageHelper::isErrorMessage('error'));
        $this->assertTrue(MessageHelper::isErrorMessage('danger'));
        $this->assertTrue(MessageHelper::isErrorMessage('failed'));
    }

    /**
     * Test isErrorMessage is case insensitive
     */
    public function testIsErrorMessageIsCaseInsensitive(): void
    {
        $this->assertTrue(MessageHelper::isErrorMessage('ERROR'));
        $this->assertTrue(MessageHelper::isErrorMessage('Danger'));
        $this->assertTrue(MessageHelper::isErrorMessage('FAILED'));
    }

    /**
     * Test isErrorMessage returns false for non-error types
     */
    public function testIsErrorMessageReturnsFalseForNonErrorTypes(): void
    {
        $this->assertFalse(MessageHelper::isErrorMessage('success'));
        $this->assertFalse(MessageHelper::isErrorMessage('warning'));
        $this->assertFalse(MessageHelper::isErrorMessage('info'));
        $this->assertFalse(MessageHelper::isErrorMessage('unknown'));
    }

    // ========================================================================
    // isSuccessMessage() Tests
    // ========================================================================

    /**
     * Test isSuccessMessage returns true for success types
     */
    public function testIsSuccessMessageReturnsTrueForSuccessTypes(): void
    {
        $this->assertTrue(MessageHelper::isSuccessMessage('success'));
        $this->assertTrue(MessageHelper::isSuccessMessage('contact-accepted'));
        $this->assertTrue(MessageHelper::isSuccessMessage('completed'));
    }

    /**
     * Test isSuccessMessage is case insensitive
     */
    public function testIsSuccessMessageIsCaseInsensitive(): void
    {
        $this->assertTrue(MessageHelper::isSuccessMessage('SUCCESS'));
        $this->assertTrue(MessageHelper::isSuccessMessage('CONTACT-ACCEPTED'));
        $this->assertTrue(MessageHelper::isSuccessMessage('Completed'));
    }

    /**
     * Test isSuccessMessage returns false for non-success types
     */
    public function testIsSuccessMessageReturnsFalseForNonSuccessTypes(): void
    {
        $this->assertFalse(MessageHelper::isSuccessMessage('error'));
        $this->assertFalse(MessageHelper::isSuccessMessage('warning'));
        $this->assertFalse(MessageHelper::isSuccessMessage('info'));
        $this->assertFalse(MessageHelper::isSuccessMessage('unknown'));
    }

    // ========================================================================
    // parseCliJsonOutput() Tests
    // ========================================================================

    /**
     * Test parseCliJsonOutput handles empty output
     */
    public function testParseCliJsonOutputHandlesEmptyOutput(): void
    {
        $result = MessageHelper::parseCliJsonOutput('');

        $this->assertEquals('No response received', $result['message']);
        $this->assertEquals('error', $result['type']);
        $this->assertNull($result['code']);
        $this->assertNull($result['data']);
    }

    /**
     * Test parseCliJsonOutput handles whitespace-only output
     */
    public function testParseCliJsonOutputHandlesWhitespaceOnlyOutput(): void
    {
        $result = MessageHelper::parseCliJsonOutput('   ');

        $this->assertEquals('No response received', $result['message']);
        $this->assertEquals('error', $result['type']);
    }

    /**
     * Test parseCliJsonOutput parses success JSON response
     */
    public function testParseCliJsonOutputParsesSuccessJsonResponse(): void
    {
        $json = json_encode([
            'success' => true,
            'message' => 'Contact created',
            'data' => ['status' => 'accepted', 'contact_id' => '123']
        ]);

        $result = MessageHelper::parseCliJsonOutput($json);

        $this->assertEquals('Contact created', $result['message']);
        $this->assertEquals('contact-accepted', $result['type']);
        $this->assertNull($result['code']);
        $this->assertEquals(['status' => 'accepted', 'contact_id' => '123'], $result['data']);
    }

    /**
     * Test parseCliJsonOutput parses success JSON with default message
     */
    public function testParseCliJsonOutputParsesSuccessJsonWithDefaultMessage(): void
    {
        $json = json_encode([
            'success' => true,
            'data' => ['status' => 'success']
        ]);

        $result = MessageHelper::parseCliJsonOutput($json);

        $this->assertEquals('Operation completed successfully', $result['message']);
        $this->assertEquals('success', $result['type']);
    }

    /**
     * Test parseCliJsonOutput parses error JSON response
     */
    public function testParseCliJsonOutputParsesErrorJsonResponse(): void
    {
        $json = json_encode([
            'success' => false,
            'message' => 'Contact creation failed',
            'error' => [
                'code' => 'INVALID_ADDRESS',
                'detail' => 'Address format is invalid'
            ]
        ]);

        $result = MessageHelper::parseCliJsonOutput($json);

        $this->assertEquals('The address you entered is not valid. Please check and try again.', $result['message']);
        $this->assertEquals('error', $result['type']);
        $this->assertEquals('INVALID_ADDRESS', $result['code']);
    }

    /**
     * Test parseCliJsonOutput handles error response with error key directly
     */
    public function testParseCliJsonOutputHandlesErrorKeyDirectly(): void
    {
        $json = json_encode([
            'error' => [
                'code' => 'CONTACT_NOT_FOUND',
                'detail' => 'Contact does not exist'
            ]
        ]);

        $result = MessageHelper::parseCliJsonOutput($json);

        $this->assertEquals('Contact not found. It may have been deleted.', $result['message']);
        $this->assertEquals('error', $result['type']);
        $this->assertEquals('CONTACT_NOT_FOUND', $result['code']);
    }

    /**
     * Test parseCliJsonOutput falls back to legacy parsing for invalid JSON
     */
    public function testParseCliJsonOutputFallsBackToLegacyParsingForInvalidJson(): void
    {
        $result = MessageHelper::parseCliJsonOutput('Contact accepted.');

        $this->assertEquals('Contact accepted.', $result['message']);
        $this->assertEquals('contact-accepted', $result['type']);
    }

    /**
     * Test parseCliJsonOutput maps different statuses to message types
     */
    public function testParseCliJsonOutputMapsDifferentStatusesToMessageTypes(): void
    {
        $statuses = [
            'success' => 'success',
            'accepted' => 'contact-accepted',
            'pending' => 'info',
            'blocked' => 'success',
            'updated' => 'success',
            'unblocked' => 'success',
            'deleted' => 'success',
            'synced' => 'success',
            'sent' => 'success',
            'completed' => 'success',
        ];

        foreach ($statuses as $status => $expectedType) {
            $json = json_encode([
                'success' => true,
                'data' => ['status' => $status]
            ]);

            $result = MessageHelper::parseCliJsonOutput($json);
            $this->assertEquals($expectedType, $result['type'], "Status '$status' should map to type '$expectedType'");
        }
    }

    // ========================================================================
    // getGuiFriendlyMessage() Tests
    // ========================================================================

    /**
     * Test getGuiFriendlyMessage returns friendly message for known error codes
     */
    public function testGetGuiFriendlyMessageReturnsFriendlyMessageForKnownErrorCodes(): void
    {
        $this->assertEquals(
            'The address you entered is not valid. Please check and try again.',
            MessageHelper::getGuiFriendlyMessage('INVALID_ADDRESS', '')
        );

        $this->assertEquals(
            'This contact already exists in your contact list.',
            MessageHelper::getGuiFriendlyMessage('CONTACT_EXISTS', '')
        );

        $this->assertEquals(
            'You cannot add yourself as a contact.',
            MessageHelper::getGuiFriendlyMessage('SELF_CONTACT', '')
        );

        $this->assertEquals(
            'You cannot send transactions to yourself. Please enter a different recipient address.',
            MessageHelper::getGuiFriendlyMessage('SELF_SEND', '')
        );

        $this->assertEquals(
            'You need at least one contact to send transactions. Please add a contact first.',
            MessageHelper::getGuiFriendlyMessage('NO_CONTACTS', '')
        );
    }

    /**
     * Test getGuiFriendlyMessage returns detail for unknown error codes
     */
    public function testGetGuiFriendlyMessageReturnsDetailForUnknownErrorCodes(): void
    {
        $this->assertEquals(
            'Some custom error detail',
            MessageHelper::getGuiFriendlyMessage('UNKNOWN_CODE', 'Some custom error detail')
        );
    }

    /**
     * Test getGuiFriendlyMessage returns fallback for unknown code with empty detail
     */
    public function testGetGuiFriendlyMessageReturnsFallbackForUnknownCodeWithEmptyDetail(): void
    {
        $this->assertEquals(
            'An error occurred while processing your request.',
            MessageHelper::getGuiFriendlyMessage('UNKNOWN_CODE', '')
        );
    }

    /**
     * Test getGuiFriendlyMessage returns friendly message for RATE_LIMIT_EXCEEDED
     */
    public function testGetGuiFriendlyMessageForRateLimitExceeded(): void
    {
        $this->assertEquals(
            'Too many requests. Please wait a moment before trying again.',
            MessageHelper::getGuiFriendlyMessage('RATE_LIMIT_EXCEEDED', '')
        );
    }

    /**
     * Test getGuiFriendlyMessage returns friendly message for TRANSACTION_IN_PROGRESS
     */
    public function testGetGuiFriendlyMessageForTransactionInProgress(): void
    {
        $this->assertEquals(
            'Another transaction to this contact is already in progress. Please wait for it to complete.',
            MessageHelper::getGuiFriendlyMessage('TRANSACTION_IN_PROGRESS', '')
        );
    }

    /**
     * Test getGuiFriendlyMessage covers all documented error codes
     */
    public function testGetGuiFriendlyMessageCoversAllDocumentedErrorCodes(): void
    {
        $errorCodes = [
            'INVALID_ADDRESS',
            'INVALID_NAME',
            'INVALID_FEE',
            'INVALID_CREDIT',
            'INVALID_CURRENCY',
            'INVALID_AMOUNT',
            'INVALID_RECIPIENT',
            'INVALID_PARAMS',
            'SELF_CONTACT',
            'SELF_SEND',
            'CONTACT_EXISTS',
            'CONTACT_NOT_FOUND',
            'CONTACT_BLOCKED',
            'CONTACT_REJECTED',
            'CONTACT_UNREACHABLE',
            'ACCEPT_FAILED',
            'BLOCK_FAILED',
            'UNBLOCK_FAILED',
            'DELETE_FAILED',
            'UPDATE_FAILED',
            'CONTACT_CREATE_FAILED',
            'NO_CONTACTS',
            'INSUFFICIENT_FUNDS',
            'NO_VIABLE_TRANSPORT',
            'NO_VIABLE_ROUTE',
            'P2P_CANCELLED',
            'MISSING_IDENTIFIER',
            'MISSING_ADDRESS',
            'MISSING_PARAMS',
            'NO_ADDRESS',
            'RATE_LIMIT_EXCEEDED',
            'TRANSACTION_IN_PROGRESS',
            'GENERAL_ERROR',
            'VALIDATION_ERROR',
        ];

        foreach ($errorCodes as $code) {
            $message = MessageHelper::getGuiFriendlyMessage($code, '');
            // Should not return the fallback message for known codes
            $this->assertNotEquals(
                'An error occurred while processing your request.',
                $message,
                "Error code '$code' should have a friendly message"
            );
        }
    }

    // ========================================================================
    // getMessageFromUrl() Tests - Limited testing due to $_GET dependency
    // ========================================================================

    /**
     * Test getMessageFromUrl returns null when no parameters
     */
    public function testGetMessageFromUrlReturnsNullWhenNoParameters(): void
    {
        // Clear any existing GET parameters
        $_GET = [];

        $result = MessageHelper::getMessageFromUrl();

        $this->assertNull($result);
    }

    /**
     * Test getMessageFromUrl returns null when only message parameter
     */
    public function testGetMessageFromUrlReturnsNullWhenOnlyMessageParameter(): void
    {
        $_GET = ['message' => 'Test'];

        $result = MessageHelper::getMessageFromUrl();

        $this->assertNull($result);
    }

    /**
     * Test getMessageFromUrl returns null when only type parameter
     */
    public function testGetMessageFromUrlReturnsNullWhenOnlyTypeParameter(): void
    {
        $_GET = ['type' => 'success'];

        $result = MessageHelper::getMessageFromUrl();

        $this->assertNull($result);
    }

    /**
     * Test getMessageFromUrl returns sanitized parameters
     */
    public function testGetMessageFromUrlReturnsSanitizedParameters(): void
    {
        $_GET = [
            'message' => '<script>alert("xss")</script>',
            'type' => 'success<tag>'
        ];

        $result = MessageHelper::getMessageFromUrl();

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('<script>', $result['message']);
        $this->assertStringNotContainsString('<tag>', $result['type']);
    }

    /**
     * Test getMessageFromUrl returns correct values
     */
    public function testGetMessageFromUrlReturnsCorrectValues(): void
    {
        $_GET = [
            'message' => 'Test message',
            'type' => 'success'
        ];

        $result = MessageHelper::getMessageFromUrl();

        $this->assertNotNull($result);
        $this->assertEquals('Test message', $result['message']);
        $this->assertEquals('success', $result['type']);
    }

    // ========================================================================
    // displayFlashMessage() Tests
    // ========================================================================

    /**
     * Test displayFlashMessage returns session message when available
     */
    public function testDisplayFlashMessageReturnsSessionMessageWhenAvailable(): void
    {
        $mockSession = $this->createMock(MockSession::class);
        $mockSession->method('getMessage')
            ->willReturn(['text' => 'Session message', 'type' => 'success']);

        $result = MessageHelper::displayFlashMessage($mockSession);

        $this->assertStringContainsString('Session message', $result);
        $this->assertStringContainsString('message-success', $result);
    }

    /**
     * Test displayFlashMessage falls back to URL message when session empty
     */
    public function testDisplayFlashMessageFallsBackToUrlMessageWhenSessionEmpty(): void
    {
        $_GET = [
            'message' => 'URL message',
            'type' => 'warning'
        ];

        $mockSession = $this->createMock(MockSession::class);
        $mockSession->method('getMessage')->willReturn(null);

        $result = MessageHelper::displayFlashMessage($mockSession);

        $this->assertStringContainsString('URL message', $result);
        $this->assertStringContainsString('message-warning', $result);
    }

    /**
     * Test displayFlashMessage returns empty string when no messages
     */
    public function testDisplayFlashMessageReturnsEmptyStringWhenNoMessages(): void
    {
        $_GET = [];

        $mockSession = $this->createMock(MockSession::class);
        $mockSession->method('getMessage')->willReturn(null);

        $result = MessageHelper::displayFlashMessage($mockSession);

        $this->assertEquals('', $result);
    }

    /**
     * Clean up after URL-related tests
     */
    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }
}

/**
 * Mock Session interface for testing displayFlashMessage
 */
interface MockSession
{
    public function getMessage(): ?array;
}
