<?php
declare(strict_types=1);

/**
 * Validation tests for api_register() in src/router.php.
 *
 * Rules under test (from router.php ~line 204):
 *   username: required; /^[a-z0-9_-]{3,32}$/
 *   password: required; min 8 chars; must contain a digit
 *   display_name: required; max 64 chars (mb_strlen)
 *   uniqueness: api_error('username_taken', 422) on duplicate
 */
class UserValidationTest extends ApiTestCase
{
    // -------------------------------------------------------------------------
    // username
    // -------------------------------------------------------------------------

    public function testRegisterRequiresUsername(): void
    {
        $this->setBody(['username' => '', 'password' => 'Password1', 'display_name' => 'Test']);
        $res = $this->captureOutput('api_register');
        $this->assertValidationError($res, 'username');
    }

    public function testRegisterUsernameTooShort(): void
    {
        $this->setBody(['username' => 'ab', 'password' => 'Password1', 'display_name' => 'Test']);
        $res = $this->captureOutput('api_register');
        $this->assertValidationError($res, 'username');
    }

    public function testRegisterUsernameInvalidChars(): void
    {
        $this->setBody(['username' => 'bad user!', 'password' => 'Password1', 'display_name' => 'Test']);
        $res = $this->captureOutput('api_register');
        $this->assertValidationError($res, 'username');
    }

    public function testRegisterUsernameTooLong(): void
    {
        $this->setBody([
            'username'     => str_repeat('a', 33),
            'password'     => 'Password1',
            'display_name' => 'Test',
        ]);
        $res = $this->captureOutput('api_register');
        $this->assertValidationError($res, 'username');
    }

    // -------------------------------------------------------------------------
    // password
    // -------------------------------------------------------------------------

    public function testRegisterRequiresPassword(): void
    {
        $this->setBody(['username' => 'validuser', 'password' => '', 'display_name' => 'Test']);
        $res = $this->captureOutput('api_register');
        $this->assertValidationError($res, 'password');
    }

    public function testRegisterPasswordTooShort(): void
    {
        $this->setBody(['username' => 'validuser', 'password' => 'Pass1', 'display_name' => 'Test']);
        $res = $this->captureOutput('api_register');
        $this->assertValidationError($res, 'password');
    }

    public function testRegisterPasswordMustContainDigit(): void
    {
        $this->setBody(['username' => 'validuser', 'password' => 'NoDigitPass', 'display_name' => 'Test']);
        $res = $this->captureOutput('api_register');
        $this->assertValidationError($res, 'password');
    }

    // -------------------------------------------------------------------------
    // display_name
    // -------------------------------------------------------------------------

    public function testRegisterRequiresDisplayName(): void
    {
        $this->setBody(['username' => 'validuser', 'password' => 'Password1', 'display_name' => '']);
        $res = $this->captureOutput('api_register');
        $this->assertValidationError($res, 'display_name');
    }

    public function testRegisterDisplayNameTooLong(): void
    {
        $this->setBody([
            'username'     => 'validuser',
            'password'     => 'Password1',
            'display_name' => str_repeat('A', 65),
        ]);
        $res = $this->captureOutput('api_register');
        $this->assertValidationError($res, 'display_name');
    }

    // -------------------------------------------------------------------------
    // Happy path & uniqueness
    // -------------------------------------------------------------------------

    public function testRegisterSuccessReturnsOk(): void
    {
        $uname = 'testok_' . bin2hex(random_bytes(4));
        $this->setBody([
            'username'     => $uname,
            'password'     => 'Password1',
            'display_name' => 'Test OK',
        ]);
        $res = $this->captureOutput('api_register');
        $this->assertOk($res);
    }

    public function testRegisterUsernameTakenReturnsError(): void
    {
        // Use an existing user created in the transaction (will be rolled back)
        $uid   = self::createUser('existinguser_' . bin2hex(random_bytes(4)));
        $uname = $this->getUsername($uid);

        $this->setBody([
            'username'     => $uname,
            'password'     => 'Password1',
            'display_name' => 'Duplicate',
        ]);
        $res = $this->captureOutput('api_register');
        $this->assertFalse($res['ok'] ?? true);
        $this->assertStringContainsString('username_taken', (string)($res['error'] ?? ''));
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function getUsername(int $uid): string
    {
        $stmt = static::$db->prepare('SELECT username FROM users WHERE id = ?');
        $stmt->execute([$uid]);
        return (string)$stmt->fetchColumn();
    }
}
