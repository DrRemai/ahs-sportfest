<h1>Admin Panel</h1>

<!-- ===================================================================== -->
<h2>Users</h2>

<table border="1" cellpadding="4">
    <thead>
        <tr>
            <th>UID</th><th>Username</th><th>Display Name</th>
            <th>Admin</th><th>Created</th><th>Edit</th><th>Delete</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
            <td><?= (int)$u['id'] ?></td>
            <td><?= h($u['username']) ?></td>
            <td><?= h($u['display_name']) ?></td>
            <td><?= $u['is_admin'] ? 'Yes' : '' ?></td>
            <td><?= h(substr($u['created_at'], 0, 10)) ?></td>
            <td>
                <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/edit">
                    <?= csrf_field() ?>
                    <input type="text" name="display_name" value="<?= h($u['display_name']) ?>"
                           required maxlength="128" style="width:10em">
                    <input type="text" name="username" value="<?= h($u['username']) ?>"
                           required maxlength="64" style="width:8em">
                    <button type="submit">Save</button>
                </form>
            </td>
            <td>
                <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/delete"
                      onsubmit="return confirm('Delete user <?= h(addslashes($u['display_name'])) ?>?')">
                    <?= csrf_field() ?>
                    <button type="submit">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<hr>

<!-- ===================================================================== -->
<h2>Tournaments</h2>

<h3>Create Tournament on Behalf of Organiser</h3>
<form method="post" action="/admin/tournaments/create">
    <?= csrf_field() ?>
    <label>Name: <input type="text" name="name" required maxlength="256"></label> &nbsp;
    <label>Sport: <input type="text" name="sport" required maxlength="64"></label> &nbsp;
    <label>Format:
        <select name="format" required>
            <option value="">--</option>
            <option value="single_elimination">Single Elimination</option>
            <option value="double_elimination">Double Elimination</option>
            <option value="round_robin">Round Robin</option>
            <option value="swiss">Swiss</option>
        </select>
    </label> &nbsp;
    <label>Visibility:
        <select name="visibility">
            <option value="open">Open</option>
            <option value="invite_only">Invite Only</option>
        </select>
    </label> &nbsp;
    <label>Organiser:
        <select name="organiser_id" required>
            <option value="">-- Select user --</option>
            <?php foreach ($allUsers as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= h($u['display_name']) ?> (<?= h($u['username']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </label> &nbsp;
    <button type="submit">Create</button>
</form>

<h3>All Tournaments</h3>
<table border="1" cellpadding="4">
    <thead>
        <tr>
            <th>ID</th><th>Name</th><th>Sport</th><th>Status</th>
            <th>Visibility</th><th>Organiser</th><th>Featured</th><th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($tournaments as $t): ?>
        <tr>
            <td><?= (int)$t['id'] ?></td>
            <td><a href="/tournament/<?= (int)$t['id'] ?>"><?= h($t['name']) ?></a></td>
            <td><?= h($t['sport']) ?></td>
            <td><?= h(str_replace('_', ' ', $t['status'])) ?></td>
            <td><?= h($t['visibility']) ?></td>
            <td><?= h($t['organiser_name']) ?></td>
            <td>
                <form method="post" action="/admin/tournaments/<?= (int)$t['id'] ?>/feature"
                      style="display:inline">
                    <?= csrf_field() ?>
                    <?php if ($t['is_featured']): ?>
                        <button type="submit">Unfeature</button>
                    <?php else: ?>
                        <input type="hidden" name="is_featured" value="1">
                        <button type="submit">Feature</button>
                    <?php endif; ?>
                </form>
            </td>
            <td>
                <form method="post" action="/admin/tournaments/<?= (int)$t['id'] ?>/delete"
                      onsubmit="return confirm('Delete tournament <?= h(addslashes($t['name'])) ?>?')">
                    <?= csrf_field() ?>
                    <button type="submit">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<hr>

<!-- ===================================================================== -->
<h2>Role Management</h2>

<h3>Assign Role</h3>
<form method="post" action="/admin/roles/assign">
    <?= csrf_field() ?>
    <label>Tournament:
        <select name="tournament_id" required>
            <option value="">-- Select --</option>
            <?php foreach ($tournaments as $t): ?>
            <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label> &nbsp;
    <label>User:
        <select name="user_id" required>
            <option value="">-- Select --</option>
            <?php foreach ($allUsers as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= h($u['display_name']) ?> (<?= h($u['username']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </label> &nbsp;
    <label>Role:
        <select name="role" required>
            <option value="organiser">Organiser</option>
            <option value="staff">Staff</option>
        </select>
    </label> &nbsp;
    <button type="submit">Assign</button>
</form>

<h3>Revoke Role</h3>
<form method="post" action="/admin/roles/revoke">
    <?= csrf_field() ?>
    <label>Tournament:
        <select name="tournament_id" required>
            <option value="">-- Select --</option>
            <?php foreach ($tournaments as $t): ?>
            <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label> &nbsp;
    <label>User:
        <select name="user_id" required>
            <option value="">-- Select --</option>
            <?php foreach ($allUsers as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= h($u['display_name']) ?> (<?= h($u['username']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </label> &nbsp;
    <button type="submit">Revoke</button>
</form>

<hr>

<!-- ===================================================================== -->
<h2>Pending Reevaluations (All Tournaments)</h2>

<?php if (empty($reevaluations)): ?>
    <p>No pending reevaluations.</p>
<?php else: ?>
    <table border="1" cellpadding="4">
        <thead>
            <tr>
                <th>Tournament</th><th>Match</th><th>Requested By</th>
                <th>Proposed Score</th><th>Reason</th><th>Force Approve</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($reevaluations as $rr): ?>
            <tr>
                <td><a href="/tournament/<?= (int)$rr['tournament_id'] ?>"><?= h($rr['tournament_name']) ?></a></td>
                <td>
                    R<?= (int)$rr['round'] ?> M<?= (int)$rr['match_number'] ?>:
                    <?= h($rr['home_team_name']) ?> vs <?= h($rr['away_team_name']) ?>
                </td>
                <td><?= h($rr['requester_name']) ?></td>
                <td><?= (int)$rr['requested_home_score'] ?> – <?= (int)$rr['requested_away_score'] ?></td>
                <td><?= $rr['reason'] ? h($rr['reason']) : '—' ?></td>
                <td>
                    <form method="post"
                          action="/reevaluation/<?= (int)$rr['id'] ?>/force-approve"
                          onsubmit="return confirm('Force-approve this result? This bypasses the organiser.')">
                        <?= csrf_field() ?>
                        <button type="submit">Force Approve</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
