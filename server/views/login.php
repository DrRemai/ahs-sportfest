<h1>Log In</h1>

<?php if (!empty($error)): ?>
    <p><strong><?= h($error) ?></strong></p>
<?php endif; ?>

<form method="post" action="/login">
    <?= csrf_field() ?>
    <p>
        <label for="username">Username</label><br>
        <input type="text" id="username" name="username" autocomplete="username" required
               value="<?= h($_POST['username'] ?? '') ?>">
    </p>
    <p>
        <label for="password">Password</label><br>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
    </p>
    <p>
        <button type="submit">Log In</button>
    </p>
</form>
