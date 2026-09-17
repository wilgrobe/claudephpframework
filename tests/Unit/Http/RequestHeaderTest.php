<?php
// tests/Unit/Http/RequestHeaderTest.php
namespace Tests\Unit\Http;

use Core\Http\Request;
use Tests\TestCase;

/**
 * Reading a request header, and the two that were unreachable.
 *
 * `header()` builds its lookup as HTTP_<NAME>, which is right for nearly every
 * header and wrong for exactly two: PHP puts Content-Type and Content-Length
 * into $_SERVER WITHOUT the prefix. So `header('Content-Type')` returned null
 * on every request ever made — and null is also what a header that genuinely
 * was not sent returns, so nothing ever looked broken.
 *
 * That is worth a test rather than a comment, because of how it was found. A
 * guard meant to tell an oversized upload apart from a missing CSRF token
 * asked for the content type, got null, concluded "not a form post", and let
 * every oversized upload be reported to the author as an expired session. The
 * code read correctly. Only sending a real 15MB request showed it did nothing.
 *
 * The second half of this file matters as much as the first: the fallback must
 * stay limited to those two names. A blanket "try the unprefixed key too"
 * would turn a header lookup into an arbitrary $_SERVER read, driven by a
 * string the caller often takes from the request itself.
 */
final class RequestHeaderTest extends TestCase
{
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    /** A request carrying exactly the given $_SERVER keys. */
    private function requestWith(array $server): Request
    {
        $_SERVER = $server + [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/documents',
        ];

        return Request::capture();
    }

    public function testContentTypeIsReadableAtAll(): void
    {
        $request = $this->requestWith([
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----x',
        ]);

        $this->assertSame(
            'multipart/form-data; boundary=----x',
            $request->header('Content-Type'),
            'PHP stores this one without the HTTP_ prefix; header() must still find it.'
        );
    }

    public function testContentLengthIsReadableAtAll(): void
    {
        $request = $this->requestWith(['CONTENT_LENGTH' => '15728640']);

        $this->assertSame('15728640', $request->header('Content-Length'));
    }

    public function testOrdinaryHeadersStillComeFromTheHttpPrefix(): void
    {
        $request = $this->requestWith([
            'HTTP_X_CSRF_TOKEN' => 'abc123',
            'HTTP_REFERER'      => 'http://example.test/documents',
            'HTTP_USER_AGENT'   => 'Mozilla/5.0',
        ]);

        $this->assertSame('abc123', $request->header('X-CSRF-Token'));
        $this->assertSame('http://example.test/documents', $request->header('Referer'));
        $this->assertSame('Mozilla/5.0', $request->header('User-Agent'));
    }

    public function testAHeaderThatWasNotSentIsStillNull(): void
    {
        $request = $this->requestWith(['CONTENT_TYPE' => 'text/plain']);

        $this->assertNull($request->header('X-CSRF-Token'));
        $this->assertNull($request->header('Content-Length'));
    }

    /**
     * The prefixed spelling wins where a client really sent one, so a proxy
     * that forwards Content-Type as an ordinary header cannot be shadowed by
     * whatever PHP put in the bare key.
     */
    public function testThePrefixedSpellingIsPreferred(): void
    {
        $request = $this->requestWith([
            'HTTP_CONTENT_TYPE' => 'application/json',
            'CONTENT_TYPE'      => 'text/plain',
        ]);

        $this->assertSame('application/json', $request->header('Content-Type'));
    }

    /**
     * The guard on the fallback. Every one of these exists in a real $_SERVER,
     * and none of them is a request header — if the unprefixed key were tried
     * for any name, a caller passing a header name through from user input
     * could read the environment.
     *
     * @dataProvider serverKeysThatAreNotHeaders
     */
    public function testServerVariablesAreNotReachableAsHeaders(string $header, string $serverKey): void
    {
        $request = $this->requestWith([$serverKey => 'leaked-value']);

        $this->assertNull(
            $request->header($header),
            "header('{$header}') must not reach \$_SERVER['{$serverKey}']."
        );
    }

    public static function serverKeysThatAreNotHeaders(): array
    {
        return [
            'the system PATH'   => ['Path', 'PATH'],
            'the HTTP verb'     => ['Request-Method', 'REQUEST_METHOD'],
            'the document root' => ['Document-Root', 'DOCUMENT_ROOT'],
            'the script name'   => ['Script-Filename', 'SCRIPT_FILENAME'],
            'the remote address'=> ['Remote-Addr', 'REMOTE_ADDR'],
            'the server name'   => ['Server-Name', 'SERVER_NAME'],
        ];
    }

    public function testNamesAreMatchedWithoutRegardToCaseOrDashes(): void
    {
        $request = $this->requestWith([
            'CONTENT_TYPE'      => 'text/csv',
            'HTTP_X_CSRF_TOKEN' => 'tok',
        ]);

        $this->assertSame('text/csv', $request->header('content-type'));
        $this->assertSame('text/csv', $request->header('CONTENT-TYPE'));
        $this->assertSame('tok', $request->header('x-csrf-token'));
    }
}
