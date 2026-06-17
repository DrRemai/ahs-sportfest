<?php
declare(strict_types=1);

class Notification
{
    // Supported type constants — extend as new flows are added
    const TYPE_REEVALUATION_SUBMITTED     = 'reevaluation_submitted';
    const TYPE_REEVALUATION_RESOLVED      = 'reevaluation_resolved';
    const TYPE_MATCH_RESULT_POSTED        = 'match_result_posted';
    const TYPE_TEAM_REGISTRATION_APPROVED = 'team_registration_approved';
    const TYPE_TOURNAMENT_STATUS_CHANGED  = 'tournament_status_changed';
    const TYPE_STAFF_ASSIGNED             = 'staff_assigned';

    /**
     * Insert a notification row for a single recipient.
     *
     * @param int    $userUid  Recipient users.id
     * @param string $type     One of the TYPE_* constants
     * @param array  $payload  Arbitrary key/value data; stored as JSONB
     */
    public static function send(int $userUid, string $type, array $payload = []): void
    {
        db()->prepare(
            'INSERT INTO notifications (user_uid, type, payload)
             VALUES (?, ?, ?::jsonb)'
        )->execute([$userUid, $type, json_encode($payload, JSON_THROW_ON_ERROR)]);
        // PERF: invalidate unread-count cache so next poll reflects the new row
        Cache::delete("user:{$userUid}:notifications:count");
    }

    /**
     * Send the same notification to all Staff on a tournament.
     */
    public static function sendToTournamentStaff(
        int    $tournamentId,
        string $type,
        array  $payload = []
    ): void {
        $stmt = db()->prepare(
            'SELECT user_id FROM tournament_roles
             WHERE tournament_id = ? AND role = \'staff\''
        );
        $stmt->execute([$tournamentId]);
        foreach ($stmt->fetchAll() as $row) {
            self::send((int)$row['user_id'], $type, $payload);
        }
    }

    /**
     * Send to all privileged users on a tournament (organiser + staff).
     */
    public static function sendToTournamentTeam(
        int    $tournamentId,
        string $type,
        array  $payload = []
    ): void {
        $stmt = db()->prepare(
            'SELECT user_id FROM tournament_roles WHERE tournament_id = ?'
        );
        $stmt->execute([$tournamentId]);
        foreach ($stmt->fetchAll() as $row) {
            self::send((int)$row['user_id'], $type, $payload);
        }
    }
}
