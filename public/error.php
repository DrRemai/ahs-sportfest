<?php
$code = (int)($_GET['code'] ?? http_response_code());
if (!in_array($code, [400, 401, 403, 404, 500, 503], true)) {
    $code = 500;
}
http_response_code($code);

$messages = [
    400 => 'Bad request.',
    401 => 'Authentication required.',
    403 => 'Access denied.',
    404 => 'Page not found.',
    500 => 'Internal server error.',
    503 => 'Service unavailable.',
];
$message = $messages[$code] ?? 'An error occurred.';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $code ?> — AHS Sportfest</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #060608; --accent: #F5A623;
      --text-primary: #F0ECE0; --text-muted: rgba(240,236,224,0.22);
      --border: rgba(245,166,35,0.12); --surface: #111113; --radius: 2px;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background: var(--bg); color: var(--text-primary);
      font-family: 'JetBrains Mono', 'Courier New', monospace;
      min-height: 100vh; display: flex; flex-direction: column;
      align-items: center; justify-content: center; padding: 24px;
    }
    .error-code {
      font-size: 80px; font-weight: 700; color: var(--accent);
      letter-spacing: -0.03em; line-height: 1; margin-bottom: 16px;
    }
    .error-msg {
      font-size: 13px; color: var(--text-muted); margin-bottom: 32px;
    }
    a {
      display: inline-block; padding: 6px 16px; font-size: 12px; font-weight: 600;
      letter-spacing: 0.04em; text-transform: uppercase; text-decoration: none;
      color: var(--text-primary); border: 1px solid var(--border); border-radius: var(--radius);
    }
    a:hover { border-color: rgba(245,166,35,0.35); }
  </style>
</head>
<body>
  <div class="error-code"><?= $code ?></div>
  <div class="error-msg">// <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
  <a href="/">← home</a>
</body>
</html>
