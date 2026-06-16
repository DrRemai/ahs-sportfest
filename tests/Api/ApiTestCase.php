<?php
declare(strict_types=1);

/**
 * Base class for API handler tests.
 *
 * Calling strategy
 * ─────────────────
 * API handlers are invoked directly (not over HTTP).  To make this work:
 *
 * 1. CSRF bypassed:    api_verify_csrf() returns immediately when PHPUNIT_RUNNING
 *                      is defined (patched in src/helpers.php).
 *
 * 2. Request body:     api_body() checks $GLOBALS['_test_api_body'] first.
 *                      Use setBody() to set it and clearBody() to remove it.
 *
 * 3. Exit interception: api_ok(), api_error(), api_validation_error() throw
 *                       RuntimeException('__api_exit__') instead of calling
 *                       exit (patched in src/helpers.php).
 *                       captureOutput() wraps the handler call in ob_start() +
 *                       try/catch so the JSON can be retrieved via ob_get_clean().
 *
 * 4. Auth:             Set $_SESSION['user'] via setSession() before the call.
 *
 * All tests run inside the shared BEGIN/ROLLBACK transaction from TestCase so
 * DB mutations are always rolled back.
 */
abstract class ApiTestCase extends TestCase
{
    // -------------------------------------------------------------------------
    // Request helpers
    // -------------------------------------------------------------------------

    protected function setBody(array $data): void
    {
        $GLOBALS['_test_api_body'] = $data;
    }

    protected function clearBody(): void
    {
        unset($GLOBALS['_test_api_body']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearBody();
    }


    // -------------------------------------------------------------------------
    // Response capture
    // -------------------------------------------------------------------------

    /**
     * Invokes $fn (a handler closure) and returns the decoded JSON payload.
     *
     * The handler echoes its JSON response and then throws RuntimeException
     * (injected by the PHPUNIT_RUNNING guard in helpers.php). ob_start() +
     * ob_get_clean() captures the echoed JSON before the exception propagates.
     */
protected function captureOutput(callable $fn): array
{
    ob_start();
    try {
        $fn();
    } catch (\RuntimeException $e) {
        if ($e->getMessage() !== '__api_exit__') {
            ob_get_clean();
            throw $e; // surface unexpected RuntimeExceptions
        }
    } catch (\Throwable $e) {
        ob_get_clean();
        throw $e;
    }
    $out = ob_get_clean();
    if (empty($out)) {
        throw new \RuntimeException("Handler produced no output. Last error: " . print_r(error_get_last(), true));
    }
    return json_decode((string)$out, true) ?? [];
}
    // -------------------------------------------------------------------------
    // Assertion helpers
    // -------------------------------------------------------------------------

    protected function assertOk(array $response): void
    {
        $this->assertTrue($response['ok'] ?? false, 'Expected ok=true, got: ' . json_encode($response));
    }

    protected function assertFail(array $response, int $expectedCode = 0): void
    {
        $this->assertFalse($response['ok'] ?? true, 'Expected ok=false, got: ' . json_encode($response));
    }

    protected function assertValidationError(array $response, string $field): void
    {
        $this->assertFalse($response['ok'] ?? true, 'Expected validation failure');
        $this->assertSame('validation_failed', $response['error'] ?? '');
        $this->assertArrayHasKey($field, $response['fields'] ?? [], "Expected field '$field' in validation errors");
    }

    protected function assertError(array $response, string $message): void
    {
        $this->assertFalse($response['ok'] ?? true, 'Expected ok=false');
        $this->assertStringContainsStringIgnoringCase($message, (string)($response['error'] ?? ''));
    }
}
