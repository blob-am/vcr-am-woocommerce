<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Journal\Journal;
use BlobSolutions\WooCommerceVcrAm\Journal\Maintenance;
use BlobSolutions\WooCommerceVcrAm\Journal\Schema;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

it('skips the table check when the stored schema version is current', function (): void {
    Functions\expect('get_option')->once()->with(Schema::OPTION_VERSION)->andReturn(Schema::VERSION);
    Functions\expect('dbDelta')->never();
    Actions\expectAdded(Maintenance::PRUNE_HOOK)->once();
    Actions\expectAdded('action_scheduler_init')->once();

    (new Maintenance(Mockery::mock(Journal::class)))->register();
});

it('schedules the daily prune once', function (): void {
    Functions\expect('as_has_scheduled_action')->once()
        ->with(Maintenance::PRUNE_HOOK, [], Maintenance::ACTION_GROUP)->andReturn(false);
    Functions\expect('as_schedule_recurring_action')->once()
        ->with(Mockery::type('int'), 86400, Maintenance::PRUNE_HOOK, [], Maintenance::ACTION_GROUP);

    (new Maintenance(Mockery::mock(Journal::class)))->schedulePrune();
});

it('does not schedule a second prune', function (): void {
    Functions\when('as_has_scheduled_action')->justReturn(true);
    Functions\expect('as_schedule_recurring_action')->never();

    (new Maintenance(Mockery::mock(Journal::class)))->schedulePrune();
});
