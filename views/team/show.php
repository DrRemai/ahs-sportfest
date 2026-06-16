<h1><?= h($team['name']) ?></h1>

<?php if ($team['short_name']): ?>
    <p><strong>Short name:</strong> <?= h($team['short_name']) ?></p>
<?php endif; ?>

<p><strong>Owner:</strong> <?= h($team['owner_name']) ?></p>

<hr>

<h2>Tournament History</h2>

<?php if (empty($participations)): ?>
    <p>This team has not participated in any public tournaments.</p>
<?php else: ?>
    <table border="1" cellpadding="4">
        <thead>
            <tr>
                <th>Tournament</th>
                <th>Sport</th>
                <th>Format</th>
                <th>Status</th>
                <th>Standing</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($participations as $p): ?>
            <tr>
                <td><a href="/tournament/<?= (int)$p['id'] ?>"><?= h($p['name']) ?></a></td>
                <td><?= h($p['sport']) ?></td>
                <td><?= h(str_replace('_', ' ', $p['format'])) ?></td>
                <td><?= h(str_replace('_', ' ', $p['status'])) ?></td>
                <td>
                    <?php if ($p['status'] === 'finalised'): ?>
                        <?= $p['standing'] ? h($p['standing']) : 'Participated' ?>
                    <?php else: ?>
                        In progress
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<p><a href="/">Back to tournaments</a></p>
