<h1>Create Tournament</h1>

<?php if (!empty($error)): ?>
    <p><strong><?= h($error) ?></strong></p>
<?php endif; ?>

<form method="post" action="/tournament/create">
    <?= csrf_field() ?>
    <p>
        <label for="name">Tournament Name</label><br>
        <input type="text" id="name" name="name" required maxlength="256"
               value="<?= h($_POST['name'] ?? '') ?>">
    </p>
    <p>
        <label for="sport">Sport</label><br>
        <input type="text" id="sport" name="sport" required maxlength="64"
               value="<?= h($_POST['sport'] ?? '') ?>">
    </p>
    <p>
        <label for="format">Format</label><br>
        <select id="format" name="format" required>
            <option value="">-- Select --</option>
            <?php
            $formats = [
                'single_elimination' => 'Single Elimination',
                'double_elimination' => 'Double Elimination',
                'round_robin'        => 'Round Robin',
                'swiss'              => 'Swiss',
            ];
            foreach ($formats as $val => $label):
                $sel = (($_POST['format'] ?? '') === $val) ? ' selected' : '';
            ?>
            <option value="<?= h($val) ?>"<?= $sel ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
    </p>
    <p>
        <button type="submit">Create</button>
    </p>
</form>
