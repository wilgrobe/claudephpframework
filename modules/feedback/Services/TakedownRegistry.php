<?php
// modules/feedback/Services/TakedownRegistry.php
namespace Modules\Feedback\Services;

/**
 * Which module can take down which reported item.
 *
 * An abuse report records what it is about in its context as
 * `abuse: {source, ref}` — `source` names the publishing module's handler,
 * `ref` is that module's own identifier. Reports written before `source`
 * existed carry only `{code}`; when exactly one handler is registered, it is
 * taken to own them, so existing reports keep their takedown button.
 *
 * With no handler registered (a site that publishes nothing reportable) the
 * queue still records and routes abuse reports — it simply offers no takedown.
 */
final class TakedownRegistry
{
    /** @var array<string,TakedownHandler> */
    private static array $handlers = [];

    public static function register(string $source, TakedownHandler $handler): void
    {
        self::$handlers[$source] = $handler;
    }

    /** @return array<string,TakedownHandler> */
    public static function all(): array
    {
        return self::$handlers;
    }

    /** Test isolation. */
    public static function reset(): void
    {
        self::$handlers = [];
    }

    /**
     * The handler and reference an abuse report points at, or null when no
     * registered module can take it down.
     *
     * @param array<string,mixed> $abuse  the report's context['abuse']
     * @return array{source:string,ref:string,handler:TakedownHandler}|null
     */
    public static function resolve(array $abuse): ?array
    {
        $ref = trim((string) ($abuse['ref'] ?? $abuse['code'] ?? ''));
        if ($ref === '') { return null; }

        $source = trim((string) ($abuse['source'] ?? ''));
        if ($source === '') {
            // A report from before `source` was recorded: unambiguous only when
            // a single module could have published it.
            if (count(self::$handlers) !== 1) { return null; }
            $source = (string) array_key_first(self::$handlers);
        }

        $handler = self::$handlers[$source] ?? null;
        return $handler === null ? null : ['source' => $source, 'ref' => $ref, 'handler' => $handler];
    }
}
