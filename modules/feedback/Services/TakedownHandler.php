<?php
// modules/feedback/Services/TakedownHandler.php
namespace Modules\Feedback\Services;

/**
 * A module that publishes something the public can report implements this so
 * an abuse report can take that thing down from the feedback queue.
 *
 * The feedback module owns abuse REPORTS; it does not own whatever was
 * reported. A hosted page, a listing, a profile — each belongs to the module
 * that publishes it, and only that module knows what "take it down" means for
 * its own rows. So the queue asks, through this contract, instead of reaching
 * into another module's tables (which is how the first version of this shipped:
 * a shared module running UPDATE on a table only one site had).
 *
 * Register an implementation with TakedownRegistry::register() from the
 * publishing module's register() hook.
 */
interface TakedownHandler
{
    /** What the thing is, for the button and the messages ("hosted page"). */
    public function label(): string;

    /** Where the operator can look at the reported item, or null. */
    public function url(string $ref): ?string;

    /**
     * Is the item this report names currently public?
     * true = live, false = already down, null = no such item.
     */
    public function isPublished(string $ref): ?bool;

    /**
     * Take it down. Unpublish rather than delete where the item can come back:
     * the report, the item and its history should survive the takedown.
     * The caller reads the result back through isPublished() — do not report
     * success here, just do the work.
     */
    public function unpublish(string $ref): void;
}
