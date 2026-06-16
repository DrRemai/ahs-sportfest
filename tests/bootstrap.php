<?php
declare(strict_types=1);

// Must be defined before helpers.php is loaded so the PHPUNIT_RUNNING guard
// is available the moment any API helper function is first called.
define('PHPUNIT_RUNNING', true);

// Load test-specific DB constants and BASE_PATH
require_once dirname(__DIR__) . '/config.test.php';

// Initialize a session so session_start_once() is a no-op throughout tests,
// and so $_SESSION is a proper superglobal rather than an undefined array.
if (session_status() === PHP_SESSION_NONE) {
    session_save_path(sys_get_temp_dir());
    @session_start();
}

// Core src files (global-namespace functions / classes — no PSR-4 autoloader)
require_once BASE_PATH . '/src/db.php';
require_once BASE_PATH . '/src/auth.php';
require_once BASE_PATH . '/src/helpers.php';
require_once BASE_PATH . '/src/Cache.php';
require_once BASE_PATH . '/src/Notification.php';
require_once BASE_PATH . '/src/FormatFactory.php';
require_once BASE_PATH . '/src/Formats/FormatInterface.php';
require_once BASE_PATH . '/src/Formats/SingleElim.php';
require_once BASE_PATH . '/src/Formats/DoubleElim.php';
require_once BASE_PATH . '/src/Formats/RoundRobin.php';
require_once BASE_PATH . '/src/Formats/Swiss.php';
require_once BASE_PATH . '/src/Formats/MultiStage.php';
require_once BASE_PATH . '/src/Permissions.php';
require_once BASE_PATH . '/src/router.php';

// Base test-case classes
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/Api/ApiTestCase.php';
