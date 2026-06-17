<h1>Tournaments</h1>

<form method="get" action="/">
    <label>Search: <input type="text" name="q" value="<?= h($q) ?>" placeholder="Tournament name"></label>
    &nbsp;
    <label>Sport: <input type="text" name="sport" value="<?= h($sport) ?>" placeholder="e.g. Football"></label>
    &nbsp;
    <label>Team: <input type="text" name="team" value="<?= h($team) ?>" placeholder="Team name"></label>
    &nbsp;
    <button type="submit">Filter</button>
    <?php if ($q !== '' || $sport !== '' || $team !== ''): ?>
        &nbsp;<a href="/">Clear</a>
    <?php endif; ?>
</form>

<?php if (current_user()): ?>
    <p><a href="/tournament/create">+ Create Tournament</a></p>
<?php endif; ?>

<?php if (empty($tournaments)): ?>
    <p>No tournaments found.</p>
<?php else: ?>
    <table border="1" cellpadding="4">
        <thead>
            <tr>
                <th>Name</th>
                <th>Sport</th>
                <th>Format</th>
                <th>Status</th>
                <th>Visibility</th>
                <th>Organiser</th>
                <th>Featured</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tournaments as $t): ?>
            <tr>
                <td>
                    <a href="/tournament/<?= (int)$t['id'] ?>"><?= h($t['name']) ?></a>
                </td>
                <td><?= h($t['sport']) ?></td>
                <td><?= h(str_replace('_', ' ', $t['format'])) ?></td>
                <td><?= h(str_replace('_', ' ', $t['status'])) ?></td>
                <td><?= h($t['visibility']) ?></td>
                <td><?= h($t['organiser_name']) ?></td>
                <td><?= $t['is_featured'] ? 'Yes' : '' ?></td>
                <td><?= h(substr($t['created_at'], 0, 10)) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
