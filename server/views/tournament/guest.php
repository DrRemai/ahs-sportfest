<?php
$byRound = [];
foreach ($matches as $m) {
    $byRound[(int)$m['round']][] = $m;
}

$approvedTeams = array_filter($teams, fn($t) => $t['reg_status'] === 'approved');
?>

<h1><?= h($tournament['name']) ?></h1>

<p>
    <strong>Sport:</strong> <?= h($tournament['sport']) ?> &nbsp;|&nbsp;
    <strong>Format:</strong> <?= h(str_replace('_', ' ', $tournament['format'])) ?> &nbsp;|&nbsp;
    <strong>Status:</strong> <?= h(str_replace('_', ' ', $tournament['status'])) ?> &nbsp;|&nbsp;
    <strong>Visibility:</strong> <?= h($tournament['visibility']) ?> &nbsp;|&nbsp;
    <strong>Organiser:</strong> <?= h($tournament['organiser_name']) ?>
</p>

<?php if (!empty($tournament['description'])): ?>
    <p><?= h($tournament['description']) ?></p>
<?php endif; ?>

<hr>

<h2>Teams</h2>

<?php if (empty($approvedTeams)): ?>
    <p>No teams registered.</p>
<?php else: ?>
    <table border="1" cellpadding="4">
        <thead>
            <tr><th>#</th><th>Seed</th><th>Team</th></tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach ($approvedTeams as $team): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= $team['seed'] !== null ? (int)$team['seed'] : '—' ?></td>
                <td>
                    <a href="/team/<?= (int)$team['id'] ?>"><?= h($team['name']) ?></a>
                    <?= $team['short_name'] ? ' (' . h($team['short_name']) . ')' : '' ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<hr>

<h2>Schedule &amp; Results</h2>

<?php if (empty($matches)): ?>
    <p>No bracket generated yet.</p>
<?php else: ?>
    <?php foreach ($byRound as $round => $roundMatches): ?>
        <h3>Round <?= (int)$round ?></h3>
        <table border="1" cellpadding="4">
            <thead>
                <tr>
                    <th>Match</th>
                    <th>Home</th>
                    <th>Score</th>
                    <th>Away</th>
                    <th>Status</th>
                    <th>Winner</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roundMatches as $m): ?>
                <tr>
                    <td><?= (int)$m['match_number'] ?></td>
                    <td><?= $m['home_team_name'] ? h($m['home_team_name']) : 'TBD' ?></td>
                    <td>
                        <?php if (in_array($m['status'], ['accepted', 'disputed'], true)): ?>
                            <?= (int)$m['home_score'] ?> – <?= (int)$m['away_score'] ?>
                        <?php else: ?>
                            vs
                        <?php endif; ?>
                    </td>
                    <td><?= $m['away_team_name'] ? h($m['away_team_name']) : 'TBD' ?></td>
                    <td><?= h($m['status']) ?></td>
                    <td><?= $m['winner_name'] ? h($m['winner_name']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
<?php endif; ?>

<hr>

<h2>Bracket</h2>

<?php if (empty($matches)): ?>
    <p>No bracket data.</p>
<?php else: ?>
    <?php foreach ($byRound as $round => $roundMatches): ?>
        <strong>Round <?= (int)$round ?></strong>
        <ul>
            <?php foreach ($roundMatches as $m): ?>
            <li>
                Match <?= (int)$m['match_number'] ?>:
                <?= $m['home_team_name'] ? h($m['home_team_name']) : 'TBD' ?>
                <?php if (in_array($m['status'], ['accepted', 'disputed'], true)): ?>
                    <strong><?= (int)$m['home_score'] ?> – <?= (int)$m['away_score'] ?></strong>
                <?php else: ?>
                    vs
                <?php endif; ?>
                <?= $m['away_team_name'] ? h($m['away_team_name']) : 'TBD' ?>
                <?php if ($m['winner_name']): ?>
                    → <em>Winner: <?= h($m['winner_name']) ?></em>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!current_user()): ?>
    <hr>
    <p><a href="/login">Log in</a> to manage this tournament.</p>
<?php endif; ?>
