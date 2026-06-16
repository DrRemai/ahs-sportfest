<?php
declare(strict_types=1);

/**
 * Validation tests for api_tournament_create() and api_tournament_settings().
 *
 * api_tournament_create (~line 1467 in router.php):
 *   - Requires auth
 *   - name:        required
 *   - sport:       required
 *   - format:      must be in FormatFactory::validFormats()
 *   - description: max 280 chars
 *
 * api_tournament_settings (~line 534 in router.php):
 *   - Requires organiser or admin role
 *   - name + sport required
 *   - status must be one of: draft, in_progress, finalised, archived
 */
class TournamentValidationTest extends ApiTestCase
{
    protected static bool $useTransaction = false;

    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$userId = self::createUser();
    }

    // -------------------------------------------------------------------------
    // api_tournament_create — auth
    // -------------------------------------------------------------------------

    public function testCreateRequiresAuth(): void
    {
        $this->clearSession();
        $this->setBody(['name' => 'T', 'sport' => 'Football', 'format' => 'single_elim']);
        $res = $this->captureOutput('api_tournament_create');
        $this->assertFalse($res['ok'] ?? true);
        $this->assertStringContainsStringIgnoringCase('unauthenticated', (string)($res['error'] ?? ''));
    }

    // -------------------------------------------------------------------------
    // api_tournament_create — validation
    // -------------------------------------------------------------------------

    public function testCreateRequiresName(): void
    {
        $this->setSession(self::$userId);
        $this->setBody(['name' => '', 'sport' => 'Football', 'format' => 'single_elim']);
        $res = $this->captureOutput('api_tournament_create');
        $this->assertFail($res);
        $this->assertStringContainsStringIgnoringCase('name', (string)($res['error'] ?? ''));
    }

    public function testCreateRequiresSport(): void
    {
        $this->setSession(self::$userId);
        $this->setBody(['name' => 'My T', 'sport' => '', 'format' => 'single_elim']);
        $res = $this->captureOutput('api_tournament_create');
        $this->assertFail($res);
        $this->assertStringContainsStringIgnoringCase('sport', (string)($res['error'] ?? ''));
    }

    public function testCreateRejectsInvalidFormat(): void
    {
        $this->setSession(self::$userId);
        $this->setBody(['name' => 'My T', 'sport' => 'Football', 'format' => 'invalid_format']);
        $res = $this->captureOutput('api_tournament_create');
        $this->assertFail($res);
        $this->assertStringContainsStringIgnoringCase('format', (string)($res['error'] ?? ''));
    }

    public function testCreateRejectsDescriptionOver280Chars(): void
    {
        $this->setSession(self::$userId);
        $this->setBody([
            'name'        => 'My T',
            'sport'       => 'Football',
            'format'      => 'single_elim',
            'description' => str_repeat('x', 281),
        ]);
        $res = $this->captureOutput('api_tournament_create');
        $this->assertFail($res);
        $this->assertStringContainsStringIgnoringCase('description', (string)($res['error'] ?? ''));
    }

    public function testCreateSuccessWithValidData(): void
    {
        $this->setSession(self::$userId);
        $this->setBody(['name' => 'Valid Tournament', 'sport' => 'Football', 'format' => 'single_elim']);
        $res = $this->captureOutput('api_tournament_create');
        $this->assertOk($res);
        $this->assertArrayHasKey('tournament_id', $res['data'] ?? []);
    }

    // -------------------------------------------------------------------------
    // api_tournament_settings — permission
    // -------------------------------------------------------------------------

    public function testSettingsRequiresOrganiserOrAdmin(): void
    {
        $guestUid = self::createUser();
        $tid      = self::createTournament(self::$userId);

        $this->setSession($guestUid); // no role for this tournament
        $this->setBody(['name' => 'New Name', 'sport' => 'Football', 'status' => 'draft']);
        $res = $this->captureOutput(fn() => api_tournament_settings($tid));
        $this->assertFail($res);
        $this->assertStringContainsStringIgnoringCase('forbidden', (string)($res['error'] ?? ''));
    }

    public function testSettingsAcceptsOrganiserRole(): void
    {
        $tid = self::createTournament(self::$userId);
        self::assignRole($tid, self::$userId, 'organiser', self::$userId);

        $this->setSession(self::$userId);
        $this->setBody(['name' => 'Updated', 'sport' => 'Football', 'status' => 'draft']);
        $res = $this->captureOutput(fn() => api_tournament_settings($tid));
        $this->assertOk($res);
    }

    public function testSettingsRejectsEmptyName(): void
    {
        $tid = self::createTournament(self::$userId);
        self::assignRole($tid, self::$userId, 'organiser', self::$userId);

        $this->setSession(self::$userId);
        $this->setBody(['name' => '', 'sport' => 'Football', 'status' => 'draft']);
        $res = $this->captureOutput(fn() => api_tournament_settings($tid));
        $this->assertFail($res);
    }
}
