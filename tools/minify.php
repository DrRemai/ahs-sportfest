<?php
/**
 * Endgame Tournaments — asset minifier
 *
 * Usage:  php tools/minify.php
 * Output: public/assets/dist/app.min.css
 *         public/assets/dist/app.min.js
 *         public/assets/dist/manifest.json
 *
 * No Node, no npm — pure PHP string-level whitespace minifier.
 * Variables are NOT renamed. import/export statements are stripped so the
 * concatenated bundle works as a plain <script> (not type="module").
 *
 * After running, public/index.php reads the manifest and replaces:
 *   /assets/css/app.css       →  /assets/dist/app.min.css?v=<hash>
 *   /assets/js/app.js         →  /assets/dist/app.min.js?v=<hash>
 */

declare(strict_types=1);

$root    = dirname(__DIR__) . '/public/assets';
$distDir = $root . '/dist';

if (!is_dir($distDir) && !mkdir($distDir, 0755, true)) {
    fwrite(STDERR, "ERROR: cannot create $distDir\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// CSS — single file for now; extend $cssSources for additional sheets
// ---------------------------------------------------------------------------
$cssSources = [
    $root . '/css/app.css',
];

$cssRaw = '';
foreach ($cssSources as $path) {
    if (!file_exists($path)) { fwrite(STDERR, "WARN: $path not found\n"); continue; }
    $cssRaw .= "\n" . file_get_contents($path);
}

$cssMin = minifyCSS($cssRaw);
$cssOut = $distDir . '/app.min.css';
file_put_contents($cssOut, $cssMin);
echo 'CSS: ' . number_format(strlen($cssRaw)) . ' → ' . number_format(strlen($cssMin)) . " bytes\n";

// ---------------------------------------------------------------------------
// JS — concatenated in dependency order; app.js last (entry point)
// import/export lines are stripped — bundle runs in global scope
// ---------------------------------------------------------------------------
$jsSources = [
    $root . '/js/utils/focus-trap.js',
    $root . '/js/utils/sse-client.js',
    $root . '/js/components/badge.js',
    $root . '/js/components/confirm.js',
    $root . '/js/components/empty.js',
    $root . '/js/components/error.js',
    $root . '/js/components/progress.js',
    $root . '/js/components/skeleton.js',
    $root . '/js/components/toast.js',
    $root . '/js/components/nav.js',
    $root . '/js/views/login.js',
    $root . '/js/views/register.js',
    $root . '/js/views/account.js',
    $root . '/js/views/home.js',
    $root . '/js/views/tournament.js',
    $root . '/js/views/team.js',
    $root . '/js/views/create-team.js',
    $root . '/js/views/create-tournament.js',
    $root . '/js/views/admin.js',
    $root . '/js/router.js',
    $root . '/js/app.js',
];

$jsRaw = '';
foreach ($jsSources as $path) {
    if (!file_exists($path)) { fwrite(STDERR, "WARN: $path not found\n"); continue; }
    $jsRaw .= "\n/* " . basename(dirname($path)) . '/' . basename($path) . " */\n";
    $jsRaw .= file_get_contents($path);
}

$jsMin = minifyJS($jsRaw);
$jsOut = $distDir . '/app.min.js';
file_put_contents($jsOut, $jsMin);
echo 'JS:  ' . number_format(strlen($jsRaw)) . ' → ' . number_format(strlen($jsMin)) . " bytes\n";

// ---------------------------------------------------------------------------
// Manifest — versioned URLs for cache-busting
// ---------------------------------------------------------------------------
$cssHash = substr(md5_file($cssOut), 0, 8);
$jsHash  = substr(md5_file($jsOut),  0, 8);

$manifest = [
    'app.css' => "/assets/dist/app.min.css?v={$cssHash}",
    'app.js'  => "/assets/dist/app.min.js?v={$jsHash}",
];

file_put_contents($distDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Manifest written — CSS v={$cssHash}  JS v={$jsHash}\n";

// ---------------------------------------------------------------------------
// CSS minifier
// ---------------------------------------------------------------------------

function minifyCSS(string $css): string
{
    // Remove /* ... */ comments (non-greedy, handles multi-line)
    $css = preg_replace('!/\*.*?\*/!s', '', $css);

    // Collapse whitespace (tabs, newlines, multiple spaces) to single space
    $css = preg_replace('/\s+/', ' ', $css);

    // Remove spaces around structural characters
    $css = preg_replace('/\s*([{};:,>~+])\s*/', '$1', $css);

    // Remove trailing semicolons before closing brace
    $css = str_replace(';}', '}', $css);

    // Remove leading/trailing whitespace
    return trim($css);
}

// ---------------------------------------------------------------------------
// JS minifier — whitespace only, no renaming, no AST
// Strips ES module import/export so the bundle runs without type="module"
// ---------------------------------------------------------------------------

function minifyJS(string $js): string
{
    // Strip ES module import lines (import ... from '...'; or import '...';)
    $js = preg_replace('/^\s*import\s+[^;]+;[ \t]*/m', '', $js);

    // Strip export keywords from declarations (export default, export const, export function)
    $js = preg_replace('/\bexport\s+default\s+/', '', $js);
    $js = preg_replace('/\bexport\s+(const|let|var|function|class)\b/', '$1', $js);
    $js = preg_replace('/^\s*export\s*\{[^}]*\}\s*;?\s*$/m', '', $js);

    // Remove single-line comments (// ...) not inside strings
    // Simple heuristic: remove // to end-of-line when not preceded by : or a quote
    $js = preg_replace('/(?<!:)(?<!\'|")(?<![a-z])\/\/[^\n]*/i', '', $js);

    // Remove /* ... */ block comments
    $js = preg_replace('!/\*.*?\*/!s', '', $js);

    // Collapse whitespace (preserve single spaces around operators)
    $js = preg_replace('/[ \t]+/', ' ', $js);       // collapse horizontal whitespace
    $js = preg_replace('/\n\s*\n+/', "\n", $js);    // collapse blank lines
    $js = preg_replace('/^\s+/m', '', $js);          // strip leading whitespace per line

    // Remove spaces around common operators / punctuation where safe
    $js = preg_replace('/\s*([{}();,])\s*/', '$1', $js);

    return trim($js);
}
