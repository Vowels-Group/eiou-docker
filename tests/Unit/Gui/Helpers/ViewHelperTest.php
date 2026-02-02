<?php
/**
 * Unit Tests for ViewHelper
 *
 * Tests view rendering utility functions including sanitization,
 * formatting, and HTML generation.
 */

namespace Eiou\Tests\Gui\Helpers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Gui\Helpers\ViewHelper;

#[CoversClass(ViewHelper::class)]
class ViewHelperTest extends TestCase
{
    // =========================================================================
    // sanitize() tests
    // =========================================================================

    /**
     * Test sanitize escapes HTML special characters
     */
    public function testSanitizeEscapesHtmlSpecialCharacters(): void
    {
        $input = '<script>alert("XSS")</script>';
        $expected = '&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;';

        $this->assertEquals($expected, ViewHelper::sanitize($input));
    }

    /**
     * Test sanitize escapes ampersands
     */
    public function testSanitizeEscapesAmpersands(): void
    {
        $input = 'foo & bar';
        $expected = 'foo &amp; bar';

        $this->assertEquals($expected, ViewHelper::sanitize($input));
    }

    /**
     * Test sanitize escapes single quotes
     */
    public function testSanitizeEscapesSingleQuotes(): void
    {
        $input = "It's a test";
        $expected = "It&#039;s a test";

        $this->assertEquals($expected, ViewHelper::sanitize($input));
    }

    /**
     * Test sanitize escapes double quotes
     */
    public function testSanitizeEscapesDoubleQuotes(): void
    {
        $input = 'He said "hello"';
        $expected = 'He said &quot;hello&quot;';

        $this->assertEquals($expected, ViewHelper::sanitize($input));
    }

    /**
     * Test sanitize handles empty string
     */
    public function testSanitizeHandlesEmptyString(): void
    {
        $this->assertEquals('', ViewHelper::sanitize(''));
    }

    /**
     * Test sanitize preserves safe text
     */
    public function testSanitizePreservesSafeText(): void
    {
        $input = 'Hello World 123';
        $this->assertEquals($input, ViewHelper::sanitize($input));
    }

    /**
     * Test sanitize handles UTF-8 characters
     */
    public function testSanitizeHandlesUtf8Characters(): void
    {
        $input = 'Caf\u00e9 \u00fcber';
        $this->assertEquals($input, ViewHelper::sanitize($input));
    }

    // =========================================================================
    // formatTimestamp() tests
    // =========================================================================

    /**
     * Test formatTimestamp with default format
     */
    public function testFormatTimestampWithDefaultFormat(): void
    {
        $timestamp = '2025-06-15 14:30:00';
        $expected = '2025-06-15 14:30:00';

        $this->assertEquals($expected, ViewHelper::formatTimestamp($timestamp));
    }

    /**
     * Test formatTimestamp with custom format
     */
    public function testFormatTimestampWithCustomFormat(): void
    {
        $timestamp = '2025-06-15 14:30:00';
        $format = 'd/m/Y';
        $expected = '15/06/2025';

        $this->assertEquals($expected, ViewHelper::formatTimestamp($timestamp, $format));
    }

    /**
     * Test formatTimestamp with date only format
     */
    public function testFormatTimestampWithDateOnlyFormat(): void
    {
        $timestamp = '2025-12-25 00:00:00';
        $format = 'Y-m-d';
        $expected = '2025-12-25';

        $this->assertEquals($expected, ViewHelper::formatTimestamp($timestamp, $format));
    }

    /**
     * Test formatTimestamp with time only format
     */
    public function testFormatTimestampWithTimeOnlyFormat(): void
    {
        $timestamp = '2025-06-15 09:45:30';
        $format = 'H:i:s';
        $expected = '09:45:30';

        $this->assertEquals($expected, ViewHelper::formatTimestamp($timestamp, $format));
    }

    /**
     * Test formatTimestamp returns original on invalid timestamp
     */
    public function testFormatTimestampReturnsOriginalOnInvalidTimestamp(): void
    {
        $invalidTimestamp = 'not-a-timestamp';

        $this->assertEquals($invalidTimestamp, ViewHelper::formatTimestamp($invalidTimestamp));
    }

    /**
     * Test formatTimestamp handles empty string
     */
    public function testFormatTimestampHandlesEmptyString(): void
    {
        $this->assertEquals('', ViewHelper::formatTimestamp(''));
    }

    /**
     * Test formatTimestamp with Unix timestamp string
     */
    public function testFormatTimestampWithUnixTimestampString(): void
    {
        $timestamp = '@1718451000'; // Unix timestamp format
        $result = ViewHelper::formatTimestamp($timestamp);

        // Should parse and format the Unix timestamp
        $this->assertNotEquals($timestamp, $result);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result);
    }

    /**
     * Test formatTimestamp with relative date string
     */
    public function testFormatTimestampWithRelativeDateString(): void
    {
        $timestamp = 'yesterday';
        $result = ViewHelper::formatTimestamp($timestamp);

        // Should parse and return a valid formatted date
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result);
    }

    // =========================================================================
    // getTransactionClass() tests
    // =========================================================================

    /**
     * Test getTransactionClass returns correct class for send
     */
    public function testGetTransactionClassReturnsSendClass(): void
    {
        $this->assertEquals('transaction-send', ViewHelper::getTransactionClass('send'));
    }

    /**
     * Test getTransactionClass returns correct class for sent
     */
    public function testGetTransactionClassReturnsSentAsSendClass(): void
    {
        $this->assertEquals('transaction-send', ViewHelper::getTransactionClass('sent'));
    }

    /**
     * Test getTransactionClass returns correct class for receive
     */
    public function testGetTransactionClassReturnsReceiveClass(): void
    {
        $this->assertEquals('transaction-receive', ViewHelper::getTransactionClass('receive'));
    }

    /**
     * Test getTransactionClass returns correct class for received
     */
    public function testGetTransactionClassReturnsReceivedAsReceiveClass(): void
    {
        $this->assertEquals('transaction-receive', ViewHelper::getTransactionClass('received'));
    }

    /**
     * Test getTransactionClass returns correct class for p2p
     */
    public function testGetTransactionClassReturnsP2pClass(): void
    {
        $this->assertEquals('transaction-p2p', ViewHelper::getTransactionClass('p2p'));
    }

    /**
     * Test getTransactionClass is case-insensitive
     */
    public function testGetTransactionClassIsCaseInsensitive(): void
    {
        $this->assertEquals('transaction-send', ViewHelper::getTransactionClass('SEND'));
        $this->assertEquals('transaction-receive', ViewHelper::getTransactionClass('Receive'));
        $this->assertEquals('transaction-p2p', ViewHelper::getTransactionClass('P2P'));
    }

    /**
     * Test getTransactionClass returns default for unknown type
     */
    public function testGetTransactionClassReturnsDefaultForUnknownType(): void
    {
        $this->assertEquals('transaction-default', ViewHelper::getTransactionClass('unknown'));
        $this->assertEquals('transaction-default', ViewHelper::getTransactionClass('transfer'));
        $this->assertEquals('transaction-default', ViewHelper::getTransactionClass(''));
    }

    // =========================================================================
    // getStatusBadgeClass() tests
    // =========================================================================

    /**
     * Test getStatusBadgeClass returns success for accepted
     */
    public function testGetStatusBadgeClassReturnsSuccessForAccepted(): void
    {
        $this->assertEquals('badge-success', ViewHelper::getStatusBadgeClass('accepted'));
    }

    /**
     * Test getStatusBadgeClass returns warning for pending
     */
    public function testGetStatusBadgeClassReturnsWarningForPending(): void
    {
        $this->assertEquals('badge-warning', ViewHelper::getStatusBadgeClass('pending'));
    }

    /**
     * Test getStatusBadgeClass returns danger for blocked
     */
    public function testGetStatusBadgeClassReturnsDangerForBlocked(): void
    {
        $this->assertEquals('badge-danger', ViewHelper::getStatusBadgeClass('blocked'));
    }

    /**
     * Test getStatusBadgeClass returns danger for rejected
     */
    public function testGetStatusBadgeClassReturnsDangerForRejected(): void
    {
        $this->assertEquals('badge-danger', ViewHelper::getStatusBadgeClass('rejected'));
    }

    /**
     * Test getStatusBadgeClass is case-insensitive
     */
    public function testGetStatusBadgeClassIsCaseInsensitive(): void
    {
        $this->assertEquals('badge-success', ViewHelper::getStatusBadgeClass('ACCEPTED'));
        $this->assertEquals('badge-warning', ViewHelper::getStatusBadgeClass('Pending'));
        $this->assertEquals('badge-danger', ViewHelper::getStatusBadgeClass('BLOCKED'));
    }

    /**
     * Test getStatusBadgeClass returns default for unknown status
     */
    public function testGetStatusBadgeClassReturnsDefaultForUnknownStatus(): void
    {
        $this->assertEquals('badge-default', ViewHelper::getStatusBadgeClass('unknown'));
        $this->assertEquals('badge-default', ViewHelper::getStatusBadgeClass('active'));
        $this->assertEquals('badge-default', ViewHelper::getStatusBadgeClass(''));
    }

    // =========================================================================
    // generatePagination() tests
    // =========================================================================

    /**
     * Test generatePagination returns empty string for single page
     */
    public function testGeneratePaginationReturnsEmptyForSinglePage(): void
    {
        $this->assertEquals('', ViewHelper::generatePagination(1, 1, '/page'));
    }

    /**
     * Test generatePagination returns empty string for zero pages
     */
    public function testGeneratePaginationReturnsEmptyForZeroPages(): void
    {
        $this->assertEquals('', ViewHelper::generatePagination(1, 0, '/page'));
    }

    /**
     * Test generatePagination generates correct structure for multiple pages
     */
    public function testGeneratePaginationGeneratesCorrectStructure(): void
    {
        $result = ViewHelper::generatePagination(2, 3, '/test');

        $this->assertStringContainsString('<div class="pagination">', $result);
        $this->assertStringContainsString('</div>', $result);
    }

    /**
     * Test generatePagination shows all page numbers
     */
    public function testGeneratePaginationShowsAllPageNumbers(): void
    {
        $result = ViewHelper::generatePagination(1, 5, '/page');

        for ($i = 1; $i <= 5; $i++) {
            $this->assertStringContainsString('&page=' . $i, $result);
        }
    }

    /**
     * Test generatePagination marks current page as active
     */
    public function testGeneratePaginationMarksCurrentPageAsActive(): void
    {
        $result = ViewHelper::generatePagination(3, 5, '/page');

        $this->assertStringContainsString('class="active">3</a>', $result);
    }

    /**
     * Test generatePagination shows previous button when not on first page
     */
    public function testGeneratePaginationShowsPreviousButtonWhenNotOnFirstPage(): void
    {
        $result = ViewHelper::generatePagination(3, 5, '/page');

        $this->assertStringContainsString('&laquo; Previous', $result);
        $this->assertStringContainsString('&page=2', $result);
    }

    /**
     * Test generatePagination hides previous button on first page
     */
    public function testGeneratePaginationHidesPreviousButtonOnFirstPage(): void
    {
        $result = ViewHelper::generatePagination(1, 5, '/page');

        $this->assertStringNotContainsString('&laquo; Previous', $result);
    }

    /**
     * Test generatePagination shows next button when not on last page
     */
    public function testGeneratePaginationShowsNextButtonWhenNotOnLastPage(): void
    {
        $result = ViewHelper::generatePagination(3, 5, '/page');

        $this->assertStringContainsString('Next &raquo;', $result);
        $this->assertStringContainsString('&page=4', $result);
    }

    /**
     * Test generatePagination hides next button on last page
     */
    public function testGeneratePaginationHidesNextButtonOnLastPage(): void
    {
        $result = ViewHelper::generatePagination(5, 5, '/page');

        $this->assertStringNotContainsString('Next &raquo;', $result);
    }

    /**
     * Test generatePagination escapes baseUrl for XSS prevention
     */
    public function testGeneratePaginationEscapesBaseUrlForXssPrevention(): void
    {
        $maliciousUrl = '/page?foo=<script>alert(1)</script>';
        $result = ViewHelper::generatePagination(1, 2, $maliciousUrl);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    /**
     * Test generatePagination handles URL with existing query parameters
     */
    public function testGeneratePaginationHandlesUrlWithExistingQueryParameters(): void
    {
        $result = ViewHelper::generatePagination(1, 3, '/list?sort=name');

        // Note: The implementation appends &page=N (the baseUrl is escaped but generated params use raw &)
        $this->assertStringContainsString('/list?sort=name&page=1', $result);
        $this->assertStringContainsString('/list?sort=name&page=2', $result);
    }

    // =========================================================================
    // renderSelectOptions() tests
    // =========================================================================

    /**
     * Test renderSelectOptions generates correct option tags
     */
    public function testRenderSelectOptionsGeneratesCorrectOptionTags(): void
    {
        $options = [
            'opt1' => 'Option 1',
            'opt2' => 'Option 2'
        ];

        $result = ViewHelper::renderSelectOptions($options);

        $this->assertStringContainsString('<option value="opt1">Option 1</option>', $result);
        $this->assertStringContainsString('<option value="opt2">Option 2</option>', $result);
    }

    /**
     * Test renderSelectOptions marks selected option
     */
    public function testRenderSelectOptionsMarksSelectedOption(): void
    {
        $options = [
            'a' => 'Alpha',
            'b' => 'Beta',
            'c' => 'Gamma'
        ];

        $result = ViewHelper::renderSelectOptions($options, 'b');

        $this->assertStringContainsString('<option value="b" selected>Beta</option>', $result);
        $this->assertStringNotContainsString('<option value="a" selected>', $result);
        $this->assertStringNotContainsString('<option value="c" selected>', $result);
    }

    /**
     * Test renderSelectOptions handles null selected value
     */
    public function testRenderSelectOptionsHandlesNullSelectedValue(): void
    {
        $options = ['x' => 'X Value'];

        $result = ViewHelper::renderSelectOptions($options, null);

        $this->assertStringContainsString('<option value="x">X Value</option>', $result);
        $this->assertStringNotContainsString('selected', $result);
    }

    /**
     * Test renderSelectOptions returns empty string for empty options
     */
    public function testRenderSelectOptionsReturnsEmptyForEmptyOptions(): void
    {
        $this->assertEquals('', ViewHelper::renderSelectOptions([]));
    }

    /**
     * Test renderSelectOptions sanitizes values and labels
     */
    public function testRenderSelectOptionsSanitizesValuesAndLabels(): void
    {
        $options = [
            '<script>' => 'Malicious <b>Label</b>'
        ];

        $result = ViewHelper::renderSelectOptions($options);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('<b>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringContainsString('&lt;b&gt;', $result);
    }

    /**
     * Test renderSelectOptions handles numeric keys
     */
    public function testRenderSelectOptionsHandlesNumericKeys(): void
    {
        $options = [
            0 => 'Zero',
            1 => 'One',
            2 => 'Two'
        ];

        // Note: The implementation uses strict comparison (===), so numeric key 1
        // must be compared with numeric value 1, not string '1'
        $result = ViewHelper::renderSelectOptions($options);

        $this->assertStringContainsString('<option value="0">Zero</option>', $result);
        $this->assertStringContainsString('<option value="1">One</option>', $result);
        $this->assertStringContainsString('<option value="2">Two</option>', $result);
    }

    /**
     * Test renderSelectOptions marks selected option with matching type
     */
    public function testRenderSelectOptionsMarksSelectedWithMatchingType(): void
    {
        $options = [
            'a' => 'Alpha',
            'b' => 'Beta'
        ];

        // String key matched with string selected value (strict comparison)
        $result = ViewHelper::renderSelectOptions($options, 'a');

        $this->assertStringContainsString('<option value="a" selected>Alpha</option>', $result);
        $this->assertStringNotContainsString('<option value="b" selected>', $result);
    }

    /**
     * Test renderSelectOptions handles special characters in values
     */
    public function testRenderSelectOptionsHandlesSpecialCharactersInValues(): void
    {
        $options = [
            'a&b' => 'A and B',
            "it's" => 'Apostrophe'
        ];

        $result = ViewHelper::renderSelectOptions($options);

        $this->assertStringContainsString('value="a&amp;b"', $result);
        $this->assertStringContainsString("value=\"it&#039;s\"", $result);
    }

    // =========================================================================
    // generateBreadcrumbs() tests
    // =========================================================================

    /**
     * Test generateBreadcrumbs returns empty string for empty array
     */
    public function testGenerateBreadcrumbsReturnsEmptyForEmptyArray(): void
    {
        $this->assertEquals('', ViewHelper::generateBreadcrumbs([]));
    }

    /**
     * Test generateBreadcrumbs generates correct structure
     */
    public function testGenerateBreadcrumbsGeneratesCorrectStructure(): void
    {
        $breadcrumbs = [
            'Home' => '/',
            'Products' => '/products'
        ];

        $result = ViewHelper::generateBreadcrumbs($breadcrumbs);

        $this->assertStringContainsString('<nav class="breadcrumb">', $result);
        $this->assertStringContainsString('<ol>', $result);
        $this->assertStringContainsString('</ol>', $result);
        $this->assertStringContainsString('</nav>', $result);
    }

    /**
     * Test generateBreadcrumbs creates links for all items except last
     */
    public function testGenerateBreadcrumbsCreatesLinksExceptLast(): void
    {
        $breadcrumbs = [
            'Home' => '/',
            'Products' => '/products',
            'Current' => '/products/current'
        ];

        $result = ViewHelper::generateBreadcrumbs($breadcrumbs);

        $this->assertStringContainsString('<a href="/">Home</a>', $result);
        $this->assertStringContainsString('<a href="/products">Products</a>', $result);
        // Last item should not be a link
        $this->assertStringContainsString('<li>Current</li>', $result);
        $this->assertStringNotContainsString('<a href="/products/current">', $result);
    }

    /**
     * Test generateBreadcrumbs renders single item without link
     */
    public function testGenerateBreadcrumbsRendersSingleItemWithoutLink(): void
    {
        $breadcrumbs = [
            'Home' => '/'
        ];

        $result = ViewHelper::generateBreadcrumbs($breadcrumbs);

        $this->assertStringContainsString('<li>Home</li>', $result);
        $this->assertStringNotContainsString('<a href="/">', $result);
    }

    /**
     * Test generateBreadcrumbs sanitizes labels and URLs
     */
    public function testGenerateBreadcrumbsSanitizesLabelsAndUrls(): void
    {
        $breadcrumbs = [
            '<script>evil</script>' => '/page?a=1&b=2',
            'Safe' => '/safe'
        ];

        $result = ViewHelper::generateBreadcrumbs($breadcrumbs);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringContainsString('a=1&amp;b=2', $result);
    }

    /**
     * Test generateBreadcrumbs wraps each item in li element
     */
    public function testGenerateBreadcrumbsWrapsEachItemInLiElement(): void
    {
        $breadcrumbs = [
            'A' => '/a',
            'B' => '/b',
            'C' => '/c'
        ];

        $result = ViewHelper::generateBreadcrumbs($breadcrumbs);

        // Count li tags (should be 3 opening and 3 closing)
        $this->assertEquals(3, substr_count($result, '<li>'));
        $this->assertEquals(3, substr_count($result, '</li>'));
    }

    /**
     * Test generateBreadcrumbs preserves order
     */
    public function testGenerateBreadcrumbsPreservesOrder(): void
    {
        $breadcrumbs = [
            'First' => '/first',
            'Second' => '/second',
            'Third' => '/third'
        ];

        $result = ViewHelper::generateBreadcrumbs($breadcrumbs);

        $firstPos = strpos($result, 'First');
        $secondPos = strpos($result, 'Second');
        $thirdPos = strpos($result, 'Third');

        $this->assertLessThan($secondPos, $firstPos);
        $this->assertLessThan($thirdPos, $secondPos);
    }
}
