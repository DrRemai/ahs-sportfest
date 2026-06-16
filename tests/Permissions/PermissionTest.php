<?php
declare(strict_types=1);

/**
 * Tests for src/Permissions.php.
 *
 * The pure canXxx() methods take a ?string $role and need no database access.
 * The resolveRole() method wraps tournament_role() and therefore requires a
 * live tournament row and a session user.
 *
 * All DB-touching tests run inside the shared BEGIN/ROLLBACK transaction
 * inherited from TestCase.
 */
class PermissionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // canEnterMatchResult
    // -------------------------------------------------------------------------

    public function testGuestCannotEnterMatchResult(): void
    {
        $this->assertFalse(Permissions::canEnterMatchResult(null));
    }

    public function testStaffCanEnterMatchResult(): void
    {
        $this->assertTrue(Permissions::canEnterMatchResult('staff'));
    }

    public function testOrganiserCanEnterMatchResult(): void
    {
        $this->assertTrue(Permissions::canEnterMatchResult('organiser'));
    }

    public function testAdminRoleCanEnterMatchResult(): void
    {
        $this->assertTrue(Permissions::canEnterMatchResult('admin'));
    }

    // -------------------------------------------------------------------------
    // canAcceptResult
    // -------------------------------------------------------------------------

    public function testStaffCannotDirectlyAcceptResult(): void
    {
        $this->assertFalse(Permissions::canAcceptResult('staff'));
    }

    public function testOrganiserCanAcceptResult(): void
    {
        $this->assertTrue(Permissions::canAcceptResult('organiser'));
    }

    public function testAdminCanAcceptResult(): void
    {
        $this->assertTrue(Permissions::canAcceptResult('admin'));
    }

    // -------------------------------------------------------------------------
    // canEditTournament
    // -------------------------------------------------------------------------

    public function testOrganiserCanEditTournament(): void
    {
        $this->assertTrue(Permissions::canEditTournament('organiser'));
    }

    public function testStaffCannotEditTournament(): void
    {
        $this->assertFalse(Permissions::canEditTournament('staff'));
    }

    public function testGuestCannotEditTournament(): void
    {
        $this->assertFalse(Permissions::canEditTournament(null));
    }

    // -------------------------------------------------------------------------
    // canViewDraft
    // -------------------------------------------------------------------------

    public function testGuestCannotViewDraft(): void
    {
        $this->assertFalse(Permissions::canViewDraft(null));
    }

    public function testStaffCanViewDraft(): void
    {
        $this->assertTrue(Permissions::canViewDraft('staff'));
    }

    public function testOrganiserCanViewDraft(): void
    {
        $this->assertTrue(Permissions::canViewDraft('organiser'));
    }

    // -------------------------------------------------------------------------
    // canResolveReevaluation
    // -------------------------------------------------------------------------

    public function testGuestCannotResolveReevaluation(): void
    {
        $this->assertFalse(Permissions::canResolveReevaluation(null));
    }

    public function testOrganiserCanResolveReevaluation(): void
    {
        $this->assertTrue(Permissions::canResolveReevaluation('organiser'));
    }

    // -------------------------------------------------------------------------
    // canForceApproveReevaluation
    // -------------------------------------------------------------------------

    public function testNonAdminCannotForceApprove(): void
    {
        $this->assertFalse(Permissions::canForceApproveReevaluation(['uid' => 1, 'is_admin' => false]));
    }

    public function testAdminCanForceApprove(): void
    {
        $this->assertTrue(Permissions::canForceApproveReevaluation(['uid' => 1, 'is_admin' => true]));
    }

    // -------------------------------------------------------------------------
    // canArchiveTeam
    // -------------------------------------------------------------------------

    public function testTeamOwnerCanArchive(): void
    {
        $this->assertTrue(Permissions::canArchiveTeam(['uid' => 5, 'is_admin' => false], 5));
    }

    public function testNonOwnerNonAdminCannotArchive(): void
    {
        $this->assertFalse(Permissions::canArchiveTeam(['uid' => 7, 'is_admin' => false], 5));
    }

    public function testAdminCanArchiveAnyTeam(): void
    {
        $this->assertTrue(Permissions::canArchiveTeam(['uid' => 99, 'is_admin' => true], 5));
    }

    // -------------------------------------------------------------------------
    // resolveRole — requires DB
    // -------------------------------------------------------------------------

    public function testResolveRoleReturnsGuestForUnauthenticated(): void
    {
        $this->clearSession();
        $uid = self::createUser();
        $tid = self::createTournament($uid);

        $this->assertSame('guest', Permissions::resolveRole($tid));
    }

    public function testResolveRoleReturnsOrganiser(): void
    {
        $uid = self::createUser();
        $tid = self::createTournament($uid);
        self::assignRole($tid, $uid, 'organiser', $uid);
        $this->setSession($uid);

        $this->assertSame('organiser', Permissions::resolveRole($tid));
    }

    public function testResolveRoleReturnsAdminForAdminUser(): void
    {
        $adminUid = self::createUser('', true);
        $uid      = self::createUser();
        $tid      = self::createTournament($uid);

        // Admin gets 'admin' regardless of tournament_roles rows
        $this->setSession($adminUid, true);
        $this->assertSame('admin', Permissions::resolveRole($tid));
    }
}
