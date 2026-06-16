<?php
$byRound      = [];
foreach ($matches as $m) {
    $byRound[(int)$m['round']][] = $m;
}

$approvedTeams = array_values(array_filter($teams, fn($t) => $t['reg_status'] === 'approved'));
$pendingTeams  = array_values(array_filter($teams, fn($t) => $t['reg_status'] === 'pending'));

$openMatches = array_values(array_filter(
    $matches,
    fn($m) => !in_array($m['status'], ['accepted', 'bye'], true)
        && $m['home_team_id'] && $m['away_team_id']
));

$acceptedMatches = array_values(array_filter($matches, fn($m) => $m['status'] === 'accepted'));

$validStatuses = ['draft', 'in_progress', 'finalised', 'archived'];
$tid           = (int)$tournament['id'];
?>

<h1><?= h($tournament['name']) ?> <small>[Organiser View]</small></h1>

<p>
    <strong>Sport:</strong> <?= h($tournament['sport']) ?> &nbsp;|&nbsp;
    <strong>Format:</strong> <?= h(str_replace('_', ' ', $tournament['format'])) ?> &nbsp;|&nbsp;
    <strong>Status:</strong> <?= h(str_replace('_', ' ', $tournament['status'])) ?> &nbsp;|&nbsp;
    <strong>Visibility:</strong> <?= h($tournament['visibility']) ?> &nbsp;|&nbsp;
    <strong>Organiser:</strong> <?= h($tournament['organiser_name']) ?>
    <?php if ($tournament['is_featured']): ?>&nbsp;|&nbsp;<em>Featured</em><?php endif; ?>
</p>

<?php if (!empty($tournament['description'])): ?>
    <p><?= h($tournament['description']) ?></p>
<?php endif; ?>

<hr>

<!-- ===================================================================== -->
<h2>Teams</h2>

<?php if (empty($approvedTeams)): ?>
    <p>No approved teams yet.</p>
<?php else: ?>
    <table border="1" cellpadding="4">
        <thead><tr><th>#</th><th>Seed</th><th>Team</th></tr></thead>
        <tbody>
            <?php foreach ($approvedTeams as $i => $team): ?>
            <tr>
                <td><?= $i + 1 ?></td>
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

<?php if (!empty($pendingTeams)): ?>
<h3>Pending Registration Requests</h3>
<table border="1" cellpadding="4">
    <thead><tr><th>Team</th><th>Actions</th></tr></thead>
    <tbody>
        <?php foreach ($pendingTeams as $team): ?>
        <tr>
            <td><?= h($team['name']) ?></td>
            <td>
                <form method="post" action="/tournament/<?= $tid ?>/teams/approve" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                    <input type="hidden" name="action" value="approve">
                    <button type="submit">Approve</button>
                </form>
                <form method="post" action="/tournament/<?= $tid ?>/teams/approve" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                    <input type="hidden" name="action" value="reject">
                    <button type="submit">Reject</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h3>Add a Team Directly</h3>
<form method="post" action="/tournament/<?= $tid ?>/teams/add">
    <?= csrf_field() ?>
    <label>Team ID: <input type="number" name="team_id" min="1" required style="width:5em"></label>
    <button type="submit">Add &amp; Approve</button>
</form>

<hr>

<!-- ===================================================================== -->
<?php if ($tournament['status'] === 'draft' && !empty($approvedTeams)): ?>
<h2>Generate Bracket</h2>

<form method="post" action="/tournament/<?= $tid ?>/seed">
    <?= csrf_field() ?>
    <p>
        <label>Seeding mode:
            <select name="mode">
                <option value="manual">Manual (use order below)</option>
                <option value="random">Random shuffle</option>
            </select>
        </label>
    </p>
    <p>Drag to reorder, or just submit — team IDs are used in the order they appear.</p>
    <?php foreach ($approvedTeams as $i => $team): ?>
        <div>
            <label>
                #<?= $i + 1 ?>
                <input type="number" name="team_ids[]" value="<?= (int)$team['id'] ?>" style="width:5em" readonly>
                <?= h($team['name']) ?>
            </label>
        </div>
    <?php endforeach; ?>
    <p><button type="submit">Generate Bracket</button></p>
</form>

<hr>
<?php endif; ?>

<!-- ===================================================================== -->
<h2>Enter Scores</h2>

<?php if (empty($openMatches)): ?>
    <p>No open matches to score.</p>
<?php else: ?>
    <?php foreach ($openMatches as $m): ?>
    <form method="post" action="/match/<?= (int)$m['id'] ?>/result" style="margin-bottom:0.75em">
        <?= csrf_field() ?>
        <strong>R<?= (int)$m['round'] ?> M<?= (int)$m['match_number'] ?>:</strong>
        <?= h($m['home_team_name']) ?>
        <input type="number" name="home_score" min="0"
               value="<?= (int)($m['home_score'] ?? 0) ?>" style="width:4em" required>
        –
        <input type="number" name="away_score" min="0"
               value="<?= (int)($m['away_score'] ?? 0) ?>" style="width:4em" required>
        <?= h($m['away_team_name']) ?>
        <button type="submit">Save &amp; Accept</button>
    </form>
    <?php endforeach; ?>
<?php endif; ?>

<h3>Correct an Accepted Result</h3>

<?php if (empty($acceptedMatches)): ?>
    <p>No accepted results yet.</p>
<?php else: ?>
    <select id="reeval-select" onchange="showReevalForm(this.value)">
        <option value="">-- Pick a match --</option>
        <?php foreach ($acceptedMatches as $m): ?>
        <option value="<?= (int)$m['id'] ?>"
                data-mid="<?= (int)$m['id'] ?>"
                data-home="<?= h($m['home_team_name']) ?>"
                data-away="<?= h($m['away_team_name']) ?>"
                data-hs="<?= (int)$m['home_score'] ?>"
                data-as="<?= (int)$m['away_score'] ?>">
            R<?= (int)$m['round'] ?> M<?= (int)$m['match_number'] ?>:
            <?= h($m['home_team_name']) ?> <?= (int)$m['home_score'] ?>–<?= (int)$m['away_score'] ?> <?= h($m['away_team_name']) ?>
        </option>
        <?php endforeach; ?>
    </select>

    <div id="reeval-form" style="display:none; margin-top:0.5em">
        <form method="post" id="reeval-form-inner" action="">
            <?= csrf_field() ?>
            <span id="reeval-home-label"></span>
            <input type="number" name="home_score" id="reeval-home-score" min="0" style="width:4em" required>
            –
            <input type="number" name="away_score" id="reeval-away-score" min="0" style="width:4em" required>
            <span id="reeval-away-label"></span>
            <br>
            <label>Reason: <input type="text" name="reason" maxlength="512" style="width:30em"></label>
            <br>
            <button type="submit">Submit Correction</button>
        </form>
    </div>

    <script>
    function showReevalForm(matchId) {
        var sel  = document.getElementById('reeval-select');
        var wrap = document.getElementById('reeval-form');
        if (!matchId) { wrap.style.display = 'none'; return; }
        var opt = sel.options[sel.selectedIndex];
        document.getElementById('reeval-form-inner').action = '/match/' + matchId + '/result';
        document.getElementById('reeval-home-label').textContent = opt.dataset.home;
        document.getElementById('reeval-away-label').textContent = opt.dataset.away;
        document.getElementById('reeval-home-score').value = opt.dataset.hs;
        document.getElementById('reeval-away-score').value = opt.dataset.as;
        wrap.style.display = 'block';
    }
    </script>
<?php endif; ?>

<hr>

<!-- ===================================================================== -->
<?php if (!empty($reevaluations)): ?>
<h2>Pending Reevaluation Requests</h2>
<table border="1" cellpadding="4">
    <thead>
        <tr>
            <th>Match</th>
            <th>Requested By</th>
            <th>Proposed Score</th>
            <th>Reason</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($reevaluations as $rr): ?>
        <tr>
            <td>
                R<?= (int)$rr['round'] ?> M<?= (int)$rr['match_number'] ?>:
                <?= h($rr['home_team_name']) ?> vs <?= h($rr['away_team_name']) ?>
            </td>
            <td><?= h($rr['requester_name']) ?></td>
            <td><?= (int)$rr['requested_home_score'] ?> – <?= (int)$rr['requested_away_score'] ?></td>
            <td><?= $rr['reason'] ? h($rr['reason']) : '—' ?></td>
            <td>
                <form method="post" action="/reevaluation/<?= (int)$rr['id'] ?>/resolve" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="approve">
                    <button type="submit">Approve</button>
                </form>
                <form method="post" action="/reevaluation/<?= (int)$rr['id'] ?>/resolve" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject">
                    <label>Note: <input type="text" name="review_note" maxlength="255" style="width:12em"></label>
                    <button type="submit">Reject</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<hr>
<?php endif; ?>

<!-- ===================================================================== -->
<h2>Schedule &amp; Results</h2>

<?php if (empty($matches)): ?>
    <p>No bracket generated yet.</p>
<?php else: ?>
    <?php foreach ($byRound as $round => $roundMatches): ?>
        <h3>Round <?= (int)$round ?></h3>
        <table border="1" cellpadding="4">
            <thead>
                <tr>
                    <th>Match</th><th>Home</th><th>Score</th>
                    <th>Away</th><th>Status</th><th>Winner</th>
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

<!-- ===================================================================== -->
<h2>Tournament Settings</h2>

<form method="post" action="/tournament/<?= $tid ?>/settings">
    <?= csrf_field() ?>
    <p>
        <label>Name<br>
            <input type="text" name="name" required maxlength="256"
                   value="<?= h($tournament['name']) ?>">
        </label>
    </p>
    <p>
        <label>Sport<br>
            <input type="text" name="sport" required maxlength="64"
                   value="<?= h($tournament['sport']) ?>">
        </label>
    </p>
    <p>
        <label>Status<br>
            <select name="status" required>
                <?php foreach ($validStatuses as $s): ?>
                <option value="<?= $s ?>"<?= $tournament['status'] === $s ? ' selected' : '' ?>>
                    <?= ucfirst(str_replace('_', ' ', $s)) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </label>
    </p>
    <p>
        <label>Visibility<br>
            <select name="visibility">
                <option value="open"<?= $tournament['visibility'] === 'open' ? ' selected' : '' ?>>Open</option>
                <option value="invite_only"<?= $tournament['visibility'] === 'invite_only' ? ' selected' : '' ?>>Invite Only</option>
            </select>
        </label>
    </p>
    <p>
        <label>Description<br>
            <textarea name="description" rows="3" cols="60"><?= h($tournament['description'] ?? '') ?></textarea>
        </label>
    </p>
    <p><button type="submit">Save Settings</button></p>
</form>
