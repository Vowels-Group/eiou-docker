<?php
/**
 * Unit Tests for ApiKeyService
 *
 * Tests command routing, permission validation, and CLI output.
 * Mocks ApiKeyRepository and CliOutputManager to isolate unit behavior.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\ApiKeyService;
use Eiou\Database\ApiKeyRepository;
use Eiou\Cli\CliOutputManager;
use Eiou\Core\ErrorCodes;
use ReflectionClass;
use Exception;

#[CoversClass(ApiKeyService::class)]
class ApiKeyServiceTest extends TestCase
{
    private ApiKeyRepository $repository;
    private CliOutputManager $output;
    private ApiKeyService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ApiKeyRepository::class);
        $this->output = $this->createMock(CliOutputManager::class);
        $this->service = new ApiKeyService($this->repository, $this->output);
    }

    // =========================================================================
    // handleCommand() Routing Tests
    // =========================================================================

    /**
     * Test handleCommand routes 'create' action correctly
     */
    public function testHandleCommandRoutesToCreate(): void
    {
        $argv = ['eiou', 'apikey', 'create', 'Test Key', 'wallet:read'];

        $this->repository->expects($this->once())
            ->method('createKey')
            ->with('Test Key', ['wallet:read'])
            ->willReturn([
                'key_id' => 'eiou_test123',
                'secret' => 'secret123',
                'name' => 'Test Key',
                'permissions' => ['wallet:read']
            ]);

        $this->output->expects($this->once())
            ->method('success');

        ob_start();
        $this->service->handleCommand($argv);
        ob_end_clean();
    }

    /**
     * Test handleCommand routes 'list' action correctly
     */
    public function testHandleCommandRoutesToList(): void
    {
        $argv = ['eiou', 'apikey', 'list'];

        $this->repository->expects($this->once())
            ->method('listKeys')
            ->with(true)
            ->willReturn([]);

        $this->output->expects($this->atLeastOnce())
            ->method('info');

        $this->service->handleCommand($argv);
    }

    /**
     * Test handleCommand routes 'delete' action correctly
     */
    public function testHandleCommandRoutesToDelete(): void
    {
        $argv = ['eiou', 'apikey', 'delete', 'eiou_test123'];

        $this->repository->expects($this->once())
            ->method('deleteKey')
            ->with('eiou_test123')
            ->willReturn(true);

        $this->output->expects($this->once())
            ->method('success');

        ob_start();
        $this->service->handleCommand($argv);
        ob_end_clean();
    }

    /**
     * Test handleCommand routes 'disable' action correctly
     */
    public function testHandleCommandRoutesToDisable(): void
    {
        $argv = ['eiou', 'apikey', 'disable', 'eiou_test123'];

        $this->repository->expects($this->once())
            ->method('disableKey')
            ->with('eiou_test123')
            ->willReturn(true);

        $this->output->expects($this->once())
            ->method('success');

        ob_start();
        $this->service->handleCommand($argv);
        ob_end_clean();
    }

    /**
     * Test handleCommand routes 'enable' action correctly
     */
    public function testHandleCommandRoutesToEnable(): void
    {
        $argv = ['eiou', 'apikey', 'enable', 'eiou_test123'];

        $this->repository->expects($this->once())
            ->method('enableKey')
            ->with('eiou_test123')
            ->willReturn(true);

        $this->output->expects($this->once())
            ->method('success');

        ob_start();
        $this->service->handleCommand($argv);
        ob_end_clean();
    }

    /**
     * Test handleCommand routes 'help' action correctly
     */
    public function testHandleCommandRoutesToHelp(): void
    {
        $argv = ['eiou', 'apikey', 'help'];

        ob_start();
        $this->service->handleCommand($argv);
        $output = ob_get_clean();

        $this->assertStringContainsString('API Key Management Commands', $output);
    }

    /**
     * Test handleCommand defaults to help when no action specified
     */
    public function testHandleCommandDefaultsToHelp(): void
    {
        $argv = ['eiou', 'apikey'];

        ob_start();
        $this->service->handleCommand($argv);
        $output = ob_get_clean();

        $this->assertStringContainsString('API Key Management Commands', $output);
    }

    /**
     * Test handleCommand defaults to help for unknown action
     */
    public function testHandleCommandDefaultsToHelpForUnknownAction(): void
    {
        $argv = ['eiou', 'apikey', 'unknown'];

        ob_start();
        $this->service->handleCommand($argv);
        $output = ob_get_clean();

        $this->assertStringContainsString('API Key Management Commands', $output);
    }

    /**
     * Test handleCommand is case-insensitive for action
     */
    public function testHandleCommandIsCaseInsensitive(): void
    {
        $argv = ['eiou', 'apikey', 'LIST'];

        $this->repository->expects($this->once())
            ->method('listKeys')
            ->with(true)
            ->willReturn([]);

        $this->output->expects($this->atLeastOnce())
            ->method('info');

        $this->service->handleCommand($argv);
    }

    // =========================================================================
    // PERMISSIONS Constant Tests
    // =========================================================================

    /**
     * Test PERMISSIONS constant contains expected values
     */
    public function testPermissionsConstantContainsExpectedValues(): void
    {
        $reflection = new ReflectionClass(ApiKeyService::class);
        $permissionsProperty = $reflection->getConstant('PERMISSIONS');

        $expectedPermissions = [
            'wallet:read',
            'wallet:send',
            'contacts:read',
            'contacts:write',
            'system:read',
            'backup:read',
            'backup:write',
            'admin',
            'all'
        ];

        $this->assertEquals($expectedPermissions, $permissionsProperty);
    }

    /**
     * Test PERMISSIONS constant includes wallet permissions
     */
    public function testPermissionsConstantIncludesWalletPermissions(): void
    {
        $reflection = new ReflectionClass(ApiKeyService::class);
        $permissions = $reflection->getConstant('PERMISSIONS');

        $this->assertContains('wallet:read', $permissions);
        $this->assertContains('wallet:send', $permissions);
    }

    /**
     * Test PERMISSIONS constant includes contacts permissions
     */
    public function testPermissionsConstantIncludesContactsPermissions(): void
    {
        $reflection = new ReflectionClass(ApiKeyService::class);
        $permissions = $reflection->getConstant('PERMISSIONS');

        $this->assertContains('contacts:read', $permissions);
        $this->assertContains('contacts:write', $permissions);
    }

    /**
     * Test PERMISSIONS constant includes admin permissions
     */
    public function testPermissionsConstantIncludesAdminPermissions(): void
    {
        $reflection = new ReflectionClass(ApiKeyService::class);
        $permissions = $reflection->getConstant('PERMISSIONS');

        $this->assertContains('admin', $permissions);
        $this->assertContains('all', $permissions);
    }

    // =========================================================================
    // Permission Validation Tests
    // =========================================================================

    /**
     * Test valid permission is accepted
     */
    public function testValidPermissionIsAccepted(): void
    {
        $argv = ['eiou', 'apikey', 'create', 'Test Key', 'wallet:read'];

        $this->repository->expects($this->once())
            ->method('createKey')
            ->willReturn([
                'key_id' => 'eiou_test123',
                'secret' => 'secret123',
                'name' => 'Test Key',
                'permissions' => ['wallet:read']
            ]);

        $this->output->expects($this->never())
            ->method('error')
            ->with($this->stringContains('Invalid permission'));

        ob_start();
        $this->service->handleCommand($argv);
        ob_end_clean();
    }

    /**
     * Test invalid permission shows error
     */
    public function testInvalidPermissionShowsError(): void
    {
        $argv = ['eiou', 'apikey', 'create', 'Test Key', 'invalid:permission'];

        $this->repository->expects($this->never())
            ->method('createKey');

        $this->output->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Invalid permission: invalid:permission'),
                ErrorCodes::INVALID_PERMISSION,
                400
            );

        $this->service->handleCommand($argv);
    }

    /**
     * Test multiple valid permissions are accepted
     */
    public function testMultipleValidPermissionsAreAccepted(): void
    {
        $argv = ['eiou', 'apikey', 'create', 'Test Key', 'wallet:read,contacts:read,system:read'];

        $this->repository->expects($this->once())
            ->method('createKey')
            ->with('Test Key', ['wallet:read', 'contacts:read', 'system:read'])
            ->willReturn([
                'key_id' => 'eiou_test123',
                'secret' => 'secret123',
                'name' => 'Test Key',
                'permissions' => ['wallet:read', 'contacts:read', 'system:read']
            ]);

        ob_start();
        $this->service->handleCommand($argv);
        ob_end_clean();
    }

    /**
     * Test wildcard permission format is accepted
     */
    public function testWildcardPermissionFormatIsAccepted(): void
    {
        $argv = ['eiou', 'apikey', 'create', 'Test Key', 'wallet:*'];

        $this->repository->expects($this->once())
            ->method('createKey')
            ->with('Test Key', ['wallet:*'])
            ->willReturn([
                'key_id' => 'eiou_test123',
                'secret' => 'secret123',
                'name' => 'Test Key',
                'permissions' => ['wallet:*']
            ]);

        ob_start();
        $this->service->handleCommand($argv);
        ob_end_clean();
    }

    /**
     * Test default permissions when not specified
     */
    public function testDefaultPermissionsWhenNotSpecified(): void
    {
        $argv = ['eiou', 'apikey', 'create', 'Test Key'];

        $this->repository->expects($this->once())
            ->method('createKey')
            ->with('Test Key', ['wallet:read', 'contacts:read'])
            ->willReturn([
                'key_id' => 'eiou_test123',
                'secret' => 'secret123',
                'name' => 'Test Key',
                'permissions' => ['wallet:read', 'contacts:read']
            ]);

        ob_start();
        $this->service->handleCommand($argv);
        ob_end_clean();
    }

    // =========================================================================
    // Create Key Tests
    // =========================================================================

    /**
     * Test create key requires name argument
     */
    public function testCreateKeyRequiresName(): void
    {
        $argv = ['eiou', 'apikey', 'create'];

        $this->repository->expects($this->never())
            ->method('createKey');

        $this->output->expects($this->once())
            ->method('error')
            ->with(
                'Missing required argument: name',
                ErrorCodes::MISSING_ARGUMENT,
                400
            );

        $this->service->handleCommand($argv);
    }

    /**
     * Test create key handles repository exception
     */
    public function testCreateKeyHandlesRepositoryException(): void
    {
        $argv = ['eiou', 'apikey', 'create', 'Test Key'];

        $this->repository->expects($this->once())
            ->method('createKey')
            ->willThrowException(new Exception('Database error'));

        $this->output->expects($this->once())
            ->method('error')
            ->with(
                'Failed to create API key: Database error',
                ErrorCodes::CREATE_FAILED,
                500
            );

        $this->service->handleCommand($argv);
    }

    /**
     * Test create key trims permission whitespace
     */
    public function testCreateKeyTrimsPermissionWhitespace(): void
    {
        $argv = ['eiou', 'apikey', 'create', 'Test Key', ' wallet:read , contacts:read '];

        $this->repository->expects($this->once())
            ->method('createKey')
            ->with('Test Key', ['wallet:read', 'contacts:read'])
            ->willReturn([
                'key_id' => 'eiou_test123',
                'secret' => 'secret123',
                'name' => 'Test Key',
                'permissions' => ['wallet:read', 'contacts:read']
            ]);

        ob_start();
        $this->service->handleCommand($argv);
        ob_end_clean();
    }

    // =========================================================================
    // List Keys Tests
    // =========================================================================

    /**
     * Test list keys displays empty message when no keys
     */
    public function testListKeysDisplaysEmptyMessage(): void
    {
        $argv = ['eiou', 'apikey', 'list'];

        $this->repository->expects($this->once())
            ->method('listKeys')
            ->with(true)
            ->willReturn([]);

        $this->output->expects($this->exactly(2))
            ->method('info');

        $this->service->handleCommand($argv);
    }

    /**
     * Test list keys displays keys when available
     */
    public function testListKeysDisplaysKeysWhenAvailable(): void
    {
        $argv = ['eiou', 'apikey', 'list'];

        $keys = [
            [
                'key_id' => 'eiou_test123',
                'name' => 'Test Key',
                'enabled' => true,
                'last_used_at' => '2025-01-01 12:00:00'
            ]
        ];

        $this->repository->expects($this->once())
            ->method('listKeys')
            ->with(true)
            ->willReturn($keys);

        $this->output->expects($this->once())
            ->method('success');

        ob_start();
        $this->service->handleCommand($argv);
        $output = ob_get_clean();

        $this->assertStringContainsString('API KEYS', $output);
    }

    /**
     * Test list keys handles repository exception
     */
    public function testListKeysHandlesRepositoryException(): void
    {
        $argv = ['eiou', 'apikey', 'list'];

        $this->repository->expects($this->once())
            ->method('listKeys')
            ->willThrowException(new Exception('Database error'));

        $this->output->expects($this->once())
            ->method('error')
            ->with(
                'Failed to list API keys: Database error',
                ErrorCodes::LIST_FAILED,
                500
            );

        $this->service->handleCommand($argv);
    }

    // =========================================================================
    // Delete Key Tests
    // =========================================================================

    /**
     * Test delete key requires key_id argument
     */
    public function testDeleteKeyRequiresKeyId(): void
    {
        $argv = ['eiou', 'apikey', 'delete'];

        $this->repository->expects($this->never())
            ->method('deleteKey');

        $this->output->expects($this->once())
            ->method('error')
            ->with(
                'Missing required argument: key_id',
                ErrorCodes::MISSING_ARGUMENT,
                400
            );

        $this->service->handleCommand($argv);
    }

    /**
     * Test delete key shows error when key not found
     */
    public function testDeleteKeyShowsErrorWhenNotFound(): void
    {
        $argv = ['eiou', 'apikey', 'delete', 'nonexistent'];

        $this->repository->expects($this->once())
            ->method('deleteKey')
            ->with('nonexistent')
            ->willReturn(false);

        $this->output->expects($this->once())
            ->method('error')
            ->with(
                'API key not found: nonexistent',
                ErrorCodes::NOT_FOUND,
                404
            );

        $this->service->handleCommand($argv);
    }

    /**
     * Test delete key handles repository exception
     */
    public function testDeleteKeyHandlesRepositoryException(): void
    {
        $argv = ['eiou', 'apikey', 'delete', 'eiou_test123'];

        $this->repository->expects($this->once())
            ->method('deleteKey')
            ->willThrowException(new Exception('Database error'));

        $this->output->expects($this->once())
            ->method('error')
            ->with(
                'Failed to delete API key: Database error',
                ErrorCodes::DELETE_FAILED,
                500
            );

        $this->service->handleCommand($argv);
    }

    // =========================================================================
    // Disable Key Tests
    // =========================================================================

    /**
     * Test disable key requires key_id argument
     */
    public function testDisableKeyRequiresKeyId(): void
    {
        $argv = ['eiou', 'apikey', 'disable'];

        $this->repository->expects($this->never())
            ->method('disableKey');

        $this->output->expects($this->once())
            ->method('error')
            ->with(
                'Missing required argument: key_id',
                ErrorCodes::MISSING_ARGUMENT,
                400
            );

        $this->service->handleCommand($argv);
    }

    /**
     * Test disable key shows error when key not found
     */
    public function testDisableKeyShowsErrorWhenNotFound(): void
    {
        $argv = ['eiou', 'apikey', 'disable', 'nonexistent'];

        $this->repository->expects($this->once())
            ->method('disableKey')
            ->with('nonexistent')
            ->willReturn(false);

        $this->output->expects($this->once())
            ->method('error')
            ->with(
                'API key not found: nonexistent',
                ErrorCodes::NOT_FOUND,
                404
            );

        $this->service->handleCommand($argv);
    }

    /**
     * Test disable key handles repository exception
     */
    public function testDisableKeyHandlesRepositoryException(): void
    {
        $argv = ['eiou', 'apikey', 'disable', 'eiou_test123'];

        $this->repository->expects($this->once())
            ->method('disableKey')
            ->willThrowException(new Exception('Database error'));

        $this->output->expects($this->once())
            ->method('error')
            ->with(
                'Failed to disable API key: Database error',
                ErrorCodes::DISABLE_FAILED,
                500
            );

        $this->service->handleCommand($argv);
    }

    // =========================================================================
    // Enable Key Tests
    // =========================================================================

    /**
     * Test enable key requires key_id argument
     */
    public function testEnableKeyRequiresKeyId(): void
    {
        $argv = ['eiou', 'apikey', 'enable'];

        $this->repository->expects($this->never())
            ->method('enableKey');

        $this->output->expects($this->once())
            ->method('error')
            ->with(
                'Missing required argument: key_id',
                ErrorCodes::MISSING_ARGUMENT,
                400
            );

        $this->service->handleCommand($argv);
    }

    /**
     * Test enable key shows error when key not found
     */
    public function testEnableKeyShowsErrorWhenNotFound(): void
    {
        $argv = ['eiou', 'apikey', 'enable', 'nonexistent'];

        $this->repository->expects($this->once())
            ->method('enableKey')
            ->with('nonexistent')
            ->willReturn(false);

        $this->output->expects($this->once())
            ->method('error')
            ->with(
                'API key not found: nonexistent',
                ErrorCodes::NOT_FOUND,
                404
            );

        $this->service->handleCommand($argv);
    }

    /**
     * Test enable key handles repository exception
     */
    public function testEnableKeyHandlesRepositoryException(): void
    {
        $argv = ['eiou', 'apikey', 'enable', 'eiou_test123'];

        $this->repository->expects($this->once())
            ->method('enableKey')
            ->willThrowException(new Exception('Database error'));

        $this->output->expects($this->once())
            ->method('error')
            ->with(
                'Failed to enable API key: Database error',
                ErrorCodes::ENABLE_FAILED,
                500
            );

        $this->service->handleCommand($argv);
    }

    // =========================================================================
    // Help Output Tests
    // =========================================================================

    /**
     * Test help output contains create command documentation
     */
    public function testHelpOutputContainsCreateDocumentation(): void
    {
        $argv = ['eiou', 'apikey', 'help'];

        ob_start();
        $this->service->handleCommand($argv);
        $output = ob_get_clean();

        $this->assertStringContainsString('create <name>', $output);
        $this->assertStringContainsString('permissions', $output);
    }

    /**
     * Test help output contains list command documentation
     */
    public function testHelpOutputContainsListDocumentation(): void
    {
        $argv = ['eiou', 'apikey', 'help'];

        ob_start();
        $this->service->handleCommand($argv);
        $output = ob_get_clean();

        $this->assertStringContainsString('apikey list', $output);
    }

    /**
     * Test help output contains delete command documentation
     */
    public function testHelpOutputContainsDeleteDocumentation(): void
    {
        $argv = ['eiou', 'apikey', 'help'];

        ob_start();
        $this->service->handleCommand($argv);
        $output = ob_get_clean();

        $this->assertStringContainsString('delete <key_id>', $output);
    }

    /**
     * Test help output contains all permission descriptions
     */
    public function testHelpOutputContainsPermissionDescriptions(): void
    {
        $argv = ['eiou', 'apikey', 'help'];

        ob_start();
        $this->service->handleCommand($argv);
        $output = ob_get_clean();

        $this->assertStringContainsString('wallet:read', $output);
        $this->assertStringContainsString('wallet:send', $output);
        $this->assertStringContainsString('contacts:read', $output);
        $this->assertStringContainsString('contacts:write', $output);
        $this->assertStringContainsString('system:read', $output);
        $this->assertStringContainsString('backup:read', $output);
        $this->assertStringContainsString('backup:write', $output);
        $this->assertStringContainsString('admin', $output);
        $this->assertStringContainsString('all', $output);
    }

    /**
     * Test help output contains API usage instructions
     */
    public function testHelpOutputContainsApiUsageInstructions(): void
    {
        $argv = ['eiou', 'apikey', 'help'];

        ob_start();
        $this->service->handleCommand($argv);
        $output = ob_get_clean();

        $this->assertStringContainsString('X-API-Key', $output);
        $this->assertStringContainsString('X-API-Timestamp', $output);
        $this->assertStringContainsString('X-API-Signature', $output);
        $this->assertStringContainsString('HMAC-SHA256', $output);
    }

    // =========================================================================
    // validatePermissions() Static Method Tests
    // =========================================================================

    /**
     * Test validatePermissions accepts a single valid permission
     */
    public function testValidatePermissionsAcceptsSingleValid(): void
    {
        $result = ApiKeyService::validatePermissions(['wallet:read']);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['invalid_permission']);
    }

    /**
     * Test validatePermissions accepts multiple valid permissions
     */
    public function testValidatePermissionsAcceptsMultipleValid(): void
    {
        $result = ApiKeyService::validatePermissions(['wallet:read', 'contacts:write', 'admin']);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['invalid_permission']);
    }

    /**
     * Test validatePermissions rejects invalid permission
     */
    public function testValidatePermissionsRejectsInvalid(): void
    {
        $result = ApiKeyService::validatePermissions(['wallet:read', 'evil:hack']);

        $this->assertFalse($result['valid']);
        $this->assertEquals('evil:hack', $result['invalid_permission']);
    }

    /**
     * Test validatePermissions accepts wildcard format
     */
    public function testValidatePermissionsAcceptsWildcard(): void
    {
        $result = ApiKeyService::validatePermissions(['wallet:*']);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['invalid_permission']);
    }

    /**
     * Test validatePermissions rejects invalid wildcard format
     */
    public function testValidatePermissionsRejectsInvalidWildcard(): void
    {
        $result = ApiKeyService::validatePermissions(['*:*']);

        $this->assertFalse($result['valid']);
        $this->assertEquals('*:*', $result['invalid_permission']);
    }

    // =========================================================================
    // validateRateLimit() Static Method Tests
    // =========================================================================

    /**
     * Test validateRateLimit accepts valid rate limit
     */
    public function testValidateRateLimitAcceptsValid(): void
    {
        $result = ApiKeyService::validateRateLimit(100);

        $this->assertTrue($result['valid']);
        $this->assertEquals(100, $result['value']);
        $this->assertNull($result['error']);
    }

    /**
     * Test validateRateLimit rejects zero
     */
    public function testValidateRateLimitRejectsZero(): void
    {
        $result = ApiKeyService::validateRateLimit(0);

        $this->assertFalse($result['valid']);
        $this->assertNull($result['value']);
        $this->assertStringContainsString('greater than zero', $result['error']);
    }

    /**
     * Test validateRateLimit rejects negative
     */
    public function testValidateRateLimitRejectsNegative(): void
    {
        $result = ApiKeyService::validateRateLimit(-5);

        $this->assertFalse($result['valid']);
        $this->assertNull($result['value']);
        $this->assertStringContainsString('greater than zero', $result['error']);
    }

    /**
     * Test validateRateLimit rejects over max
     */
    public function testValidateRateLimitRejectsOverMax(): void
    {
        $result = ApiKeyService::validateRateLimit(ApiKeyService::MAX_RATE_LIMIT + 1);

        $this->assertFalse($result['valid']);
        $this->assertNull($result['value']);
        $this->assertStringContainsString('exceeds maximum', $result['error']);
    }

    /**
     * Test validateRateLimit rejects non-numeric
     */
    public function testValidateRateLimitRejectsNonNumeric(): void
    {
        $result = ApiKeyService::validateRateLimit('abc');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['value']);
        $this->assertStringContainsString('numeric', $result['error']);
    }
}
