<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Endgame Tournaments</title>
</head>
<body>

<nav>
    <a href="/">Endgame Tournaments</a>
    &nbsp;|&nbsp;
    <?php $__user = current_user(); ?>
    <?php if ($__user): ?>
        Logged in as <strong><?= h($__user['display_name']) ?></strong>
        <?php if ($__user['is_admin']): ?>
            <em>(Admin)</em>
        <?php endif; ?>
        &nbsp;|&nbsp;
        <a href="/tournament/create">New Tournament</a>
        &nbsp;|&nbsp;
        <form method="post" action="/logout" style="display:inline">
            <?= csrf_field() ?>
            <button type="submit">Log out</button>
        </form>
    <?php else: ?>
        <a href="/login">Log in</a>
    <?php endif; ?>
</nav>

<hr>

<main>
    <?php if (!empty($_GET['notice'])): ?>
        <p><strong>Notice:</strong> <?= h($_GET['notice']) ?></p>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
        <p><strong>Error:</strong> <?= h($_GET['error']) ?></p>
    <?php endif; ?>

    <?= $content ?>
</main>

<hr>
<footer>
    <small>Endgame Tournaments</small>
</footer>

</body>
</html>
