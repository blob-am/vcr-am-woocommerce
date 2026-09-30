<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Catalog\ReceiptName;
use BlobSolutions\WooCommerceVcrAm\Catalog\ReceiptNameField;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('sanitize_text_field')->returnArg();
    Functions\when('wp_unslash')->returnArg();
    $_POST = [];
});

afterEach(function (): void {
    $_POST = [];
});

/**
 * @return object{updates: list<array{int, string, mixed}>, deletes: list<array{int, string}>}
 */
function recordReceiptNameWrites(): object
{
    $recorder = new class () {
        /** @var list<array{int, string, mixed}> */
        public array $updates = [];

        /** @var list<array{int, string}> */
        public array $deletes = [];
    };

    Functions\when('update_post_meta')->alias(
        function (int $id, string $key, mixed $value) use ($recorder): bool {
            $recorder->updates[] = [$id, $key, $value];

            return true;
        },
    );
    Functions\when('delete_post_meta')->alias(
        function (int $id, string $key) use ($recorder): bool {
            $recorder->deletes[] = [$id, $key];

            return true;
        },
    );

    return $recorder;
}

it('registers on the product and the variation forms, and on both saves', function (): void {
    Actions\expectAdded('woocommerce_product_options_general_product_data')->once();
    Actions\expectAdded('woocommerce_process_product_meta')->once();
    Actions\expectAdded('woocommerce_variation_options_pricing')->once();
    Actions\expectAdded('woocommerce_save_product_variation')->once();

    (new ReceiptNameField())->register();
});

it('stores a trimmed override for a product', function (): void {
    $written = recordReceiptNameWrites();
    $_POST[ReceiptName::META_KEY] = '  Chemex 6 cup  ';

    (new ReceiptNameField())->saveForProduct(7);

    expect($written->updates)->toBe([[7, ReceiptName::META_KEY, 'Chemex 6 cup']])
        ->and($written->deletes)->toBe([]);
});

it('removes the override when the box is emptied, so the product name takes over again', function (): void {
    $written = recordReceiptNameWrites();
    $_POST[ReceiptName::META_KEY] = '   ';

    (new ReceiptNameField())->saveForProduct(7);

    expect($written->deletes)->toBe([[7, ReceiptName::META_KEY]])
        ->and($written->updates)->toBe([]);
});

it('ignores a save that carries no field at all', function (): void {
    // WooCommerce fires the save hook for quick edits and other flows that
    // never render this box; writing then would wipe the override.
    $written = recordReceiptNameWrites();

    (new ReceiptNameField())->saveForProduct(7);

    expect($written->updates)->toBe([])
        ->and($written->deletes)->toBe([]);
});

it('ignores an id that is not one', function (): void {
    $written = recordReceiptNameWrites();
    $_POST[ReceiptName::META_KEY] = 'Chemex 6 cup';

    (new ReceiptNameField())->saveForProduct(null);

    expect($written->updates)->toBe([]);
});

it('stores a variation override under the variation id', function (): void {
    $written = recordReceiptNameWrites();
    $_POST[ReceiptName::META_KEY] = [42 => 'Chemex 6 cup, black'];

    (new ReceiptNameField())->saveForVariation(42, 0);

    expect($written->updates)->toBe([[42, ReceiptName::META_KEY, 'Chemex 6 cup, black']]);
});

it('leaves other variations alone when one is saved', function (): void {
    $written = recordReceiptNameWrites();
    $_POST[ReceiptName::META_KEY] = [42 => 'Black', 43 => 'White'];

    (new ReceiptNameField())->saveForVariation(43, 1);

    expect($written->updates)->toBe([[43, ReceiptName::META_KEY, 'White']]);
});
