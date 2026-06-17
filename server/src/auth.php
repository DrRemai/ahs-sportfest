<?php
declare(strict_types=1);

function session_start_once(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => false, // set true when serving over HTTPS
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/** Returns the session user array or null if not logged in. */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_auth(): void
{
    if (!current_user()) {
        redirect('/login');
    }
}

function require_admin(): void
{
    $user = current_user();
    if (!$user || !$user['is_admin']) {
        http_response_code(403);
        render('errors/403');
        exit;
    }
}

/**
 * Returns the calling user's role in $tournamentId:
 * 'admin', 'organiser', 'staff', or null (guest).
 * Admins always get 'admin' regardless of tournament_roles rows.
 */
function tournament_role(int $tournamentId): ?string
{
    $user = current_user();
    if (!$user) {
        return null;
    }
    if ($user['is_admin']) {
        return 'admin';
    }

    $stmt = db()->prepare(
        'SELECT role FROM tournament_roles WHERE tournament_id = ? AND user_id = ?'
    );
    $stmt->execute([$tournamentId, $user['uid']]);
    $row = $stmt->fetch();
    return $row ? $row['role'] : null;
}

function handle_login(): void
{
    verify_csrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        render('login', ['error' => 'Username and password are required.']);
        return;
    }

    $stmt = db()->prepare(
        'SELECT id, display_name, password_hash, is_admin
         FROM users
         WHERE lower(username) = lower(?)'
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        render('login', ['error' => 'Invalid username or password.']);
        return;
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'uid'          => (int)$user['id'],
        'display_name' => $user['display_name'],
        'is_admin'     => (bool)$user['is_admin'],
    ];

    redirect('/');
}

function handle_logout(): void
{
    verify_csrf();
    $_SESSION = [];
    session_destroy();
    redirect('/login');
}
