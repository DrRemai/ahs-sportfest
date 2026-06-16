<?php
declare(strict_types=1);

/**
 * Validation tests for api_teams_create() in src/router.php.
 *
 * Rules under test (~line 267 in router.php):
 *   - Requires auth
 *   - name:  required; 2–48 chars (mb_strlen)
 *   - sport: required; 2–32 chars (mb_strlen)
 *   - Unique name per owner (PostgreSQL unique index); returns 422 'team_name_taken'
 */
class TeamValidationTest extends ApiTestCase
{
    protected static bool $useTransaction = false;

    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$userId = self::createUser();
    }

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    public function testCreateTeamRequiresAuth(): void
    {
        $this->clearSession();
        $this->setBody(['name' => 'Rockets', 'sport' => 'Football']);
        $res = $this->captureOutput('api_teams_create');
        $this->assertFalse($res['ok'] ?? true);
        $this->assertStringContainsStringIgnoringCase('unauthenticated', (string)($res['error'] ?? ''));
    }

    // -------------------------------------------------------------------------
    // name
    // -------------------------------------------------------------------------

    public function testTeamNameRequired(): void
    {
        $this->setSession(self::$userId);
        $this->setBody(['name' => '', 'sport' => 'Football']);
        $res = $this->captureOutput('api_teams_create');
        $this->assertValidationError($res, 'name');
    }

    public function testTeamNameTooShort(): void
    {
        $this->setSession(self::$userId);
        $this->setBody(['name' => 'A', 'sport' => 'Football']);
        $res = $this->captureOutput('api_teams_create');
        $this->assertValidationError($res, 'name');
    }

    public function testTeamNameTooLong(): void
    {
        $this->setSession(self::$userId);
        $this->setBody(['name' => str_repeat('x', 49), 'sport' => 'Football']);
        $res = $this->captureOutput('api_teams_create');
        $this->assertValidationError($res, 'name');
    }

    // -------------------------------------------------------------------------
    // sport
    // -------------------------------------------------------------------------

    public function testTeamSportRequired(): void
    {
        $this->setSession(self::$userId);
        $this->setBody(['name' => 'Rockets', 'sport' => '']);
        $res = $this->captureOutput('api_teams_create');
        $this->assertValidationError($res, 'sport');
    }

    public function testTeamSportTooShort(): void
    {
        $this->setSession(self::$userId);
        $this->setBody(['name' => 'Rockets', 'sport' => 'F']);
        $res = $this->captureOutput('api_teams_create');
        $this->assertValidationError($res, 'sport');
    }

    public function testTeamSportTooLong(): void
    {
        $this->setSession(self::$userId);
        $this->setBody(['name' => 'Rockets', 'sport' => str_repeat('x', 33)]);
        $res = $this->captureOutput('api_teams_create');
        $this->assertValidationError($res, 'sport');
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------
public function testCreateTeamSuccess(): void
{
    $this->setSession(self::$userId);
    $name = 'Team_' . bin2hex(random_bytes(4));
    $this->setBody(['name' => $name, 'sport' => 'Football']);
    $res = $this->captureOutput('api_teams_create');
    $this->assertOk($res);
    $this->assertSame($name, $res['data']['name'] ?? '');
}
}
