<?php
// tests/Unit/Http/OversizedPostTest.php
namespace Tests\Unit\Http;

use App\Middleware\CsrfMiddleware;
use Core\Request;
use Tests\TestCase;

/**
 * An upload that is too large must not be reported as a security event.
 *
 * Over `post_max_size` PHP discards the entire request body before any code
 * here runs. $_POST is empty, which means `_token` is gone, which means the
 * CSRF middleware sees exactly what a stale form looks like — and used to say
 * so: "your session timed out". Every part of that was wrong. The session was
 * fine, the user lost the form they had filled in, their session id was
 * regenerated, and a security.csrf_mismatch row was written against an honest
 * upload (on the Builder, one the health check counts as a blocked attempt).
 *
 * The distinguishing fact is that PHP populates $_POST and $_FILES ITSELF for
 * form-encoded requests. A form post that arrived with a length and left both
 * empty did not merely fail a token check — it was never parsed.
 *
 * Tested directly rather than through a live request, because the first
 * version of this guard read `Request::header('Content-Type')` at a time when
 * that method could not return it: the check looked right and never once
 * fired. A guard that can only be exercised by standing up a session and a
 * 15MB POST is a guard nobody notices is dead.
 */
final class OversizedPostTest extends TestCase
{
    private array $server;
    private array $post;
    private array $files;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->post   = $_POST;
        $this->files  = $_FILES;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_POST   = $this->post;
        $_FILES  = $this->files;
    }

    /**
     * @param array $server extra $_SERVER keys
     * @param array $post   what PHP's parser managed to fill in
     */
    private function request(array $server, array $post = [], array $files = []): Request
    {
        $_POST   = $post;
        $_FILES  = $files;
        $_SERVER = $server + [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/documents',
        ];

        return Request::capture();
    }

    public function testAMultipartPostWithNothingParsedWasDiscarded(): void
    {
        $request = $this->request([
            'CONTENT_TYPE'   => 'multipart/form-data; boundary=----x',
            'CONTENT_LENGTH' => '15728640',
        ]);

        $this->assertTrue(CsrfMiddleware::bodyWasDiscarded($request));
    }

    public function testAUrlEncodedPostWithNothingParsedWasDiscarded(): void
    {
        $request = $this->request([
            'CONTENT_TYPE'   => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH' => '20971520',
        ]);

        $this->assertTrue(CsrfMiddleware::bodyWasDiscarded($request));
    }

    /**
     * The case this must never claim: an ordinary form post that really is
     * missing its token. Treating that as "too large" would turn the CSRF
     * check into advice about upload limits and let a genuine mismatch
     * through unlogged.
     */
    public function testAParsedFormPostIsNotDiscarded(): void
    {
        $request = $this->request(
            ['CONTENT_TYPE' => 'multipart/form-data; boundary=----x', 'CONTENT_LENGTH' => '512'],
            ['kind' => 'notes']
        );

        $this->assertFalse(CsrfMiddleware::bodyWasDiscarded($request));
    }

    public function testAPostCarryingOnlyFilesIsNotDiscarded(): void
    {
        $request = $this->request(
            ['CONTENT_TYPE' => 'multipart/form-data; boundary=----x', 'CONTENT_LENGTH' => '4096'],
            [],
            ['documents' => ['name' => ['a.md'], 'error' => [UPLOAD_ERR_OK], 'tmp_name' => ['/tmp/x']]]
        );

        $this->assertFalse(CsrfMiddleware::bodyWasDiscarded($request));
    }

    /**
     * A JSON body legitimately leaves $_POST empty — PHP does not parse those
     * into it. Those callers send their token in the X-CSRF-Token header,
     * which survives whatever happens to the body, so they must keep reaching
     * the real token check.
     */
    public function testAJsonPostIsNotMistakenForADiscardedBody(): void
    {
        $request = $this->request([
            'CONTENT_TYPE'   => 'application/json',
            'CONTENT_LENGTH' => '15728640',
        ]);

        $this->assertFalse(CsrfMiddleware::bodyWasDiscarded($request));
    }

    public function testARawBodyIsNotMistakenForADiscardedBody(): void
    {
        $request = $this->request([
            'CONTENT_TYPE'   => 'application/octet-stream',
            'CONTENT_LENGTH' => '99999999',
        ]);

        $this->assertFalse(CsrfMiddleware::bodyWasDiscarded($request));
    }

    public function testAFormPostWithNoBodyAtAllIsNotDiscarded(): void
    {
        $request = $this->request(['CONTENT_TYPE' => 'multipart/form-data; boundary=----x']);

        $this->assertFalse(CsrfMiddleware::bodyWasDiscarded($request));
    }

    public function testAPostWithNoContentTypeIsNotDiscarded(): void
    {
        $request = $this->request(['CONTENT_LENGTH' => '15728640']);

        $this->assertFalse(CsrfMiddleware::bodyWasDiscarded($request));
    }

    /**
     * Only POST. PHP fills $_POST for POST alone, so an empty $_POST on a PUT
     * or DELETE says nothing at all about whether the body arrived.
     *
     * @dataProvider otherMethods
     */
    public function testOnlyPostIsJudgedThisWay(string $method): void
    {
        $request = $this->request([
            'REQUEST_METHOD' => $method,
            'CONTENT_TYPE'   => 'multipart/form-data; boundary=----x',
            'CONTENT_LENGTH' => '15728640',
        ]);

        $this->assertFalse(CsrfMiddleware::bodyWasDiscarded($request));
    }

    public static function otherMethods(): array
    {
        return [['PUT'], ['PATCH'], ['DELETE'], ['GET']];
    }
}
