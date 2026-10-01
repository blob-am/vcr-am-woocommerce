<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Action Scheduler integration for receipt-name syncing. Mirrors
 * {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalQueue} and
 * {@see \BlobSolutions\WooCommerceVcrAm\Refund\RefundQueue}: same `vcr` group so
 * admins read one scheduled-actions view, its own hook so the handlers cannot
 * cross-trigger.
 *
 * Why it is queued at all: the sync costs two HTTP round-trips to VCR, and
 * nobody should wait on those while saving a product -- least of all a merchant
 * doing a bulk edit. Nothing downstream depends on it having finished.
 *
 * The attempt number rides along as an action argument rather than in post
 * meta, because unlike a sale there is no row of our own to hang it on. That
 * makes the de-dupe check below exact only for a first attempt, which is
 * deliberate: a fresh edit while a retry is in flight should be allowed
 * through, since it carries the newer name. A duplicate costs one extra read
 * and renames nothing, because {@see OfferTitleSync} compares before writing.
 */
/**
 * Not declared `final` so {@see OfferTitleListener} unit tests can mock the
 * queue; there's no production extension point.
 */
class OfferTitleQueue
{
    public const ACTION_HOOK = 'vcr_sync_offer_title';

    /** Same group as the sale and refund queues -- one place admins look. */
    public const ACTION_GROUP = 'vcr';

    /**
     * Shorter than the sale queue's: a stale receipt name costs the merchant
     * nothing until the next order, and the next product save tries again
     * anyway.
     *
     * @var list<int>
     */
    private const RETRY_DELAYS_SECONDS = [60, 300, 1800];

    public function __construct(
        private readonly OfferTitleSync $sync,
    ) {
    }

    public function register(): void
    {
        add_action(self::ACTION_HOOK, [$this, 'handle'], 10, 2);
    }

    public function enqueue(int $productId): void
    {
        if ($this->hasScheduledAction($productId)) {
            return;
        }

        as_enqueue_async_action(self::ACTION_HOOK, [$productId, 0], self::ACTION_GROUP);
    }

    /**
     * Action Scheduler entry point.
     */
    public function handle(mixed $productId, mixed $attempt = 0): void
    {
        if (! is_int($productId) || ! is_int($attempt)) {
            return;
        }

        if (! $this->sync->run($productId)) {
            return;
        }

        if (! isset(self::RETRY_DELAYS_SECONDS[$attempt])) {
            return;
        }

        as_schedule_single_action(
            time() + self::RETRY_DELAYS_SECONDS[$attempt],
            self::ACTION_HOOK,
            [$productId, $attempt + 1],
            self::ACTION_GROUP,
        );
    }

    private function hasScheduledAction(int $productId): bool
    {
        if (! function_exists('as_get_scheduled_actions')) {
            return false;
        }

        $matches = as_get_scheduled_actions(
            [
                'hook' => self::ACTION_HOOK,
                'args' => [$productId, 0],
                'group' => self::ACTION_GROUP,
                'status' => ['pending', 'in-progress'],
                'per_page' => 1,
            ],
            'ids',
        );

        return is_array($matches) && $matches !== [];
    }
}
