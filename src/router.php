<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// API dispatch
// ---------------------------------------------------------------------------

function dispatch_api(): void
{
    $method = $_SERVER['REQUEST_METHOD'];
    $full   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $uri    = rtrim(substr($full, 4), '/') ?: '/'; // strip /api prefix

    $static = [
        'GET /me'                            => 'api_me',
        'PATCH /me'                          => 'api_me_update',
        'GET /me/teams'                      => 'api_me_teams',
        'POST /login'                        => 'api_login',
        'POST /logout'                       => 'api_logout',
        // SCHOOL: registration disabled for school deployment — restore 'POST /register' => 'api_register' to re-enable
        'POST /teams/create'                 => 'api_teams_create',
        'GET /teams/eligible'                => 'api_teams_eligible_by_sport',
        'GET /teams/search'                  => 'api_teams_search',
        'GET /tournaments'                   => 'api_tournaments',
        'GET /users/search'                  => 'api_users_search',
        'POST /tournaments/create'           => 'api_tournament_create',
        'GET /notifications'                 => 'api_notifications',
        'POST /notifications/read'           => 'api_notifications_read',
        'GET /admin/users'                   => 'api_admin_users',
        'GET /admin/tournaments'             => 'api_admin_tournaments',
        'GET /admin/reevaluations'           => 'api_admin_reevaluations',
        'POST /admin/roles/assign'           => 'api_admin_role_assign',
        'POST /admin/roles/revoke'           => 'api_admin_role_revoke',
        'POST /admin/tournaments/create'     => 'api_admin_tournament_create',
        'GET /admin/teams'                   => 'api_admin_teams',
    ];

    $key = "$method $uri";
    if (isset($static[$key])) {
        $static[$key]();
        return;
    }

    $dynamic = [
        ['GET',  '#^/tournament/(\d+)$#',                 'api_tournament_view'],
        ['GET',  '#^/tournament/(\d+)/eligible-teams$#', 'api_tournament_eligible_teams'],
        ['GET',  '#^/tournament/(\d+)/roles$#',          'api_tournament_roles'],
        ['POST', '#^/tournament/(\d+)/roles/assign$#',   'api_tournament_roles_assign'],
        ['POST', '#^/tournament/(\d+)/roles/revoke$#',   'api_tournament_roles_revoke'],
        ['GET',  '#^/tournament/(\d+)/bracket$#',         'api_tournament_bracket'],
        ['GET',  '#^/tournament/(\d+)/standings$#',       'api_tournament_standings'],
        ['POST', '#^/tournament/(\d+)/settings$#',        'api_tournament_settings'],
        ['POST', '#^/tournament/(\d+)/config$#',          'api_tournament_config'],
        ['POST', '#^/tournament/(\d+)/seed$#',            'api_tournament_seed'],
        ['POST', '#^/tournament/(\d+)/teams/add$#',       'api_tournament_teams_add'],
        ['POST', '#^/tournament/(\d+)/teams/request$#',   'api_tournament_teams_request'],
        ['POST', '#^/tournament/(\d+)/teams/approve$#',     'api_tournament_teams_approve'],
        ['POST', '#^/tournament/(\d+)/teams/approve-all$#', 'api_tournament_teams_approve_all'],
        ['POST', '#^/match/(\d+)/result$#',               'api_match_result'],
        ['POST', '#^/reevaluation/(\d+)/resolve$#',       'api_reevaluation_resolve'],
        ['POST', '#^/reevaluation/(\d+)/force-approve$#', 'api_reevaluation_force_approve'],
        ['GET',  '#^/team/(\d+)$#',                       'api_team_view'],
        ['POST', '#^/admin/users/(\d+)/edit$#',           'api_admin_user_edit'],
        ['POST', '#^/admin/users/(\d+)/delete$#',         'api_admin_user_delete'],
        ['POST', '#^/admin/tournaments/(\d+)/delete$#',   'api_admin_tournament_delete'],
        ['POST',   '#^/admin/tournaments/(\d+)/feature$#',  'api_admin_tournament_feature'],
        ['PATCH',  '#^/team/(\d+)$#',                       'api_team_update'],
        ['POST',   '#^/team/(\d+)/archive$#',               'api_team_archive'],
        ['POST',   '#^/team/(\d+)/members/add$#',           'api_team_members_add'],
        ['DELETE', '#^/team/(\d+)/members/(\d+)$#',         'api_team_members_delete'],
        ['POST',   '#^/admin/team/(\d+)/status$#',          'api_admin_team_status'],
        // SCHOOL: admin team hard delete — cascades members, tournament_teams, match refs, name claim
        ['POST',   '#^/admin/team/(\d+)/delete$#',          'api_admin_team_delete'],
        // SCHOOL: live match toggle — organiser/staff/admin only; SSE fires via DB trigger
        ['POST',   '#^/match/(\d+)/toggle-live$#',          'api_match_toggle_live'],
    ];

    foreach ($dynamic as [$dm, $pattern, $handler]) {
        if ($method === $dm && preg_match($pattern, $uri, $m)) {
            $args = array_map('intval', array_slice($m, 1));
            $handler(...$args);
            return;
        }
    }

    api_error('Not found.', 404);
}

// ---------------------------------------------------------------------------
// Session / identity
// ---------------------------------------------------------------------------

function api_me(): void
{
    $user = current_user();
    $username = null;
    if ($user) {
        $row = db()->prepare('SELECT username FROM users WHERE id = ?');
        $row->execute([$user['uid']]);
        $username = $row->fetchColumn() ?: null;
    }
    api_ok([
        'user' => $user ? [
            'uid'          => $user['uid'],
            'username'     => $username,
            'display_name' => $user['display_name'],
            'is_admin'     => $user['is_admin'],
        ] : null,
        'csrf' => csrf_token(),
    ]);
}

function api_me_update(): void
{
    $user = api_require_auth();
    api_verify_csrf();

    $body        = api_body();
    $displayName = trim($body['display_name'] ?? '');

    $fields = [];
    if ($displayName === '') {
        $fields['display_name'] = 'Display name is required.';
    } elseif (mb_strlen($displayName) > 64) {
        $fields['display_name'] = 'Display name must be 64 characters or fewer.';
    }
    if (!empty($fields)) api_validation_error($fields);

    db()->prepare('UPDATE users SET display_name=?, updated_at=NOW() WHERE id=?')
         ->execute([$displayName, $user['uid']]);

    // Keep session in sync so current_user() returns fresh name immediately
    $_SESSION['user']['display_name'] = $displayName;

    api_ok(['display_name' => $displayName]);
}

function api_me_teams(): void
{
    $user = api_require_auth();

    $stmt = db()->prepare(
        "SELECT t.id, t.name, t.sport, t.status,
                COUNT(DISTINCT tt.id) FILTER (WHERE tt.status = 'approved') AS tournament_count,
                COUNT(DISTINCT tm.id) AS member_count
         FROM teams t
         LEFT JOIN tournament_teams tt ON tt.team_id = t.id
         LEFT JOIN team_members tm ON tm.team_id = t.id
         WHERE t.owner_uid = ?
         GROUP BY t.id
         ORDER BY CASE WHEN t.status = 'active' THEN 0 ELSE 1 END, t.name ASC"
    );
    $stmt->execute([$user['uid']]);
    $teams = $stmt->fetchAll();

    foreach ($teams as &$t) {
        $t['tournament_count'] = (int)$t['tournament_count'];
        $t['member_count']     = (int)$t['member_count'];
    }
    unset($t);

    api_ok(['teams' => $teams]);
}

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

function api_login(): void
{
    $body     = api_body();
    $username = trim($body['username'] ?? '');
    $password = $body['password']      ?? '';

    if ($username === '' || $password === '') {
        api_error('Username and password are required.');
    }

    $stmt = db()->prepare(
        'SELECT id, username, display_name, password_hash, is_admin FROM users WHERE lower(username) = lower(?)'
    );
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['password_hash'])) {
        api_error('Invalid credentials.', 401);
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'uid'          => (int)$row['id'],
        'display_name' => $row['display_name'],
        'is_admin'     => (bool)$row['is_admin'],
    ];
    unset($_SESSION['csrf_token']);

    api_ok([
        'user' => [
            'uid'          => (int)$row['id'],
            'username'     => $row['username'],
            'display_name' => $row['display_name'],
            'is_admin'     => (bool)$row['is_admin'],
        ],
        'csrf' => csrf_token(),
    ]);
}

function api_logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    api_ok(null);
}

// Disabled for school deployment — re-enable by restoring the /register route and nav link
function api_register(): void
{
    // No CSRF required — unauthenticated endpoint; no session exists yet.
    $body        = api_body();
    $username    = trim($body['username']     ?? '');
    $password    = $body['password']          ?? '';
    $displayName = trim($body['display_name'] ?? '');

    $fields = [];

    if ($username === '') {
        $fields['username'] = 'Username is required.';
    } elseif (!preg_match('/^[a-z0-9_-]{3,32}$/', $username)) {
        $fields['username'] = 'Must be 3–32 characters: a–z, 0–9, hyphens and underscores only.';
    }

    if ($password === '') {
        $fields['password'] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $fields['password'] = 'Must be at least 8 characters.';
    } elseif (!preg_match('/\d/', $password)) {
        $fields['password'] = 'Must contain at least one number.';
    }

    if ($displayName === '') {
        $fields['display_name'] = 'Display name is required.';
    } elseif (mb_strlen($displayName) > 64) {
        $fields['display_name'] = 'Must be 64 characters or fewer.';
    }

    if (!empty($fields)) api_validation_error($fields);

    // Check uniqueness before hashing to avoid wasted bcrypt work
    $stmt = db()->prepare('SELECT id FROM users WHERE lower(username) = lower(?)');
    $stmt->execute([$username]);
    if ($stmt->fetch()) api_error('username_taken', 422);

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = db()->prepare(
        'INSERT INTO users (username, display_name, password_hash) VALUES (?, ?, ?) RETURNING id'
    );
    $stmt->execute([$username, $displayName, $hash]);
    $userId = (int)$stmt->fetchColumn();

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'uid'          => $userId,
        'display_name' => $displayName,
        'is_admin'     => false,
    ];
    unset($_SESSION['csrf_token']);

    http_response_code(201);
    api_ok([
        'user' => ['username' => $username, 'display_name' => $displayName, 'is_admin' => false],
        'csrf' => csrf_token(),
    ]);
}

// ---------------------------------------------------------------------------
// Teams
// ---------------------------------------------------------------------------

function api_teams_create(): void
{
    $user = api_require_auth();
    api_verify_csrf();

    $body  = api_body();
    $name  = trim($body['name']  ?? '');
    $sport = trim($body['sport'] ?? '');

    $fields = [];

    if ($name === '') {
        $fields['name'] = 'Team name is required.';
    } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 48) {
        $fields['name'] = 'Team name must be 2–48 characters.';
    }

    if ($sport === '') {
        $fields['sport'] = 'Sport is required.';
    } elseif (mb_strlen($sport) < 2 || mb_strlen($sport) > 32) {
        $fields['sport'] = 'Sport must be 2–32 characters.';
    }

    if (!empty($fields)) api_validation_error($fields);

    $members = array_values(array_filter(
        array_map(fn($m) => mb_substr(trim((string)($m ?? '')), 0, 80), (array)($body['members'] ?? [])),
        fn($m) => $m !== ''
    ));
    $members = array_slice($members, 0, 50);

    $db = db();
    $db->beginTransaction();
    try {
        // SCHOOL: global name-claim check — a name belongs to whoever claimed it first.
        // The same user can reuse their own claimed name across different sports.
        $claimStmt = $db->prepare(
            'SELECT owner_uid FROM team_name_claims WHERE name_lower = lower(?)'
        );
        $claimStmt->execute([$name]);
        $claim = $claimStmt->fetch();

        if ($claim && (int)$claim['owner_uid'] !== $user['uid']) {
            $db->rollBack();
            api_error('team_name_claimed', 422);
        }

        if (!$claim) {
            $db->prepare(
                'INSERT INTO team_name_claims (name_lower, owner_uid) VALUES (lower(?), ?)'
            )->execute([$name, $user['uid']]);
        }

        $stmt = $db->prepare(
            'INSERT INTO teams (name, sport, owner_uid) VALUES (?, ?, ?) RETURNING id, name, sport'
        );
        $stmt->execute([$name, $sport, $user['uid']]);
        $team = $stmt->fetch();

        if (!empty($members)) {
            $mStmt = $db->prepare('INSERT INTO team_members (team_id, name) VALUES (?, ?)');
            foreach ($members as $memberName) {
                $mStmt->execute([(int)$team['id'], $memberName]);
            }
        }

        $db->commit();
    } catch (\PDOException $e) {
        $db->rollBack();
        throw $e;
    }

    // PERF: new team may appear in eligible-teams lists
    Cache::deletePattern('tournaments:list:');

    http_response_code(201);
    api_ok($team);
}

// ---------------------------------------------------------------------------
// All active teams matching a sport — used by the tournament-creation wizard
// (step 5) where no tournament ID exists yet.
// Decision: wizard cannot use /tournament/{id}/eligible-teams because the
// tournament hasn't been created when the organiser is seeding it.
// ---------------------------------------------------------------------------
function api_teams_eligible_by_sport(): void
{
    api_require_auth();
    $sport = trim($_GET['sport'] ?? '');
    if ($sport === '') api_error('sport parameter required.', 400);

    $stmt = db()->prepare(
        "SELECT t.id, t.name, t.sport, u.display_name AS owner_display_name
         FROM teams t
         JOIN users u ON u.id = t.owner_uid
         WHERE lower(t.sport) = lower(?) AND t.status = 'active'
         ORDER BY u.display_name, t.name"
    );
    $stmt->execute([$sport]);
    api_ok($stmt->fetchAll());
}

function api_teams_search(): void
{
    api_require_auth();
    $sport = trim($_GET['sport'] ?? '');
    $q     = trim($_GET['q']     ?? '');
    if ($sport === '') api_error('sport parameter required.', 422);

    $sql    = "SELECT t.id, t.name, t.sport, u.display_name AS owner_display_name
               FROM teams t
               JOIN users u ON u.id = t.owner_uid
               WHERE t.status = 'active'
               AND lower(t.sport) = lower(?)";
    $params = [$sport];

    if ($q !== '') {
        $sql     .= ' AND lower(t.name) LIKE lower(?)';
        $params[] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY t.name ASC LIMIT 50';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    api_ok($stmt->fetchAll());
}

// ---------------------------------------------------------------------------
// Tournaments list
// ---------------------------------------------------------------------------
function api_tournaments(): void
{
    $user    = current_user();
    $q       = trim($_GET['q']     ?? '');
    $sport   = trim($_GET['sport'] ?? '');
    $page    = max(0, (int)($_GET['page'] ?? 0));
    $isAdmin = $user && $user['is_admin'];
    $cacheKey = 'tournaments:list:' . md5(serialize([
        'adm' => $isAdmin, 'q' => $q, 'sp' => $sport, 'pg' => $page,
    ]));
    $result = Cache::remember($cacheKey, 20, function() use ($isAdmin, $q, $sport, $page) {
        $where  = [];
        $params = [];

        if (!$isAdmin) {
            $where[] = "t.status NOT IN ('draft', 'archived')";
        }
        if ($q !== '') {
            $where[]  = 'lower(t.name) LIKE lower(?)';
            $params[] = '%' . $q . '%';
        }
        if ($sport !== '') {
            $where[]  = 'lower(t.sport) LIKE lower(?)';
            $params[] = '%' . $sport . '%';
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset      = $page * 50;

        $sql = "SELECT t.id, t.name, t.sport, t.format, t.status, t.visibility,
                       t.created_at,
                       u.display_name AS organiser_name,
                       COUNT(*) OVER() AS total_count
                FROM tournaments t
                JOIN users u ON u.id = t.created_by
                $whereClause
                ORDER BY t.created_at ASC
                LIMIT 50 OFFSET ?";

        $params[] = $offset;
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows  = $stmt->fetchAll();
        $total = !empty($rows) ? (int)$rows[0]['total_count'] : 0;
        foreach ($rows as &$row) unset($row['total_count']);
        unset($row);

        return [
            'tournaments' => $rows,
            'meta'        => ['total' => $total, 'page' => $page, 'limit' => 50],
        ];
    });

    api_ok($result);
}


// ---------------------------------------------------------------------------
// Tournament view
// ---------------------------------------------------------------------------

function api_tournament_view(int $id): void
{
    $stmt = db()->prepare(
        'SELECT t.*, u.display_name AS organiser_name
         FROM tournaments t
         JOIN users u ON u.id = t.created_by
         WHERE t.id = ?'
    );
    $stmt->execute([$id]);
    $tournament = $stmt->fetch();

    if (!$tournament) api_error('Tournament not found.', 404);

    $role = tournament_role($id);

    if (in_array($tournament['status'], ['draft', 'archived'], true)
        && !in_array($role, ['organiser', 'admin', 'staff'], true))
    {
        api_error('Tournament not found.', 404);
    }

    $teams = db()->prepare(
        'SELECT tt.seed, tt.status AS reg_status, te.id, te.name, te.short_name
         FROM tournament_teams tt
         JOIN teams te ON te.id = tt.team_id
         WHERE tt.tournament_id = ?
         ORDER BY tt.seed ASC NULLS LAST, te.name ASC'
    );
    $teams->execute([$id]);

    $matches = db()->prepare(
        'SELECT m.*,
                ht.name  AS home_team_name,
                at2.name AS away_team_name,
                wt.name  AS winner_name
         FROM matches m
         LEFT JOIN teams ht  ON ht.id  = m.home_team_id
         LEFT JOIN teams at2 ON at2.id = m.away_team_id
         LEFT JOIN teams wt  ON wt.id  = m.winner_id
         WHERE m.tournament_id = ?
         ORDER BY m.round ASC, m.match_number ASC'
    );
    $matches->execute([$id]);

    // SCHOOL: live_matches — quick query for currently-live matches in this tournament
    $liveStmt = db()->prepare(
        'SELECT m.id, m.round, m.home_score, m.away_score,
                ht.name  AS home_team_name,
                at2.name AS away_team_name
         FROM matches m
         LEFT JOIN teams ht  ON ht.id  = m.home_team_id
         LEFT JOIN teams at2 ON at2.id = m.away_team_id
         WHERE m.tournament_id = ? AND m.is_live = true
         ORDER BY m.round ASC, m.match_number ASC'
    );
    $liveStmt->execute([$id]);

    $data = [
        'tournament'   => $tournament,
        'teams'        => $teams->fetchAll(),
        'matches'      => $matches->fetchAll(),
        'live_matches' => $liveStmt->fetchAll(),
        'role'         => $role,
    ];

    // my_team: the logged-in user's team registration in this tournament.
    // Decision: resolved server-side to avoid an extra round-trip from the
    // client. Returns the first match (by registration date) if a user owns
    // multiple teams in the same tournament.
    $myTeam = null;
    if ($currentUser = current_user()) {
        $myTeamStmt = db()->prepare(
            'SELECT tt.team_id, te.name AS team_name, tt.status
             FROM tournament_teams tt
             JOIN teams te ON te.id = tt.team_id
             WHERE tt.tournament_id = ? AND te.owner_uid = ?
             ORDER BY tt.registered_at DESC
             LIMIT 1'
        );
        $myTeamStmt->execute([$id, $currentUser['uid']]);
        $myTeam = $myTeamStmt->fetch() ?: null;
    }
    $data['my_team'] = $myTeam;

    if (in_array($role, ['organiser', 'admin'], true)) {
        $reval = db()->prepare(
            "SELECT rr.*, m.round, m.match_number,
                    ht.name  AS home_team_name,
                    at2.name AS away_team_name,
                    u.display_name AS requester_name
             FROM reevaluation_requests rr
             JOIN matches m     ON m.id   = rr.match_id
             LEFT JOIN teams ht  ON ht.id  = m.home_team_id
             LEFT JOIN teams at2 ON at2.id = m.away_team_id
             JOIN users u       ON u.id   = rr.requested_by
             WHERE rr.tournament_id = ? AND rr.status = 'pending'
             ORDER BY rr.created_at ASC"
        );
        $reval->execute([$id]);
        $data['reevaluations'] = $reval->fetchAll();
    }

    api_ok($data);
}

// ---------------------------------------------------------------------------
// Eligible teams for organiser team-adding UI
// ---------------------------------------------------------------------------

function api_tournament_eligible_teams(int $id): void
{
    $role = tournament_role($id);
    if (!in_array($role, ['organiser', 'admin'], true)) api_error('Forbidden.', 403);

    $stmt = db()->prepare(
        "SELECT t.id, t.name, t.sport, u.display_name AS owner_display_name
         FROM teams t
         JOIN users u ON u.id = t.owner_uid
         WHERE lower(t.sport) = (SELECT lower(sport) FROM tournaments WHERE id = ?)
           AND t.status = 'active'
           AND t.id NOT IN (
               SELECT team_id FROM tournament_teams
               WHERE tournament_id = ? AND status IN ('approved', 'pending')
           )
         ORDER BY u.display_name, t.name"
    );
    $stmt->execute([$id, $id]);
    api_ok($stmt->fetchAll());
}

// ---------------------------------------------------------------------------
// Bracket
// ---------------------------------------------------------------------------

function api_tournament_bracket(int $id): void
{
    // PERF: cache match data for 10s; role/canEnterResults are user-specific and computed live
    $data = Cache::remember("tournament:{$id}:bracket", 10, function() use ($id) {
        $tStmt = db()->prepare('SELECT format FROM tournaments WHERE id = ?');
        $tStmt->execute([$id]);
        $t = $tStmt->fetch();
        if (!$t) return false; // sentinel: tournament not found

        $stmt = db()->prepare(
            'SELECT m.*,
                    ht.name  AS home_team_name,
                    at2.name AS away_team_name,
                    wt.name  AS winner_name
             FROM matches m
             LEFT JOIN teams ht  ON ht.id  = m.home_team_id
             LEFT JOIN teams at2 ON at2.id = m.away_team_id
             LEFT JOIN teams wt  ON wt.id  = m.winner_id
             WHERE m.tournament_id = ?
             ORDER BY m.bracket_side ASC, m.round ASC, m.match_number ASC'
        );
        $stmt->execute([$id]);
        return ['format' => $t['format'], 'matches' => $stmt->fetchAll()];
    });

    if ($data === false || $data === null) api_error('Tournament not found.', 404);

    $role            = tournament_role($id);
    $canEnterResults = in_array($role, ['organiser', 'admin', 'staff'], true);
    $format          = $data['format'];
    $matches         = $data['matches'];

    if ($format === 'double_elim') {
        $winRounds = [];
        $lbRounds  = [];
        $gf        = null;

        foreach ($matches as $m) {
            $side = $m['bracket_side'] ?? 'winners';
            if ($side === 'grand_final') {
                $gf = $m;
            } elseif ($side === 'losers') {
                $lbRounds[(int)$m['round']][] = $m;
            } else {
                $winRounds[(int)$m['round']][] = $m;
            }
        }

        api_ok([
            'format'          => $format,
            'winners_rounds'  => array_values(array_map(fn($ms) => ['matches' => $ms], $winRounds)),
            'losers_rounds'   => array_values(array_map(fn($ms) => ['matches' => $ms], $lbRounds)),
            'grand_final'     => $gf,
            'canEnterResults' => $canEnterResults,
        ]);
        return;
    }

    // multi_stage: separate group-phase matches (bracket_side A/B) from knockout
    if ($format === 'multi_stage') {
        $groups       = [];
        $knockoutRnds = [];

        foreach ($matches as $m) {
            $side = $m['bracket_side'] ?? 'none';
            if (strlen($side) === 1 && ctype_upper($side[0])) {
                $groups[$side][(int)$m['round']][] = $m;
            } else {
                $knockoutRnds[(int)$m['round']][] = $m;
            }
        }

        $groupsOut = [];
        foreach ($groups as $letter => $rnds) {
            ksort($rnds);
            $groupsOut[$letter] = array_values(array_map(fn($ms) => ['matches' => $ms], $rnds));
        }
        ksort($knockoutRnds);

        api_ok([
            'format'          => $format,
            'groups'          => $groupsOut,
            'knockout_rounds' => array_values(array_map(fn($ms) => ['matches' => $ms], $knockoutRnds)),
            'canEnterResults' => $canEnterResults,
        ]);
        return;
    }

    // single_elim, round_robin, swiss: group by round
    $rounds = [];
    foreach ($matches as $m) {
        $rounds[(int)$m['round']][] = $m;
    }

    api_ok([
        'format'          => $format,
        'rounds'          => array_values(array_map(fn($ms) => ['matches' => $ms], $rounds)),
        'canEnterResults' => $canEnterResults,
    ]);
}

// ---------------------------------------------------------------------------
// Tournament settings
// ---------------------------------------------------------------------------

function api_tournament_settings(int $id): void
{
    api_require_auth();
    api_verify_csrf();

    $role = tournament_role($id);
    if (!in_array($role, ['organiser', 'admin'], true)) api_error('Forbidden.', 403);

    $body        = api_body();
    $name        = trim($body['name']        ?? '');
    $sport       = trim($body['sport']       ?? '');
    $status      = trim($body['status']      ?? '');
    $visibility  = trim($body['visibility']  ?? 'open');
    $description = trim($body['description'] ?? '');

    if ($name === '' || $sport === '') api_error('Name and sport are required.');

    $validStatuses = ['draft', 'in_progress', 'finalised', 'archived'];
    if (!in_array($status, $validStatuses, true)) api_error('Invalid status.');
    if (!in_array($visibility, ['open', 'invite_only'], true)) $visibility = 'open';

    $prevStmt = db()->prepare('SELECT status FROM tournaments WHERE id = ?');
    $prevStmt->execute([$id]);
    $prev = $prevStmt->fetch();

    db()->prepare(
        'UPDATE tournaments SET name=?, sport=?, status=?, visibility=?, description=?, updated_at=NOW() WHERE id=?'
    )->execute([$name, $sport, $status, $visibility, $description ?: null, $id]);

    if ($prev && $prev['status'] !== $status) {
        Notification::sendToTournamentTeam($id, Notification::TYPE_TOURNAMENT_STATUS_CHANGED, [
            'tournament_id' => $id,
            'old_status'    => $prev['status'],
            'new_status'    => $status,
        ]);
    }

    // PERF: invalidate tournament info and all discovery-list variants
    Cache::delete("tournament:{$id}:info");
    Cache::deletePattern("tournaments:list:");

    api_ok(null);
}

// ---------------------------------------------------------------------------
// Seeding
// ---------------------------------------------------------------------------

function api_tournament_seed(int $id): void
{
    api_require_auth();
    api_verify_csrf();

    $role = tournament_role($id);
    if (!in_array($role, ['organiser', 'admin'], true)) api_error('Forbidden.', 403);

    $stmt = db()->prepare('SELECT status FROM tournaments WHERE id = ?');
    $stmt->execute([$id]);
    $tournament = $stmt->fetch();
    if (!$tournament || $tournament['status'] !== 'draft') api_error('Tournament must be in draft status.');

    $body    = api_body();
    $mode    = ($body['mode'] ?? 'manual') === 'random' ? 'random' : 'manual';
    $teamIds = array_map('intval', $body['team_ids'] ?? []);
    $teamIds = array_values(array_filter($teamIds, fn($t) => $t > 0));

    if (count($teamIds) < 2) api_error('At least 2 teams required.');

    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $chk = db()->prepare(
        "SELECT team_id FROM tournament_teams
         WHERE tournament_id = ? AND team_id IN ($placeholders) AND status = 'approved'"
    );
    $chk->execute(array_merge([$id], $teamIds));
    $approved = array_column($chk->fetchAll(), 'team_id');

    if (!empty(array_diff($teamIds, $approved))) api_error('Some team IDs are not approved members.');

    $fStmt = db()->prepare('SELECT format, format_config FROM tournaments WHERE id = ?');
    $fStmt->execute([$id]);
    $fmt = $fStmt->fetch();
    if (!$fmt) api_error('Tournament not found.', 404);

    $formatConfig         = json_decode($fmt['format_config'] ?? '{}', true) ?? [];
    $formatConfig['mode'] = $mode;

    try {
        FormatFactory::make($fmt['format'])->generate($id, $teamIds, $formatConfig);
    } catch (\Exception $e) {
        api_error($e->getMessage());
    }

    // PERF: seeding rewrites bracket and resets standings
    Cache::delete("tournament:{$id}:bracket");
    Cache::delete("tournament:{$id}:standings");
    Cache::delete("tournament:{$id}:standings_groups");
    Cache::delete("tournament:{$id}:standings_knockout");

    api_ok(null);
}

// ---------------------------------------------------------------------------
// Team management
// ---------------------------------------------------------------------------

function api_tournament_teams_add(int $id): void
{
    api_require_auth();
    api_verify_csrf();

    $role = tournament_role($id);
    if (!in_array($role, ['organiser', 'admin'], true)) api_error('Forbidden.', 403);

    $body   = api_body();
    $teamId = (int)($body['team_id'] ?? 0);
    if ($teamId <= 0) api_error('Invalid team ID.');

    $stmt = db()->prepare('SELECT id FROM teams WHERE id = ?');
    $stmt->execute([$teamId]);
    if (!$stmt->fetch()) api_error('Team not found.', 404);

    db()->prepare(
        "INSERT INTO tournament_teams (tournament_id, team_id, status, registered_at)
         VALUES (?, ?, 'approved', NOW())
         ON CONFLICT (tournament_id, team_id)
         DO UPDATE SET status = 'approved', updated_at = NOW()"
    )->execute([$id, $teamId]);

    $ownerStmt = db()->prepare('SELECT owner_uid FROM teams WHERE id = ?');
    $ownerStmt->execute([$teamId]);
    $owner = $ownerStmt->fetch();
    if ($owner) {
        Notification::send((int)$owner['owner_uid'], Notification::TYPE_TEAM_REGISTRATION_APPROVED, [
            'tournament_id' => $id,
            'team_id'       => $teamId,
        ]);
    }

    api_ok(null);
}

function api_tournament_teams_request(int $id): void
{
    $user   = api_require_auth();
    api_verify_csrf();

    $body   = api_body();
    $teamId = (int)($body['team_id'] ?? 0);
    if ($teamId <= 0) api_error('Invalid team ID.');

    $stmt = db()->prepare('SELECT id, owner_uid, status FROM teams WHERE id = ?');
    $stmt->execute([$teamId]);
    $team = $stmt->fetch();
    if (!$team || (int)$team['owner_uid'] !== $user['uid']) api_error('Forbidden.', 403);
    if ($team['status'] === 'archived') api_error('Archived teams cannot register for tournaments.', 422);

    $tStmt = db()->prepare('SELECT visibility, status FROM tournaments WHERE id = ?');
    $tStmt->execute([$id]);
    $tournament = $tStmt->fetch();
    if (!$tournament) api_error('Tournament not found.', 404);

    if ($tournament['visibility'] !== 'open') {
        api_error('This tournament is not open for registration.');
    }
    if (in_array($tournament['status'], ['finalised', 'archived'], true)) {
        api_error('Cannot join a finalised or archived tournament.');
    }

    // Check for an existing pending or approved entry
    $existingStmt = db()->prepare(
        "SELECT status FROM tournament_teams WHERE tournament_id = ? AND team_id = ?"
    );
    $existingStmt->execute([$id, $teamId]);
    $existing = $existingStmt->fetch();
    if ($existing && in_array($existing['status'], ['pending', 'approved'], true)) {
        api_error('already_registered', 422);
    }

    db()->prepare(
        "INSERT INTO tournament_teams (tournament_id, team_id, status, registered_at)
         VALUES (?, ?, 'pending', NOW())"
    )->execute([$id, $teamId]);

    http_response_code(201);
    api_ok(null);
}

function api_tournament_teams_approve(int $id): void
{
    api_require_auth();
    api_verify_csrf();

    $role = tournament_role($id);
    if (!in_array($role, ['organiser', 'admin'], true)) api_error('Forbidden.', 403);

    $body   = api_body();
    $teamId = (int)($body['team_id'] ?? 0);
    $action = $body['action'] ?? 'approve';
    if ($teamId <= 0) api_error('Invalid team ID.');

    $newStatus = $action === 'reject' ? 'rejected' : 'approved';
    db()->prepare(
        'UPDATE tournament_teams SET status=?, updated_at=NOW() WHERE tournament_id=? AND team_id=?'
    )->execute([$newStatus, $id, $teamId]);

    if ($newStatus === 'approved') {
        $ownerStmt = db()->prepare('SELECT owner_uid FROM teams WHERE id = ?');
        $ownerStmt->execute([$teamId]);
        $owner = $ownerStmt->fetch();
        if ($owner) {
            Notification::send((int)$owner['owner_uid'], Notification::TYPE_TEAM_REGISTRATION_APPROVED, [
                'tournament_id' => $id,
                'team_id'       => $teamId,
            ]);
        }
    }

    api_ok(null);
}

function api_tournament_teams_approve_all(int $id): void
{
    api_require_auth();
    api_verify_csrf();

    $role = tournament_role($id);
    if (!in_array($role, ['organiser', 'admin'], true)) api_error('Forbidden.', 403);

    $stmt = db()->prepare('SELECT id FROM tournaments WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) api_error('Tournament not found.', 404);

    // Fetch pending teams before approving so we can send notifications
    $pendingStmt = db()->prepare(
        "SELECT tt.team_id, t.owner_uid
         FROM tournament_teams tt
         JOIN teams t ON t.id = tt.team_id
         WHERE tt.tournament_id = ? AND tt.status = 'pending'"
    );
    $pendingStmt->execute([$id]);
    $pending = $pendingStmt->fetchAll();

    if (empty($pending)) { api_ok(['approved' => 0]); return; }

    db()->prepare(
        "UPDATE tournament_teams SET status = 'approved', updated_at = NOW()
         WHERE tournament_id = ? AND status = 'pending'"
    )->execute([$id]);

    foreach ($pending as $row) {
        Notification::send((int)$row['owner_uid'], Notification::TYPE_TEAM_REGISTRATION_APPROVED, [
            'tournament_id' => $id,
            'team_id'       => (int)$row['team_id'],
        ]);
    }

    api_ok(['approved' => count($pending)]);
}

// ---------------------------------------------------------------------------
// Match result
// ---------------------------------------------------------------------------

function api_match_result(int $matchId): void
{
    $user = api_require_auth();
    api_verify_csrf();

    $stmt = db()->prepare('SELECT * FROM matches WHERE id = ?');
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();
    if (!$match) api_error('Match not found.', 404);

    $tournamentId = (int)$match['tournament_id'];
    $role         = tournament_role($tournamentId);
    if (!in_array($role, ['organiser', 'admin', 'staff'], true)) api_error('Forbidden.', 403);

    $body      = api_body();
    $homeScore = max(0, (int)($body['home_score'] ?? 0));
    $awayScore = max(0, (int)($body['away_score'] ?? 0));
    $reason    = trim($body['reason'] ?? '');

    if ($match['status'] === 'accepted' && $role === 'staff') {
        db()->prepare(
            "INSERT INTO reevaluation_requests
             (match_id, tournament_id, requested_by, requested_home_score, requested_away_score, reason, status)
             VALUES (?, ?, ?, ?, ?, ?, 'pending')"
        )->execute([$matchId, $tournamentId, $user['uid'], $homeScore, $awayScore, $reason ?: null]);

        $orgStmt = db()->prepare(
            "SELECT user_id FROM tournament_roles WHERE tournament_id=? AND role='organiser'"
        );
        $orgStmt->execute([$tournamentId]);
        foreach ($orgStmt->fetchAll() as $row) {
            Notification::send((int)$row['user_id'], Notification::TYPE_REEVALUATION_SUBMITTED, [
                'match_id'      => $matchId,
                'tournament_id' => $tournamentId,
                'requested_by'  => $user['uid'],
            ]);
        }

        api_ok(['reevaluation' => true]);
        return;
    }

    $winnerId = null;
    if ($homeScore > $awayScore) $winnerId = $match['home_team_id'];
    elseif ($awayScore > $homeScore) $winnerId = $match['away_team_id'];

    db()->prepare(
        "UPDATE matches SET home_score=?, away_score=?, status='accepted', winner_id=?,
         result_entered_by=?, result_entered_at=NOW(), updated_at=NOW() WHERE id=?"
    )->execute([$homeScore, $awayScore, $winnerId, $user['uid'], $matchId]);

    $fStmt2 = db()->prepare(
        'SELECT t.format FROM matches m JOIN tournaments t ON t.id = m.tournament_id WHERE m.id = ?'
    );
    $fStmt2->execute([$matchId]);
    $fRow = $fStmt2->fetch();
    if ($fRow) FormatFactory::make($fRow['format'])->advance($matchId);

    Notification::sendToTournamentStaff($tournamentId, Notification::TYPE_MATCH_RESULT_POSTED, [
        'match_id'      => $matchId,
        'tournament_id' => $tournamentId,
        'home_score'    => $homeScore,
        'away_score'    => $awayScore,
    ]);

    // PERF: result changes bracket layout, standings, and schedule display
    Cache::delete("tournament:{$tournamentId}:bracket");
    Cache::delete("tournament:{$tournamentId}:standings");
    Cache::delete("tournament:{$tournamentId}:standings_groups");
    Cache::delete("tournament:{$tournamentId}:standings_knockout");
    Cache::delete("tournament:{$tournamentId}:schedule");

    api_ok(null);
}

// ---------------------------------------------------------------------------
// Reevaluation
// ---------------------------------------------------------------------------

function api_reevaluation_resolve(int $rrId): void
{
    $user = api_require_auth();
    api_verify_csrf();

    $stmt = db()->prepare('SELECT * FROM reevaluation_requests WHERE id = ?');
    $stmt->execute([$rrId]);
    $rr = $stmt->fetch();
    if (!$rr || $rr['status'] !== 'pending') api_error('Reevaluation not found.', 404);

    $tournamentId = (int)$rr['tournament_id'];
    $role = tournament_role($tournamentId);
    if (!in_array($role, ['organiser', 'admin'], true)) api_error('Forbidden.', 403);

    $body       = api_body();
    $action     = $body['action'] ?? 'reject';
    $reviewNote = trim($body['review_note'] ?? '');
    $newStatus  = $action === 'approve' ? 'approved' : 'rejected';

    db()->prepare(
        'UPDATE reevaluation_requests SET status=?, resolved_by_uid=?, resolved_at=NOW(), review_note=?, updated_at=NOW() WHERE id=?'
    )->execute([$newStatus, $user['uid'], $reviewNote ?: null, $rrId]);

    if ($newStatus === 'approved') {
        $matchId = (int)$rr['match_id'];
        $hs = (int)$rr['requested_home_score'];
        $as = (int)$rr['requested_away_score'];

        $mStmt = db()->prepare('SELECT * FROM matches WHERE id = ?');
        $mStmt->execute([$matchId]);
        $match = $mStmt->fetch();

        $winnerId = null;
        if ($hs > $as) $winnerId = $match['home_team_id'];
        elseif ($as > $hs) $winnerId = $match['away_team_id'];

        db()->prepare(
            "UPDATE matches SET home_score=?, away_score=?, winner_id=?, status='accepted',
             result_entered_by=?, result_entered_at=NOW(), updated_at=NOW() WHERE id=?"
        )->execute([$hs, $as, $winnerId, $user['uid'], $matchId]);

        $fStmt3 = db()->prepare(
            'SELECT t.format FROM matches m JOIN tournaments t ON t.id = m.tournament_id WHERE m.id = ?'
        );
        $fStmt3->execute([$matchId]);
        $fRow3 = $fStmt3->fetch();
        if ($fRow3) FormatFactory::make($fRow3['format'])->advance($matchId);

        // PERF: approved reevaluation changes bracket + standings
        Cache::delete("tournament:{$tournamentId}:bracket");
        Cache::delete("tournament:{$tournamentId}:standings");
        Cache::delete("tournament:{$tournamentId}:standings_groups");
        Cache::delete("tournament:{$tournamentId}:standings_knockout");
        Cache::delete("tournament:{$tournamentId}:schedule");
    }

    Notification::send((int)$rr['requested_by'], Notification::TYPE_REEVALUATION_RESOLVED, [
        'reevaluation_id' => $rrId,
        'status'          => $newStatus,
        'tournament_id'   => $tournamentId,
    ]);

    api_ok(null);
}

function api_reevaluation_force_approve(int $rrId): void
{
    $user = api_require_admin();
    api_verify_csrf();

    $stmt = db()->prepare('SELECT * FROM reevaluation_requests WHERE id = ?');
    $stmt->execute([$rrId]);
    $rr = $stmt->fetch();
    if (!$rr || $rr['status'] !== 'pending') api_error('Reevaluation not found.', 404);

    db()->prepare(
        "UPDATE reevaluation_requests SET status='force_approved', resolved_by_uid=?, resolved_at=NOW(), updated_at=NOW() WHERE id=?"
    )->execute([$user['uid'], $rrId]);

    $matchId = (int)$rr['match_id'];
    $mStmt = db()->prepare('SELECT * FROM matches WHERE id = ?');
    $mStmt->execute([$matchId]);
    $match = $mStmt->fetch();

    $hs = (int)$rr['requested_home_score'];
    $as = (int)$rr['requested_away_score'];
    $winnerId = null;
    if ($hs > $as) $winnerId = $match['home_team_id'];
    elseif ($as > $hs) $winnerId = $match['away_team_id'];

    db()->prepare(
        "UPDATE matches SET home_score=?, away_score=?, winner_id=?, status='accepted',
         result_entered_by=?, result_entered_at=NOW(), updated_at=NOW() WHERE id=?"
    )->execute([$hs, $as, $winnerId, $user['uid'], $matchId]);

    $fStmt4 = db()->prepare(
        'SELECT t.format FROM matches m JOIN tournaments t ON t.id = m.tournament_id WHERE m.id = ?'
    );
    $fStmt4->execute([$matchId]);
    $fRow4 = $fStmt4->fetch();
    if ($fRow4) FormatFactory::make($fRow4['format'])->advance($matchId);

    Notification::send((int)$rr['requested_by'], Notification::TYPE_REEVALUATION_RESOLVED, [
        'reevaluation_id' => $rrId,
        'status'          => 'force_approved',
        'tournament_id'   => (int)$rr['tournament_id'],
    ]);

    // PERF: force-approved result changes bracket + standings
    $forceTid = (int)$rr['tournament_id'];
    Cache::delete("tournament:{$forceTid}:bracket");
    Cache::delete("tournament:{$forceTid}:standings");
    Cache::delete("tournament:{$forceTid}:standings_groups");
    Cache::delete("tournament:{$forceTid}:standings_knockout");
    Cache::delete("tournament:{$forceTid}:schedule");

    api_ok(null);
}

// ---------------------------------------------------------------------------
// Notifications
// ---------------------------------------------------------------------------

function api_notifications(): void
{
    $user = api_require_auth();

    $stmt = db()->prepare(
        'SELECT id, type, payload, read_at, created_at
         FROM notifications WHERE user_uid=? AND read_at IS NULL
         ORDER BY created_at DESC LIMIT 100'
    );
    $stmt->execute([$user['uid']]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['payload'] = json_decode($row['payload'], true);
    }
    unset($row);

    api_ok(['notifications' => $rows]);
}

function api_notifications_read(): void
{
    $user = api_require_auth();
    api_verify_csrf();

    $body = api_body();
    $ids  = array_map('intval', (array)($body['ids'] ?? []));
    $ids  = array_values(array_filter($ids, fn($i) => $i > 0));

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare(
            "UPDATE notifications SET read_at=NOW()
             WHERE user_uid=? AND id IN ($placeholders) AND read_at IS NULL"
        )->execute(array_merge([$user['uid']], $ids));
        // PERF: unread count changed
        Cache::delete("user:{$user['uid']}:notifications:count");
    }

    api_ok(null);
}

// ---------------------------------------------------------------------------
// Team page
// ---------------------------------------------------------------------------

function api_team_view(int $id): void
{
    // PERF: cache non-user-specific profile data for 60s.
    // is_owner depends on the current session and is applied after cache retrieval.
    $cached = Cache::remember("team:{$id}:profile", 60, function() use ($id) {
        $stmt = db()->prepare(
            'SELECT t.id, t.name, t.short_name, t.sport, t.description, t.status,
                    u.id AS owner_uid, u.display_name AS owner_name
             FROM teams t JOIN users u ON u.id = t.owner_uid WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return false; // sentinel: team not found

        $mStmt = db()->prepare(
            'SELECT id, name FROM team_members WHERE team_id = ? ORDER BY created_at ASC, id ASC'
        );
        $mStmt->execute([$id]);
        $members = array_map(function ($m) { $m['id'] = (int)$m['id']; return $m; }, $mStmt->fetchAll());

        $hStmt = db()->prepare(
            "SELECT t.id AS tournament_id, t.name AS tournament_name, t.sport, t.status,
                    t.format, t.is_featured
             FROM tournament_teams tt
             JOIN tournaments t ON t.id = tt.tournament_id
             WHERE tt.team_id = ? AND tt.status = 'approved'
               AND t.status NOT IN ('draft')
             ORDER BY t.created_at DESC"
        );
        $hStmt->execute([$id]);
        $historyRows = $hStmt->fetchAll();

        // PERF: finalised standings are immutable — cache 1 hour under the shared
        // tournament standings key so api_tournament_standings() also benefits.
        $history = [];
        foreach ($historyRows as $hr) {
            $tid      = (int)$hr['tournament_id'];
            $standing = null;

            if ($hr['status'] === 'finalised') {
                $standings = Cache::remember("tournament:{$tid}:standings", 3600, function() use ($hr, $tid) {
                    try {
                        return FormatFactory::make($hr['format'])->standings($tid);
                    } catch (\Exception $e) {
                        return [];
                    }
                }) ?? [];
                foreach ($standings as $pos => $s) {
                    if ((int)($s['team_id'] ?? 0) === $id) {
                        $standing = $pos + 1;
                        break;
                    }
                }
            }

            $history[] = [
                'tournament_id'   => $tid,
                'tournament_name' => $hr['tournament_name'],
                'sport'           => $hr['sport'],
                'status'          => $hr['status'],
                'is_featured'     => (bool)$hr['is_featured'],
                'standing'        => $standing,
            ];
        }

        return [
            '_owner_uid' => (int)$row['owner_uid'], // internal; stripped before response
            'base' => [
                'id'          => (int)$row['id'],
                'name'        => $row['name'],
                'short_name'  => $row['short_name'],
                'sport'       => $row['sport'],
                'description' => $row['description'],
                'status'      => $row['status'],
                'owner'       => ['display_name' => $row['owner_name']],
            ],
            'members' => $members,
            'history' => $history,
        ];
    });

    if ($cached === false || $cached === null) api_error('Team not found.', 404);

    $currentUser = current_user();
    $isOwner     = $currentUser &&
                   ((int)$currentUser['uid'] === $cached['_owner_uid'] || $currentUser['is_admin']);

    $team              = $cached['base'];
    $team['is_owner']  = $isOwner;

    api_ok(['team' => $team, 'members' => $cached['members'], 'history' => $cached['history']]);
}

// ---------------------------------------------------------------------------
// Team update / archive / members
// ---------------------------------------------------------------------------

function api_team_update(int $id): void
{
    $user = api_require_auth();
    api_verify_csrf();

    $stmt = db()->prepare('SELECT owner_uid FROM teams WHERE id = ?');
    $stmt->execute([$id]);
    $team = $stmt->fetch();
    if (!$team) api_error('Team not found.', 404);
    if ((int)$team['owner_uid'] !== $user['uid'] && !$user['is_admin']) api_error('Forbidden.', 403);

    $body        = api_body();
    $name        = trim($body['name']        ?? '');
    $sport       = trim($body['sport']       ?? '');
    $description = trim($body['description'] ?? '');

    $fields = [];
    if ($name !== '' && (mb_strlen($name) < 2 || mb_strlen($name) > 48)) {
        $fields['name'] = 'Team name must be 2–48 characters.';
    }
    if ($sport !== '' && (mb_strlen($sport) < 2 || mb_strlen($sport) > 32)) {
        $fields['sport'] = 'Sport must be 2–32 characters.';
    }
    if (mb_strlen($description) > 280) {
        $fields['description'] = 'Description must be 280 characters or fewer.';
    }
    if (!empty($fields)) api_validation_error($fields);

    $db = db();
    $db->beginTransaction();
    try {
        // SCHOOL: if the name is changing, enforce the global name-claim system.
        if ($name !== '') {
            $curStmt = $db->prepare('SELECT name FROM teams WHERE id = ?');
            $curStmt->execute([$id]);
            $currentName = $curStmt->fetchColumn();

            if (mb_strtolower($name) !== mb_strtolower((string)$currentName)) {
                $claimStmt = $db->prepare(
                    'SELECT owner_uid FROM team_name_claims WHERE name_lower = lower(?)'
                );
                $claimStmt->execute([$name]);
                $claim = $claimStmt->fetch();

                if ($claim && (int)$claim['owner_uid'] !== $user['uid']) {
                    $db->rollBack();
                    api_error('team_name_claimed', 422);
                }

                if (!$claim) {
                    $db->prepare(
                        'INSERT INTO team_name_claims (name_lower, owner_uid) VALUES (lower(?), ?)'
                    )->execute([$name, $user['uid']]);
                }
            }
        }

        $setClauses = ['updated_at = NOW()'];
        $params     = [];
        if ($name !== '')  { $setClauses[] = 'name = ?';  $params[] = $name;  }
        if ($sport !== '') { $setClauses[] = 'sport = ?'; $params[] = $sport; }
        $setClauses[] = 'description = ?';
        $params[]     = $description ?: null;
        $params[]     = $id;

        $db->prepare('UPDATE teams SET ' . implode(', ', $setClauses) . ' WHERE id = ?')
             ->execute($params);

        $db->commit();
    } catch (\PDOException $e) {
        $db->rollBack();
        throw $e;
    }

    // PERF: profile data changed
    Cache::delete("team:{$id}:profile");

    api_ok(null);
}

function api_team_archive(int $id): void
{
    $user = api_require_auth();
    api_verify_csrf();

    $stmt = db()->prepare('SELECT owner_uid, status FROM teams WHERE id = ?');
    $stmt->execute([$id]);
    $team = $stmt->fetch();
    if (!$team) api_error('Team not found.', 404);
    if ((int)$team['owner_uid'] !== $user['uid'] && !$user['is_admin']) api_error('Forbidden.', 403);
    if ($team['status'] === 'archived') api_error('Team is already archived.');

    // Decision: 409 if pending registration OR approved in an in_progress tournament.
    // Both represent active engagement that archiving would silently break.
    // Admin bypass skips this check entirely.
    if (!$user['is_admin']) {
        $blockStmt = db()->prepare(
            "SELECT 1 FROM tournament_teams tt
             JOIN tournaments t ON t.id = tt.tournament_id
             WHERE tt.team_id = ?
               AND (tt.status = 'pending'
                    OR (tt.status = 'approved' AND t.status = 'in_progress'))
             LIMIT 1"
        );
        $blockStmt->execute([$id]);
        if ($blockStmt->fetch()) {
            api_error('Team has active tournament registrations.', 409);
        }
    }

    db()->prepare("UPDATE teams SET status = 'archived', updated_at = NOW() WHERE id = ?")
         ->execute([$id]);

    // PERF: status change affects team profile and any tournament discovery listing the team
    Cache::delete("team:{$id}:profile");

    api_ok(null);
}

function api_team_members_add(int $id): void
{
    $user = api_require_auth();
    api_verify_csrf();

    $stmt = db()->prepare('SELECT owner_uid FROM teams WHERE id = ?');
    $stmt->execute([$id]);
    $team = $stmt->fetch();
    if (!$team) api_error('Team not found.', 404);
    if ((int)$team['owner_uid'] !== $user['uid']) api_error('Forbidden.', 403);

    $body = api_body();
    $name = trim($body['name'] ?? '');
    if ($name === '') api_error('Name is required.');
    if (mb_strlen($name) > 80) api_error('Name must be 80 characters or fewer.');

    $countStmt = db()->prepare('SELECT COUNT(*) FROM team_members WHERE team_id = ?');
    $countStmt->execute([$id]);
    if ((int)$countStmt->fetchColumn() >= 50) {
        api_error('Team roster is full (max 50 members).');
    }

    $ins = db()->prepare(
        'INSERT INTO team_members (team_id, name) VALUES (?, ?) RETURNING id, name, created_at'
    );
    $ins->execute([$id, $name]);
    $member       = $ins->fetch();
    $member['id'] = (int)$member['id'];

    // PERF: roster change invalidates team profile
    Cache::delete("team:{$id}:profile");

    http_response_code(201);
    api_ok(['member' => $member]);
}

function api_team_members_delete(int $teamId, int $memberId): void
{
    $user = api_require_auth();
    api_verify_csrf();

    $stmt = db()->prepare('SELECT owner_uid FROM teams WHERE id = ?');
    $stmt->execute([$teamId]);
    $team = $stmt->fetch();
    if (!$team) api_error('Team not found.', 404);
    if ((int)$team['owner_uid'] !== $user['uid']) api_error('Forbidden.', 403);

    db()->prepare('DELETE FROM team_members WHERE id = ? AND team_id = ?')
         ->execute([$memberId, $teamId]);

    // PERF: roster change invalidates team profile
    Cache::delete("team:{$teamId}:profile");

    api_ok(null);
}

// ---------------------------------------------------------------------------
// Admin
// ---------------------------------------------------------------------------

function api_admin_users(): void
{
    api_require_admin();
    // PERF: paginate — unbounded user sets are a DoS risk on large installs
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $stmt   = db()->prepare(
        'SELECT id, username, display_name, is_admin, created_at FROM users ORDER BY id LIMIT 100 OFFSET ?'
    );
    $stmt->execute([$offset]);
    api_ok(['users' => $stmt->fetchAll()]);
}

function api_admin_tournaments(): void
{
    api_require_admin();
    $rows = db()->query(
        'SELECT t.id, t.name, t.sport, t.status, t.visibility, t.is_featured,
                u.display_name AS organiser_name, t.created_at
         FROM tournaments t JOIN users u ON u.id = t.created_by ORDER BY t.id DESC'
    )->fetchAll();
    api_ok(['tournaments' => $rows]);
}

function api_admin_reevaluations(): void
{
    api_require_admin();
    $rows = db()->query(
        "SELECT rr.id, rr.match_id, rr.tournament_id, rr.status,
                rr.requested_home_score, rr.requested_away_score, rr.reason,
                m.round, m.match_number,
                ht.name  AS home_team_name,
                at2.name AS away_team_name,
                u.display_name AS requester_name,
                t.name AS tournament_name
         FROM reevaluation_requests rr
         JOIN matches m     ON m.id   = rr.match_id
         LEFT JOIN teams ht  ON ht.id  = m.home_team_id
         LEFT JOIN teams at2 ON at2.id = m.away_team_id
         JOIN users u       ON u.id   = rr.requested_by
         JOIN tournaments t ON t.id   = rr.tournament_id
         WHERE rr.status = 'pending'
         ORDER BY rr.created_at ASC"
    )->fetchAll();
    api_ok(['reevaluations' => $rows]);
}

function api_admin_user_edit(int $userId): void
{
    api_require_admin();
    api_verify_csrf();

    $body        = api_body();
    $displayName = trim($body['display_name'] ?? '');
    $username    = trim($body['username'] ?? '');
    if ($displayName === '' || $username === '') api_error('Missing fields.');

    $stmt = db()->prepare('SELECT id FROM users WHERE lower(username)=lower(?) AND id != ?');
    $stmt->execute([$username, $userId]);
    if ($stmt->fetch()) api_error('Username already taken.');

    db()->prepare('UPDATE users SET display_name=?, username=?, updated_at=NOW() WHERE id=?')
         ->execute([$displayName, $username, $userId]);

    api_ok(null);
}

function api_admin_user_delete(int $userId): void
{
    $admin = api_require_admin();
    api_verify_csrf();

    if ($userId === $admin['uid']) api_error('Cannot delete yourself.');

    // Block if the user owns teams or has created tournaments — those records
    // cannot be safely auto-deleted and must be reassigned first.
    // All other FK references (notifications, tournament_roles, reeval rows,
    // match result_entered_by, audit_log) are handled via CASCADE / SET NULL
    // in migration 009_user_delete_fks.sql.
    $depStmt = db()->prepare(
        'SELECT
           (SELECT COUNT(*) FROM teams       WHERE owner_uid  = ?) AS team_count,
           (SELECT COUNT(*) FROM tournaments WHERE created_by = ?) AS tournament_count'
    );
    $depStmt->execute([$userId, $userId]);
    $dep = $depStmt->fetch();

    if ((int)$dep['team_count'] > 0 || (int)$dep['tournament_count'] > 0) {
        api_error('user_has_dependent_records', 422, [
            'detail' => 'This user owns teams or tournaments and cannot be deleted. Reassign or remove those first.',
        ]);
    }

    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    api_ok(null);
}

function api_admin_tournament_create(): void
{
    api_require_admin();
    api_verify_csrf();

    $body        = api_body();
    $name        = trim($body['name']       ?? '');
    $sport       = trim($body['sport']      ?? '');
    $format      = trim($body['format']     ?? '');
    $visibility  = trim($body['visibility'] ?? 'open');
    $organiserId = (int)($body['organiser_id'] ?? 0);

    if ($name === '' || $sport === '' || $format === '' || $organiserId <= 0) {
        api_error('All fields are required.');
    }

    $validFormats = FormatFactory::validFormats();
    if (!in_array($format, $validFormats, true)) api_error('Invalid format.');
    if (!in_array($visibility, ['open', 'invite_only'], true)) $visibility = 'open';

    $uStmt = db()->prepare('SELECT id FROM users WHERE id = ?');
    $uStmt->execute([$organiserId]);
    if (!$uStmt->fetch()) api_error('Organiser not found.', 404);

    $db = db();
    $db->beginTransaction();

    $stmt = $db->prepare(
        "INSERT INTO tournaments (name, sport, format, status, visibility, created_by)
         VALUES (?, ?, ?, 'draft', ?, ?) RETURNING id"
    );
    $stmt->execute([$name, $sport, $format, $visibility, $organiserId]);
    $tournamentId = (int)$stmt->fetchColumn();

    $db->prepare(
        "INSERT INTO tournament_roles (tournament_id, user_id, role, assigned_by)
         VALUES (?, ?, 'organiser', ?)"
    )->execute([$tournamentId, $organiserId, current_user()['uid']]);

    $db->commit();

    // PERF: new tournament appears in discovery listing
    Cache::deletePattern("tournaments:list:");

    api_ok(['tournament_id' => $tournamentId]);
}

function api_admin_tournament_delete(int $id): void
{
    api_require_admin();
    api_verify_csrf();
    db()->prepare('DELETE FROM tournaments WHERE id = ?')->execute([$id]);
    // PERF: tournament removed from all discovery lists
    Cache::deletePattern("tournaments:list:");
    api_ok(null);
}

function api_admin_tournament_feature(int $id): void
{
    api_require_admin();
    api_verify_csrf();
    $body = api_body();
    $featured = (bool)($body['is_featured'] ?? false);
    db()->prepare('UPDATE tournaments SET is_featured=?, updated_at=NOW() WHERE id=?')
         ->execute([$featured, $id]);
    // PERF: featured flag changes sort order on home page
    Cache::deletePattern("tournaments:list:");
    api_ok(null);
}

function api_admin_role_assign(): void
{
    $admin = api_require_admin();
    api_verify_csrf();

    $body         = api_body();
    $tournamentId = (int)($body['tournament_id'] ?? 0);
    $userId       = (int)($body['user_id']       ?? 0);
    $role         = trim($body['role'] ?? '');

    if ($tournamentId <= 0 || $userId <= 0 || !in_array($role, ['organiser', 'staff'], true)) {
        api_error('Invalid role assignment.');
    }

    db()->prepare(
        'INSERT INTO tournament_roles (tournament_id, user_id, role, assigned_by)
         VALUES (?, ?, ?, ?)
         ON CONFLICT (tournament_id, user_id)
         DO UPDATE SET role=EXCLUDED.role, assigned_by=EXCLUDED.assigned_by, updated_at=NOW()'
    )->execute([$tournamentId, $userId, $role, $admin['uid']]);

    if ($role === 'staff') {
        Notification::send($userId, Notification::TYPE_STAFF_ASSIGNED, [
            'tournament_id' => $tournamentId,
        ]);
    }

    api_ok(null);
}

function api_admin_role_revoke(): void
{
    api_require_admin();
    api_verify_csrf();

    $body         = api_body();
    $tournamentId = (int)($body['tournament_id'] ?? 0);
    $userId       = (int)($body['user_id']       ?? 0);

    if ($tournamentId <= 0 || $userId <= 0) api_error('Invalid revoke request.');

    db()->prepare('DELETE FROM tournament_roles WHERE tournament_id=? AND user_id=?')
         ->execute([$tournamentId, $userId]);

    api_ok(null);
}

// ---------------------------------------------------------------------------
// Admin — teams
// ---------------------------------------------------------------------------

function api_admin_teams(): void
{
    api_require_admin();
    $rows = db()->query(
        "SELECT t.id, t.name, t.sport, t.status,
                u.display_name AS owner_name,
                COUNT(DISTINCT tm.id) AS member_count,
                COUNT(DISTINCT tt.id) FILTER (WHERE tt.status = 'approved') AS tournament_count
         FROM teams t
         JOIN users u ON u.id = t.owner_uid
         LEFT JOIN team_members tm ON tm.team_id = t.id
         LEFT JOIN tournament_teams tt ON tt.team_id = t.id
         GROUP BY t.id, u.display_name
         ORDER BY t.id DESC"
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['member_count']     = (int)$r['member_count'];
        $r['tournament_count'] = (int)$r['tournament_count'];
    }
    unset($r);
    api_ok(['teams' => $rows]);
}

function api_admin_team_status(int $id): void
{
    api_require_admin();
    api_verify_csrf();

    $body   = api_body();
    $status = trim($body['status'] ?? '');
    if (!in_array($status, ['active', 'archived'], true)) api_error('Invalid status.');

    $stmt = db()->prepare('SELECT id FROM teams WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) api_error('Team not found.', 404);

    db()->prepare('UPDATE teams SET status = ?, updated_at = NOW() WHERE id = ?')
         ->execute([$status, $id]);

    // PERF: status change affects team profile page
    Cache::delete("team:{$id}:profile");

    api_ok(null);
}

// SCHOOL: hard-delete a team — admin only
// Cascade order: members → tournament entries → match references → name claim → team row
function api_admin_team_delete(int $id): void
{
    api_require_admin();
    api_verify_csrf();

    $stmt = db()->prepare('SELECT id, name, owner_uid FROM teams WHERE id = ?');
    $stmt->execute([$id]);
    $team = $stmt->fetch();
    if (!$team) api_error('Team not found.', 404);

    $db = db();
    try {
        $db->beginTransaction();

        $db->prepare('DELETE FROM team_members       WHERE team_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM tournament_teams   WHERE team_id = ?')->execute([$id]);

        // Nullify match references — matches stay intact; frontend renders null team as TBD/—
        $db->prepare('UPDATE matches SET home_team_id = NULL WHERE home_team_id = ?')->execute([$id]);
        $db->prepare('UPDATE matches SET away_team_id = NULL WHERE away_team_id = ?')->execute([$id]);
        $db->prepare('UPDATE matches SET winner_id    = NULL WHERE winner_id    = ?')->execute([$id]);

        // Only remove the name claim if this owner has no other teams with the same name
        $dupCount = $db->prepare(
            'SELECT COUNT(*) FROM teams WHERE owner_uid = ? AND lower(name) = lower(?) AND id != ?'
        );
        $dupCount->execute([$team['owner_uid'], $team['name'], $id]);
        if ((int)$dupCount->fetchColumn() === 0) {
            $db->prepare(
                'DELETE FROM team_name_claims WHERE name_lower = lower(?) AND owner_uid = ?'
            )->execute([$team['name'], $team['owner_uid']]);
        }

        $db->prepare('DELETE FROM teams WHERE id = ?')->execute([$id]);

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        api_error('Delete failed: ' . $e->getMessage(), 500);
    }

    Cache::delete("team:{$id}:profile");
    api_ok(['ok' => true]);
}

// ---------------------------------------------------------------------------
// Tournament roles — list, assign staff, revoke staff
// (Admin-only organiser assignment is handled by api_admin_role_assign.)
// ---------------------------------------------------------------------------

// SCHOOL: returns all roles for this tournament — organiser/admin of that tournament only
function api_tournament_roles(int $id): void
{
    $role = tournament_role($id);
    if (!in_array($role, ['organiser', 'admin'], true)) api_error('Forbidden.', 403);

    $stmt = db()->prepare(
        "SELECT tr.user_id AS user_uid, u.display_name, u.username, tr.role
         FROM tournament_roles tr
         JOIN users u ON u.id = tr.user_id
         WHERE tr.tournament_id = ?
         ORDER BY tr.role ASC, u.display_name ASC"
    );
    $stmt->execute([$id]);
    api_ok(['roles' => $stmt->fetchAll()]);
}

// SCHOOL: privileged user search — requires organiser/admin of the given tournament.
// Excludes users who already have any role on exclude_tournament.
function api_users_search(): void
{
    api_require_auth();

    $q          = trim($_GET['q'] ?? '');
    $excludeTid = (int)($_GET['exclude_tournament'] ?? 0);

    if (mb_strlen($q) < 2) api_error('Query must be at least 2 characters.', 400);
    if ($excludeTid <= 0)  api_error('exclude_tournament is required.', 400);

    $callerRole = tournament_role($excludeTid);
    if (!in_array($callerRole, ['organiser', 'admin'], true)) api_error('Forbidden.', 403);

    $like = '%' . $q . '%';
    $stmt = db()->prepare(
        "SELECT id AS uid, display_name, username
         FROM users
         WHERE (lower(display_name) LIKE lower(?) OR lower(username) LIKE lower(?))
           AND id NOT IN (SELECT user_id FROM tournament_roles WHERE tournament_id = ?)
         ORDER BY display_name
         LIMIT 20"
    );
    $stmt->execute([$like, $like, $excludeTid]);
    api_ok($stmt->fetchAll());
}

function api_tournament_roles_assign(int $id): void
{
    $caller = api_require_auth();
    api_verify_csrf();

    $callerRole = tournament_role($id);
    if (!in_array($callerRole, ['organiser', 'admin'], true)) api_error('Forbidden.', 403);

    $body    = api_body();
    $userUid = (int)($body['user_uid'] ?? 0);
    $role    = trim($body['role'] ?? '');

    if ($userUid <= 0 || $role !== 'staff') {
        api_error('Only the staff role can be assigned via this endpoint.', 400);
    }

    db()->prepare(
        'INSERT INTO tournament_roles (tournament_id, user_id, role, assigned_by)
         VALUES (?, ?, ?, ?)
         ON CONFLICT (tournament_id, user_id)
         DO UPDATE SET role=EXCLUDED.role, assigned_by=EXCLUDED.assigned_by, updated_at=NOW()'
    )->execute([$id, $userUid, 'staff', $caller['uid']]);

    Notification::send($userUid, Notification::TYPE_STAFF_ASSIGNED, [
        'tournament_id' => $id,
    ]);

    $stmt = db()->prepare(
        "SELECT tr.user_id AS user_uid, u.display_name, u.username, tr.role
         FROM tournament_roles tr
         JOIN users u ON u.id = tr.user_id
         WHERE tr.tournament_id = ?
         ORDER BY tr.role ASC, u.display_name ASC"
    );
    $stmt->execute([$id]);
    api_ok(['roles' => $stmt->fetchAll()]);
}

function api_tournament_roles_revoke(int $id): void
{
    api_require_auth();
    api_verify_csrf();

    $callerRole = tournament_role($id);
    if (!in_array($callerRole, ['organiser', 'admin'], true)) api_error('Forbidden.', 403);

    $body    = api_body();
    $userUid = (int)($body['user_uid'] ?? 0);
    if ($userUid <= 0) api_error('Invalid user.', 400);

    $roleStmt = db()->prepare(
        'SELECT role FROM tournament_roles WHERE tournament_id = ? AND user_id = ?'
    );
    $roleStmt->execute([$id, $userUid]);
    $targetRow = $roleStmt->fetch();
    if (!$targetRow || $targetRow['role'] !== 'staff') {
        api_error('Only the staff role can be revoked via this endpoint.', 403);
    }

    db()->prepare(
        'DELETE FROM tournament_roles WHERE tournament_id = ? AND user_id = ? AND role = ?'
    )->execute([$id, $userUid, 'staff']);

    $stmt = db()->prepare(
        "SELECT tr.user_id AS user_uid, u.display_name, u.username, tr.role
         FROM tournament_roles tr
         JOIN users u ON u.id = tr.user_id
         WHERE tr.tournament_id = ?
         ORDER BY tr.role ASC, u.display_name ASC"
    );
    $stmt->execute([$id]);
    api_ok(['roles' => $stmt->fetchAll()]);
}

// ---------------------------------------------------------------------------
// Non-admin tournament creation (any authenticated user becomes organiser)
// ---------------------------------------------------------------------------

function api_tournament_create(): void
{
    $user = api_require_auth();
    api_verify_csrf();

    $body        = api_body();
    $name        = trim($body['name']        ?? '');
    $sport       = trim($body['sport']       ?? '');
    $format      = trim($body['format']      ?? 'single_elim');
    $visibility  = trim($body['visibility']  ?? 'open');
    $description = trim($body['description'] ?? '');

    if ($name === '')  api_error('Name is required.');
    if ($sport === '') api_error('Sport is required.');
    if (!in_array($format, FormatFactory::validFormats(), true)) api_error('Invalid format.');
    if (!in_array($visibility, ['open', 'invite_only'], true)) $visibility = 'open';
    if (mb_strlen($description) > 280) api_error('Description must be 280 characters or fewer.');

    $db = db();
    $db->beginTransaction();

    $stmt = $db->prepare(
        "INSERT INTO tournaments (name, sport, format, status, visibility, description, created_by)
         VALUES (?, ?, ?, 'draft', ?, ?, ?) RETURNING id"
    );
    $stmt->execute([$name, $sport, $format, $visibility, $description ?: null, $user['uid']]);
    $tournamentId = (int)$stmt->fetchColumn();

    $db->prepare(
        "INSERT INTO tournament_roles (tournament_id, user_id, role, assigned_by)
         VALUES (?, ?, 'organiser', ?)"
    )->execute([$tournamentId, $user['uid'], $user['uid']]);

    $db->commit();

    // PERF: new tournament appears in discovery listing
    Cache::deletePattern("tournaments:list:");

    http_response_code(201);
    api_ok(['tournament_id' => $tournamentId]);
}

// ---------------------------------------------------------------------------
// Format config — set format and format_config (draft status only)
// ---------------------------------------------------------------------------

function api_tournament_config(int $id): void
{
    api_require_auth();
    api_verify_csrf();

    $role = tournament_role($id);
    if (!in_array($role, ['organiser', 'admin'], true)) api_error('Forbidden.', 403);

    $stmt = db()->prepare('SELECT status FROM tournaments WHERE id = ?');
    $stmt->execute([$id]);
    $t = $stmt->fetch();
    if (!$t || $t['status'] !== 'draft') api_error('Tournament must be in draft status.');

    $body   = api_body();
    $format = trim($body['format'] ?? '');
    if (!in_array($format, FormatFactory::validFormats(), true)) api_error('Invalid format.');

    $configInput = $body['format_config'] ?? [];
    if (!is_array($configInput)) $configInput = [];

    db()->prepare(
        'UPDATE tournaments SET format = ?, format_config = ?, updated_at = NOW() WHERE id = ?'
    )->execute([$format, json_encode($configInput), $id]);

    api_ok(null);
}

// ---------------------------------------------------------------------------
// Standings — delegates to format engine; 10s cache (3600s for finalised)
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Match live toggle — organiser / staff / admin
// SCHOOL: is_live marks a match as currently being played.
// The DB trigger (010_live_matches.sql) fires the match_update SSE event
// on UPDATE OF is_live, so all connected clients update instantly.
// ---------------------------------------------------------------------------

function api_match_toggle_live(int $matchId): void
{
    api_require_auth();
    api_verify_csrf();

    $stmt = db()->prepare(
        'SELECT m.*, t.status AS tournament_status
         FROM matches m
         JOIN tournaments t ON t.id = m.tournament_id
         WHERE m.id = ?'
    );
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();
    if (!$match) api_error('Match not found.', 404);

    $tournamentId = (int)$match['tournament_id'];
    $role         = tournament_role($tournamentId);
    if (!in_array($role, ['organiser', 'admin', 'staff'], true)) api_error('Forbidden.', 403);

    // Only meaningful while the tournament is running
    if ($match['tournament_status'] !== 'in_progress') {
        api_error('Tournament must be in_progress to mark matches as live.', 422);
    }

    // Only pending or accepted matches make sense as live
    if (!in_array($match['status'], ['pending', 'accepted'], true)) {
        api_error('Only pending or accepted matches can be marked as live.', 422);
    }

    $body   = api_body();
    $isLive = isset($body['is_live']) ? (bool)$body['is_live'] : false;
    db()->prepare(
        'UPDATE matches SET is_live = ?, updated_at = NOW() WHERE id = ?'
    )->execute([$isLive ? 't' : 'f', $matchId]);
    // SCHOOL: cache invalidation — is_live change affects bracket and schedule views
    Cache::delete("tournament:{$tournamentId}:bracket");
    Cache::delete("tournament:{$tournamentId}:schedule");

    api_ok(['is_live' => $isLive, 'match_id' => $matchId]);
}

// ---------------------------------------------------------------------------
// Standings — delegates to format engine; 10s cache (3600s for finalised)
// ---------------------------------------------------------------------------

function api_tournament_standings(int $id): void
{
    $stmt = db()->prepare('SELECT format, status FROM tournaments WHERE id = ?');
    $stmt->execute([$id]);
    $t = $stmt->fetch();
    if (!$t) api_error('Tournament not found.', 404);

    // PERF: finalised standings never change → 1-hour TTL; live tournaments → 10s
    $ttl = $t['status'] === 'finalised' ? 3600 : 10;

    // multi_stage: return per-group RR standings + knockout placement
    if ($t['format'] === 'multi_stage') {
        $groupsData = Cache::remember("tournament:{$id}:standings_groups", $ttl, function() use ($id) {
            $s1 = db()->prepare(
                'SELECT id FROM tournament_stages WHERE tournament_id = ? AND stage_order = 1'
            );
            $s1->execute([$id]);
            $stage1 = $s1->fetch();
            if (!$stage1) return null;
            $byGroup = (new RoundRobin())->standingsByGroup($id, (int)$stage1['id']);
            return count($byGroup) > 1 ? $byGroup : null;
        });

        $koData = Cache::remember("tournament:{$id}:standings_knockout", $ttl, function() use ($id) {
            $stmt = db()->prepare(
                'SELECT m.round, m.home_team_id, m.away_team_id, m.winner_id,
                        ht.name  AS home_name,
                        at2.name AS away_name
                 FROM matches m
                 JOIN tournament_stages ts ON ts.id = m.stage_id
                 LEFT JOIN teams ht  ON ht.id  = m.home_team_id
                 LEFT JOIN teams at2 ON at2.id = m.away_team_id
                 WHERE m.tournament_id = ?
                   AND ts.stage_order  = 2
                   AND m.status       != \'bye\'
                 ORDER BY m.round ASC'
            );
            $stmt->execute([$id]);
            $kMatches = $stmt->fetchAll();
            if (empty($kMatches)) return null;

            $byRound = [];
            foreach ($kMatches as $m) {
                $byRound[(int)$m['round']][] = $m;
            }
            ksort($byRound);
            $rounds = array_values($byRound);
            $total  = count($rounds);

            $label = function(int $ri) use ($total): string {
                $d = $total - 1 - $ri;
                if ($d === 0) return 'Finale';
                if ($d === 1) return 'Halbfinale';
                if ($d === 2) return 'Viertelfinale';
                if ($d === 3) return 'Achtelfinale';
                return 'Runde ' . ($ri + 1);
            };

            // extract winner or loser from a completed match
            $party = function(array $m, bool $isWinner): array {
                if (!$m['winner_id']) return ['id' => null, 'name' => null];
                $winIsHome = (int)$m['winner_id'] === (int)$m['home_team_id'];
                $useHome   = $isWinner ? $winIsHome : !$winIsHome;
                return [
                    'id'   => $useHome
                        ? ($m['home_team_id'] ? (int)$m['home_team_id'] : null)
                        : ($m['away_team_id'] ? (int)$m['away_team_id'] : null),
                    'name' => $useHome ? $m['home_name'] : $m['away_name'],
                ];
            };

            $out = [];

            // Place 1 & 2 from the final
            $finalMatch = ($rounds[$total - 1] ?? [])[0] ?? null;
            if ($finalMatch) {
                $w = $party($finalMatch, true);
                $l = $party($finalMatch, false);
                $out[] = ['place' => 1, 'team_id' => $w['id'], 'team_name' => $w['name'], 'round_name' => $label($total - 1)];
                $out[] = ['place' => 2, 'team_id' => $l['id'], 'team_name' => $l['name'], 'round_name' => $label($total - 1)];
            }

            // Place 3 & 4 from the semis (joint, no third-place match)
            if ($total >= 2) {
                foreach ($rounds[$total - 2] as $m) {
                    $l    = $party($m, false);
                    $out[] = ['place' => 3, 'team_id' => $l['id'], 'team_name' => $l['name'], 'round_name' => $label($total - 2)];
                }
            }

            return $out ?: null;
        });

        if ($groupsData !== null || $koData !== null) {
            api_ok([
                'groups'             => $groupsData,
                'knockout_standings' => $koData ?? [],
                'standings'          => [],
                'format'             => 'multi_stage',
            ]);
            return;
        }
    }

    $standings = Cache::remember("tournament:{$id}:standings", $ttl, function() use ($id, $t) {
        try {
            return FormatFactory::make($t['format'])->standings($id);
        } catch (\Exception $e) {
            api_error($e->getMessage()); // exits — never stored in cache
        }
    });

    api_ok(['standings' => $standings, 'format' => $t['format']]);
}

