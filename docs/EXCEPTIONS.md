# Exception Handling

Exception types in `files/src/exceptions/`, plus the conventions for when each is appropriate and how callers translate them.

## Hierarchy

```
\Exception                                (PHP)
└── Eiou\Exceptions\ServiceException      (abstract base)
    ├── Eiou\Exceptions\FatalServiceException        — non-recoverable; abort
    ├── Eiou\Exceptions\RecoverableServiceException  — retryable; queue / DLQ
    └── Eiou\Exceptions\ValidationServiceException   — input rejected; user-actionable
```

`ServiceException` carries a structured error code (`ErrorCodes` constant), an HTTP status, and a context array. Subclasses define `getExitCode()` for CLI translation.

## When to throw which

| Subclass | Throw when | Caller behavior |
|---|---|---|
| `ValidationServiceException` | The caller gave us bad input — invalid amount, malformed pubkey, missing required field, business-rule violation (e.g. transaction would exceed credit limit). | Surface to user with the message; HTTP 400; CLI exit code 2. |
| `RecoverableServiceException` | A dependency hiccupped — DB temporary failure, peer unreachable, signature couldn't be re-issued right now. The caller may retry. | Queue / DLQ / log + retry; do not propagate to the user as a hard failure. |
| `FatalServiceException` | An invariant broke — corrupted state, missing required service, irreparable data. The operation cannot complete. | Abort; HTTP 500; CLI exit code 1; log full context. |

If you can't pick one of the three, ask: "Can the user fix this?" → `Validation`. "Will retrying help?" → `Recoverable`. "No path forward?" → `Fatal`.

Stick to the existing classes. Don't subclass for every new failure mode — the error code (`ErrorCodes`) is the discriminator within a class. Add a new ErrorCodes constant; reuse the class.

## Where each surfaces

- **REST API**: `ApiController::errorResponse()` reads `getErrorCode()` + `getHttpStatus()` from the exception and emits the canonical REST envelope.
- **CLI**: `CliJsonResponse::error()` produces an RFC 9457 problem-details JSON body; `getExitCode()` becomes the process exit status.
- **GUI / JSON-AJAX**: `GuiErrorResponse::send($code, $message, $httpStatus)` for handler-level catches; the `ServiceException` is unwrapped inline.

These three envelopes are intentionally distinct — REST clients, CLI consumers, and GUI AJAX consumers each get a shape tailored to their parser. Don't unify them.

## Plain `\RuntimeException` and `\InvalidArgumentException`

Acceptable for:

- **Programmer errors** — null where not allowed, type mismatch, missing constructor arg. These should never reach a user; they indicate a bug. Throw immediately.
- **Defense-in-depth guards** — e.g. `assertSafeMigrationIdentifier()` in `DatabaseSetup.php` throws `InvalidArgumentException` if a hardcoded value somehow got tainted. The caller is core code that shouldn't pass tainted input.
- **Plugin event veto** — listeners on `TransactionEvents::PRE_VALIDATE` may throw any `\RuntimeException` subclass to abort validation; the dispatch site lets it propagate.

Don't use plain exceptions for anything a service routinely encounters — those should be `ServiceException` subclasses.

## Documenting throws

Service public methods declare `@throws` for every typed `ServiceException` subclass they intentionally throw OR let propagate. Don't declare `@throws \Exception` — that's noise.

Canonical example: `TransactionValidationService::checkTransactionPossible()`.

```php
/**
 * Run the full validation pipeline before processing a transaction.
 *
 * @throws \RuntimeException                  Plugin veto via TransactionEvents::PRE_VALIDATE.
 * @throws ValidationServiceException         Input failed business rules (insufficient funds, etc.).
 * @throws RecoverableServiceException        Sync-to-peer needed but couldn't complete now.
 * @throws FatalServiceException              Required dependency was not injected.
 */
public function checkTransactionPossible(array $request, $echo = true): bool { … }
```

Add `@throws` lines incrementally — touching a method to fix a bug is a good time. A repo-wide sweep is out of scope; the convention is enough to keep new code on-pattern.
