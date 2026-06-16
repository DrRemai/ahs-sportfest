<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/src/db.php';
require_once BASE_PATH . '/src/auth.php';

// ---------------------------------------------------------------------------
// Runtime — no execution time limit, detect client disconnect
// ---------------------------------------------------------------------------
set_time_limit(0);
ignore_user_abort(true); // keep running; we check connection_aborted() ourselves

// ---------------------------------------------------------------------------
// Kill all output buffering layers
// Apache on Windows stacks several OB layers; all must be flushed before
// SSE output becomes visible to the client.
// ---------------------------------------------------------------------------
while (ob_get_level()) ob_end_clean();

// Suppress errors — these fail gracefully on non-Apache SAPI
@apache_setenv('no-gzip', '1');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');

// ---------------------------------------------------------------------------
// SSE headers
// ---------------------------------------------------------------------------
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

// ---------------------------------------------------------------------------
// Auth — use session_start_once() from auth.php; never raw session_start()
// ---------------------------------------------------------------------------
session_start_once();
$user = current_user();
if ($user === null) {
    echo "event: error\ndata: {\"code\":\"unauthenticated\"}\n\n";
    flush();
    exit;
}
$uid = (int) $user['uid'];
// Release the session file lock immediately — the poll loop never writes to the
// session, and holding the lock for the full SSE lifetime (up to 3600 s) would
// block every concurrent API request from the same browser.
session_write_close();

// ---------------------------------------------------------------------------
// Tournament channel subscriptions from ?t=1,2,3
// ---------------------------------------------------------------------------
$tids  = [];
$tRaw  = $_GET['t'] ?? '';
if ($tRaw !== '') {
    foreach (explode(',', $tRaw) as $chunk) {
        $v = (int) trim($chunk);
        if ($v > 0) $tids[] = $v;
    }
}

// ---------------------------------------------------------------------------
// Dual connection approach
//
// PDO can issue LISTEN commands via query(), but does NOT expose pg_get_notify()
// because that function requires a raw pgsql resource handle. Solution:
//   - PDO connection  → issue LISTEN commands
//   - pg_connect()    → non-blocking pg_get_notify() polls
//
// PGSQL_CONNECT_FORCE_NEW ensures a distinct TCP socket so the two connections
// do not share state or interfere with each other (or any existing pg_connect
// pool entries from other PHP scripts in the same process).
// ---------------------------------------------------------------------------
$dsn     = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME;
$rawDsn  = sprintf(
    'host=%s port=%s dbname=%s user=%s password=%s connect_timeout=10',
    DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
);

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    echo "event: error\ndata: {\"code\":\"db_connect\"}\n\n";
    flush();
    exit;
}

$pgConn = pg_connect($rawDsn, PGSQL_CONNECT_FORCE_NEW);
if ($pgConn === false) {
    echo "event: error\ndata: {\"code\":\"pg_connect\"}\n\n";
    flush();
    exit;
}

// ---------------------------------------------------------------------------
// Subscribe channels via PDO (LISTEN issues on the PDO connection are not
// observed by pg_get_notify, which polls the raw pg_connect handle).
// We therefore issue LISTEN on BOTH connections.
// ---------------------------------------------------------------------------
$channels = ['tournaments', 'user_' . $uid];
foreach ($tids as $tid) {
    $channels[] = 'tournament_' . $tid;
}

foreach ($channels as $ch) {
    $safeCh = pg_escape_identifier($pgConn, $ch);
    // LISTEN on the raw connection — pg_get_notify reads from here
    pg_query($pgConn, 'LISTEN ' . $safeCh);
    // LISTEN on the PDO connection as well (belt-and-suspenders; harmless)
    $pdo->exec('LISTEN ' . $safeCh);
}

// ---------------------------------------------------------------------------
// Send connected event
// ---------------------------------------------------------------------------
sendSseEvent('connected', ['uid' => $uid, 'channels' => $channels]);

// ---------------------------------------------------------------------------
// Poll loop
// ---------------------------------------------------------------------------
$connectedAt   = time();
$lastHeartbeat = time();

const HEARTBEAT_INTERVAL = 25;   // seconds — below typical proxy 30s keepalive timeout
const MAX_LIFETIME        = 3600; // 1 hour; client reconnects automatically

while (true) {
    if (connection_aborted()) break;

    $notify = pg_get_notify($pgConn, PGSQL_ASSOC);

    if ($notify !== false) {
        $decoded = json_decode($notify['payload'], true);
        if ($decoded !== null) {
            $event = $decoded['event'] ?? 'message';
            sendSseEvent($event, $decoded);
        }
    }

    $now = time();

    if ($now - $connectedAt >= MAX_LIFETIME) {
        sendSseEvent('timeout', ['reason' => 'max_lifetime']);
        break;
    }

    if ($now - $lastHeartbeat >= HEARTBEAT_INTERVAL) {
        sendSseHeartbeat();
        $lastHeartbeat = $now;
    }

    usleep(200_000); // 200 ms between polls; low CPU cost, ~150 ms notification latency
}

pg_close($pgConn);

// ---------------------------------------------------------------------------

function sendSseEvent(string $event, array $data): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: '  . json_encode($data) . "\n\n";
    flush();
}

function sendSseHeartbeat(): void
{
    echo ': heartbeat ' . time() . "\n\n";
    flush();
}
