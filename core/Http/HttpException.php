<?php
// core/Http/HttpException.php
namespace Core\Http;

/**
 * Throwable with an explicit HTTP status code. JsonErrorMiddleware maps
 * these to JSON responses; web controllers can catch them too if they want
 * to render an HTML error page instead.
 *
 *   throw new HttpException(404, 'User not found');
 *   throw HttpException::notFound('Session expired');
 *   throw HttpException::unprocessable(['email' => 'is required'], 'Validation failed');
 *
 * The optional $errors payload is surfaced alongside the message in JSON
 * responses — use it for per-field validation errors (HTTP 422) or
 * structured error details on any other status.
 */
class HttpException extends \RuntimeException
{
    private int   $statusCode;
    private array $errors;

    public function __construct(int $statusCode, string $message = '', array $errors = [], ?\Throwable $previous = null)
    {
        // Set the Exception $code too so catch (HttpException $e) { $e->getCode() } works.
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
        $this->errors     = $errors;
    }

    public function statusCode(): int { return $this->statusCode; }
    public function errors(): array   { return $this->errors; }

    // ── Ergonomic factories for the common statuses ──────────────────────────

    public static function badRequest(string $message = 'Bad request', array $errors = []): self      { return new self(400, $message, $errors); }
    public static function unauthorized(string $message = 'Unauthenticated'): self                     { return new self(401, $message); }
    public static function forbidden(string $message = 'Forbidden'): self                              { return new self(403, $message); }
    public static function notFound(string $message = 'Not found'): self                               { return new self(404, $message); }
    public static function methodNotAllowed(string $message = 'Method not allowed'): self              { return new self(405, $message); }
    public static function conflict(string $message = 'Conflict'): self                                { return new self(409, $message); }
    public static function gone(string $message = 'Gone'): self                                        { return new self(410, $message); }
    public static function unprocessable(array $errors, string $message = 'Unprocessable entity'): self{ return new self(422, $message, $errors); }
    public static function tooManyRequests(string $message = 'Too many requests'): self                { return new self(429, $message); }
}
