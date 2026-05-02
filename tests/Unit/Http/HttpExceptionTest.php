<?php
// tests/Unit/Http/HttpExceptionTest.php
namespace Tests\Unit\Http;

use Core\Http\HttpException;
use Tests\TestCase;

final class HttpExceptionTest extends TestCase
{
    public function test_default_constructor_sets_code_and_message(): void
    {
        $e = new HttpException(404, 'Not here');
        $this->assertSame(404, $e->statusCode());
        $this->assertSame('Not here', $e->getMessage());
        // Code on the Exception itself mirrors statusCode so catch-all
        // handlers that read getCode() still work.
        $this->assertSame(404, $e->getCode());
    }

    public function test_errors_payload_is_preserved(): void
    {
        $e = new HttpException(422, 'Bad', ['email' => ['required']]);
        $this->assertSame(['email' => ['required']], $e->errors());
    }

    /**
     * @dataProvider factoryProvider
     */
    public function test_factory_produces_expected_status(string $method, int $expected): void
    {
        $e = HttpException::$method();
        $this->assertSame($expected, $e->statusCode());
    }

    /** @return array<int, array{0:string, 1:int}> */
    public static function factoryProvider(): array
    {
        return [
            ['badRequest',        400],
            ['unauthorized',      401],
            ['forbidden',         403],
            ['notFound',          404],
            ['methodNotAllowed',  405],
            ['conflict',          409],
            ['gone',              410],
            ['tooManyRequests',   429],
        ];
    }

    public function test_unprocessable_factory_accepts_errors(): void
    {
        $e = HttpException::unprocessable(['x' => ['y']], 'nope');
        $this->assertSame(422, $e->statusCode());
        $this->assertSame(['x' => ['y']], $e->errors());
        $this->assertSame('nope', $e->getMessage());
    }
}
