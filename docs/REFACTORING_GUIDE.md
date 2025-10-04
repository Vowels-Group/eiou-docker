# Code Quality Refactoring Guide

This guide explains how to refactor the codebase to address code quality issues.

## 1. Replacing Global Variables

### Before (Using Globals):
```php
global $pdo;
global $user;

$pdo = createPDOConnection();
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
```

### After (Using Application Singleton):
```php
require_once 'src/core/Application.php';

$app = Application::getInstance();
$pdo = $app->getDatabase();
$user = $app->getUser();

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
```

## 2. Replacing Magic Numbers

### Before (Magic Numbers):
```php
usleep(500000); // What does 500000 mean?
if ($attempts > 5) { // Why 5?
    $blockTime = 300; // Why 300?
}
```

### After (Named Constants):
```php
require_once 'src/config/Constants.php';

usleep(Constants::POLLING_IDLE_INTERVAL_MS * 1000);
if ($attempts > Constants::RATE_LIMIT_LOGIN_MAX) {
    $blockTime = Constants::RATE_LIMIT_LOGIN_BLOCK;
}
```

## 3. Standardizing Error Handling

### Before (Inconsistent Error Handling):
```php
// Different error handling patterns
if (!$result) {
    die("Error occurred");
}

try {
    // some code
} catch (Exception $e) {
    echo $e->getMessage();
}

if ($error) {
    return false;
}
```

### After (Standardized Error Handling):
```php
require_once 'src/core/ErrorHandler.php';

// Initialize once at application start
ErrorHandler::init();

// Use consistent try-catch pattern
$result = ErrorHandler::tryOperation(
    function() use ($pdo, $data) {
        return $pdo->query($data);
    },
    function($e) {
        // Custom error handling
        return ErrorHandler::createErrorResponse($e->getMessage(), 500);
    },
    null // default value
);

// Return standardized responses
if ($error) {
    return ErrorHandler::createErrorResponse("Operation failed", 400);
}

return ErrorHandler::createSuccessResponse($data, "Operation successful");
```

## 4. Refactoring Functions to Use Application Class

### Before (Function with globals):
```php
function getUserBalance() {
    global $pdo, $user;

    $stmt = $pdo->prepare("SELECT balance FROM accounts WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    return $stmt->fetchColumn();
}
```

### After (Function with Application):
```php
function getUserBalance() {
    $app = Application::getInstance();
    $pdo = $app->getDatabase();
    $user = $app->getUser();

    if (!$pdo || !$user) {
        return ErrorHandler::createErrorResponse("Database or user not available");
    }

    return ErrorHandler::tryOperation(
        function() use ($pdo, $user) {
            $stmt = $pdo->prepare("SELECT balance FROM accounts WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            return $stmt->fetchColumn();
        },
        null,
        0 // default balance
    );
}
```

## 5. Migration Steps

### Phase 1: Add New Infrastructure (Complete)
✅ Create Constants.php for all magic numbers
✅ Create Application.php singleton
✅ Create ErrorHandler.php for consistent error handling

### Phase 2: Refactor Entry Points
1. Update main entry files to use Application::getInstance()
2. Replace global $pdo with $app->getDatabase()
3. Replace global $user with $app->getUser()

### Phase 3: Refactor Functions
1. Update each function to use Application instead of globals
2. Replace magic numbers with Constants
3. Add proper error handling with ErrorHandler

### Phase 4: Testing
1. Test each refactored component
2. Ensure backward compatibility
3. Verify error handling works correctly

## Constants Reference

### Time Intervals
- `Constants::POLLING_MIN_INTERVAL_MS` - 100ms
- `Constants::POLLING_MAX_INTERVAL_MS` - 5000ms
- `Constants::SESSION_TIMEOUT_SECONDS` - 3600 (1 hour)

### Rate Limiting
- `Constants::RATE_LIMIT_API_MAX` - 100 requests
- `Constants::RATE_LIMIT_LOGIN_MAX` - 5 attempts
- `Constants::RATE_LIMIT_LOGIN_WINDOW` - 300 seconds

### Database
- `Constants::DB_CONNECTION_TIMEOUT` - 5 seconds
- `Constants::DB_MAX_RETRIES` - 3 attempts

### Display
- `Constants::DISPLAY_TRANSACTION_HISTORY_LIMIT` - 10 items
- `Constants::DISPLAY_DATE_FORMAT` - 'Y-m-d H:i:s'

## Benefits

1. **No More Global State**: Application singleton manages state properly
2. **Clear Constants**: Magic numbers have meaningful names
3. **Consistent Errors**: All errors handled the same way
4. **Testability**: Can mock Application for testing
5. **Maintainability**: Clear patterns throughout codebase