<?php
// core/Events/EventBus.php
namespace Core\Events;

/**
 * Minimal in-process event bus.
 *
 * Modules register listeners (typically in their `module.php` register()
 * method) and emit named events from anywhere. Synchronous + single-
 * process — listeners run inline on the emit() call. No queue, no
 * cross-request fan-out; if you need that, push the event to the
 * Core\Queue surface from a listener.
 *
 * Naming convention: `<module>.<noun>.<verb_past_tense>`
 *   e.g. store.order.completed, payments.refund.issued.
 *
 * Listeners receive (string $event, array $payload) and may return
 * any value; emit() returns the array of return values in registration
 * order, so callers can compose multi-listener results when needed.
 *
 * Errors in listeners are caught and logged via error_log() so a
 * single failing listener doesn't break the emitter's own flow.
 */
final class EventBus
{
    /** @var array<string, list<callable>> */
    private static array $listeners = [];

    /**
     * Register a listener for $event. Multiple listeners for the same
     * event are fine — they fire in registration order.
     */
    public static function on(string $event, callable $handler): void
    {
        self::$listeners[$event][] = $handler;
    }

    /**
     * Fire $event with $payload. Returns a list of listener return
     * values (in registration order) so callers can compose multi-
     * listener results. Returns empty array when no listeners are
     * registered for the event.
     */
    public static function emit(string $event, array $payload = []): array
    {
        if (empty(self::$listeners[$event])) return [];
        $out = [];
        foreach (self::$listeners[$event] as $handler) {
            try {
                $out[] = $handler($event, $payload);
            } catch (\Throwable $e) {
                error_log("[EventBus] $event listener failed: " . $e->getMessage());
                $out[] = null;
            }
        }
        return $out;
    }

    /**
     * Test-only helper — clear all listeners. Production code should
     * never need this.
     */
    public static function reset(): void
    {
        self::$listeners = [];
    }

    /**
     * Test-only helper — list registered event names. Useful for
     * verifying a module wired its listeners during boot.
     *
     * @return list<string>
     */
    public static function registered(): array
    {
        return array_keys(self::$listeners);
    }
}
