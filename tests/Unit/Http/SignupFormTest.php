<?php
// tests/Unit/Http/SignupFormTest.php
namespace Tests\Unit\Http;

use Core\Auth\PasswordPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Two sign-up defects found on StoriesDen, 2026-10-10.
 *
 * 1. After any error every field came back EMPTY. The page that drew the form
 *    took the saved input out of the session to hand the view, and the view
 *    refilled each field with old() — which reads that same saved input, now
 *    gone. Only a password should be blank after an error.
 *
 * 2. The minimum password length was the literal 12 in eleven places, so a site
 *    could not choose 10 (StoriesDen's) without editing all of them — and the
 *    form would accept what the server then refused if one was missed.
 */
final class SignupFormTest extends TestCase
{
    private static function base(): string
    {
        return defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
    }

    public static function setUpBeforeClass(): void
    {
        if (!class_exists(PasswordPolicy::class)) { require_once self::base() . '/core/Auth/PasswordPolicy.php'; }
    }

    protected function tearDown(): void
    {
        putenv('PASSWORD_MIN_LENGTH');
        unset($_ENV['PASSWORD_MIN_LENGTH']);
    }

    public function test_the_minimum_is_one_setting(): void
    {
        $this->assertSame(12, PasswordPolicy::minLength(), 'unset is 12');
        foreach (['10' => 10, '4' => 8, '200' => 64, 'ten' => 12, '' => 12] as $raw => $want) {
            $_ENV['PASSWORD_MIN_LENGTH'] = (string) $raw;
            $this->assertSame($want, PasswordPolicy::minLength(), "PASSWORD_MIN_LENGTH=$raw");
        }
        $_ENV['PASSWORD_MIN_LENGTH'] = '10';
        $this->assertSame('min:10', PasswordPolicy::rule());
    }

    public function test_no_form_or_rule_carries_its_own_number(): void
    {
        $hits = [];
        foreach (['app', 'modules'] as $root) {
            $dir = self::base() . '/' . $root;
            if (!is_dir($dir)) { continue; }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                $p = (string) $f;
                if (!str_ends_with($p, '.php')) { continue; }
                foreach (explode("\n", (string) file_get_contents($p)) as $n => $line) {
                    if (!preg_match('/password/i', $line)) { continue; }
                    if (preg_match('/[\'"|]min:\d+[|\'"]|minlength="(?:1\d|[89])"/', $line)) {
                        $hits[] = substr($p, strlen(self::base()) + 1) . ':' . ($n + 1);
                    }
                }
            }
        }
        $this->assertSame([], $hits, "A password length that does not come from PasswordPolicy:\n  " . implode("\n  ", $hits));
    }

    public function test_the_form_is_refilled_after_an_error(): void
    {
        $src = (string) file_get_contents(self::base() . '/app/Controllers/AuthController.php');
        $this->assertDoesNotMatchRegularExpression("/'old'\s*=>\s*Session::flash\('old'\)/", $src,
            'The page that draws the sign-up form takes the saved input away from old(), so every field comes back blank.');
        $this->assertDoesNotMatchRegularExpression("/Session::flash\('old',\s*\\\$(?:v->all\(\)|request->post\(\))\)/", $src,
            'A failed form is kept in the session with its password in it.');

        $view = (string) file_get_contents(self::base() . '/app/Views/auth/register.php');
        foreach (['first_name', 'last_name', 'email', 'username'] as $field) {
            $this->assertStringContainsString("old('$field')", $view, "$field is not refilled");
        }
        $this->assertDoesNotMatchRegularExpression('/name="password[^"]*"[^>]*value=/', $view, 'A password field is refilled.');
    }
}
