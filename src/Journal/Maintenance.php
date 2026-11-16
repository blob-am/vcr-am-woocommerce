<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Journal;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Keeps the journal's table in place and its size bounded.
 */
final class Maintenance
{
    public const PRUNE_HOOK = 'vcr_prune_journal';

    public const ACTION_GROUP = 'vcr';

    public function __construct(
        private readonly Journal $journal,
        private readonly Schema $schema = new Schema(),
    ) {
    }

    public function register(): void
    {
        $this->schema->ensure();

        add_action(self::PRUNE_HOOK, [$this->journal, 'prune']);
        // Action Scheduler's own tables are not ready before its `init`.
        add_action('action_scheduler_init', [$this, 'schedulePrune']);
    }

    public function schedulePrune(): void
    {
        if (as_has_scheduled_action(self::PRUNE_HOOK, [], self::ACTION_GROUP)) {
            return;
        }

        as_schedule_recurring_action(time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::PRUNE_HOOK, [], self::ACTION_GROUP);
    }
}
