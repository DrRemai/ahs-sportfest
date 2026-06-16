<?php
declare(strict_types=1);

function h(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url, int $code = 302): never
{
    http_response_code($code);
    header("Location: $url");
    exit;
}

function csrf_token(): string
{
    session_start_once();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        render('errors/403');
        exit;
    }
}

/**
 * Renders a view inside the layout wrapper.
 * Variables in $data are extracted into the view's scope.
 */
// ---------------------------------------------------------------------------
// JSON API helpers
// ---------------------------------------------------------------------------

function api_validation_error(array $fields): never
{
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['ok' => false, 'error' => 'validation_failed', 'fields' => $fields],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
    );
    // In PHPUnit tests, throw instead of exit so ob_get_clean() can retrieve
    // the encoded response and the test process keeps running.
    if (defined('PHPUNIT_RUNNING')) throw new \RuntimeException('__api_exit__');
    exit;
}

function api_ok(mixed $data = null): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    if (defined('PHPUNIT_RUNNING')) throw new \RuntimeException('__api_exit__');
    exit;
}

function api_error(string $message, int $code = 400, array $extra = []): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra), JSON_THROW_ON_ERROR);
    if (defined('PHPUNIT_RUNNING')) throw new \RuntimeException('__api_exit__');
    exit;
}

function api_body(): array
{
    // In tests, setBody() writes $GLOBALS['_test_api_body'] instead of
    // php://input (which is not writable in CLI).
    if (defined('PHPUNIT_RUNNING') && isset($GLOBALS['_test_api_body'])) {
        return $GLOBALS['_test_api_body'];
    }
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function api_require_auth(): array
{
    $user = current_user();
    if (!$user) api_error('Unauthenticated.', 401);
    return $user;
}

function api_require_admin(): array
{
    $user = api_require_auth();
    if (!$user['is_admin']) api_error('Forbidden.', 403);
    return $user;
}

function api_verify_csrf(): void
{
    // CSRF tokens are meaningless in a CLI test environment; skip the check.
    if (defined('PHPUNIT_RUNNING')) return;
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        api_error('Invalid CSRF token.', 403);
    }
}

// ---------------------------------------------------------------------------
// Legacy view helpers (kept for reference, not served by SPA)
// ---------------------------------------------------------------------------

function render(string $view, array $data = []): void
{
    $viewPath = BASE_PATH . '/views/' . $view . '.php';
    if (!file_exists($viewPath)) {
        http_response_code(404);
        // Avoid recursive render failure if the 404 view itself is missing
        $errorPath = BASE_PATH . '/views/errors/404.php';
        if (file_exists($errorPath)) {
            include $errorPath;
        } else {
            echo '<h1>404 Not Found</h1>';
        }
        return;
    }

    extract($data, EXTR_SKIP);

    ob_start();
    include $viewPath;
    $content = ob_get_clean();

    include BASE_PATH . '/views/layout.php';
}
