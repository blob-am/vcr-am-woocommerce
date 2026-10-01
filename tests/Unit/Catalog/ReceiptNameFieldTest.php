<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Catalog\ReceiptName;
use BlobSolutions\WooCommerceVcrAm\Catalog\ReceiptNameField;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('sanitize_text_field')->returnArg();
    Functions\when('wp_unslash')->returnArg();
    // Nothing stored unless a test seeds it; a save compares before writing.
    Functions\when('get_post_meta')->justReturn('');
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
    Functions\when('get_post_meta')->justReturn('Chemex 6 cup');
    $written = recordReceiptNameWrites();
    $_POST[ReceiptName::META_KEY] = '   ';

    (new ReceiptNameField())->saveForProduct(7);

    expect($written->deletes)->toBe([[7, ReceiptName::META_KEY]])
        ->and($written->updates)->toBe([]);
});

it('writes nothing when the box comes back with the name already stored', function (): void {
    Functions\when('get_post_meta')->justReturn('Chemex 6 cup');
    $written = recordReceiptNameWrites();
    $_POST[ReceiptName::META_KEY] = 'Chemex 6 cup';

    (new ReceiptNameField())->saveForProduct(7);

    expect($written->updates)->toBe([])
        ->and($written->deletes)->toBe([]);
});

it('does not delete an override that was never there', function (): void {
    $written = recordReceiptNameWrites();
    $_POST[ReceiptName::META_KEY] = '';

    (new ReceiptNameField())->saveForProduct(7);

    expect($written->deletes)->toBe([])
        ->and($written->updates)->toBe([]);
});

it('announces a change so the catalog can be brought in line', function (): void {
    $written = recordReceiptNameWrites();
    $_POST[ReceiptName::META_KEY] = 'Chemex 6 cup';

    Actions\expectDone(ReceiptNameField::CHANGED_ACTION)->once()->with(7);

    (new ReceiptNameField())->saveForProduct(7);

    expect($written->updates)->toHaveCount(1);
});

it('announces an emptied box too, because that is also an answer', function (): void {
    Functions\when('get_post_meta')->justReturn('Chemex 6 cup');
    recordReceiptNameWrites();
    $_POST[ReceiptName::META_KEY] = '';

    Actions\expectDone(ReceiptNameField::CHANGED_ACTION)->once()->with(7);

    (new ReceiptNameField())->saveForProduct(7);
});

it('stays quiet when a product is saved without the name changing', function (): void {
    Functions\when('get_post_meta')->justReturn('Chemex 6 cup');
    recordReceiptNameWrites();
    $_POST[ReceiptName::META_KEY] = 'Chemex 6 cup';

    Actions\expectDone(ReceiptNameField::CHANGED_ACTION)->never();

    (new ReceiptNameField())->saveForProduct(7);
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

/**
 * Captures what the field hands WooCommerce to render, which is the only place
 * the inherited value becomes visible to a merchant.
 *
 * @return object{fields: list<array<string, mixed>>}
 */
function recordRenderedFields(): object
{
    $recorder = new class () {
        /** @var list<array<string, mixed>> */
        public array $fields = [];
    };

    Functions\when('woocommerce_wp_text_input')->alias(
        function (array $field) use ($recorder): void {
            $recorder->fields[] = $field;
        },
    );

    return $recorder;
}

/** The post object WooCommerce passes to `woocommerce_variation_options_pricing`. */
function variationPost(int $id, int $parentId): object
{
    return (object) ['ID' => $id, 'post_parent' => $parentId];
}

it('offers the parent product\'s receipt name as the variation placeholder', function (): void {
    Functions\when('get_post_meta')->alias(
        static fn (int $postId, string $key, bool $single = false): string => $postId === 7 ? 'Chemex 6 cup' : '',
    );
    $rendered = recordRenderedFields();

    (new ReceiptNameField())->renderForVariation(0, [], variationPost(8, 7));

    expect($rendered->fields)->toHaveCount(1)
        ->and($rendered->fields[0]['placeholder'])->toBe('Chemex 6 cup')
        ->and($rendered->fields[0]['value'])->toBe('')
        ->and($rendered->fields[0]['description'])->toContain('parent product')
        ->and($rendered->fields[0]['description'])->toContain('Chemex 6 cup');
});

it('tells a variation with no inherited name what would be printed instead', function (): void {
    Functions\when('get_post_meta')->justReturn('');
    $rendered = recordRenderedFields();

    (new ReceiptNameField())->renderForVariation(0, [], variationPost(8, 7));

    expect($rendered->fields[0]['placeholder'])->toBe('')
        ->and($rendered->fields[0]['description'])->toContain('plus its attributes');
});

it('shows a variation its own name rather than the inherited one once it has one', function (): void {
    Functions\when('get_post_meta')->alias(
        static fn (int $postId, string $key, bool $single = false): string => $postId === 8 ? 'Chemex black' : 'Chemex 6 cup',
    );
    $rendered = recordRenderedFields();

    (new ReceiptNameField())->renderForVariation(0, [], variationPost(8, 7));

    expect($rendered->fields[0]['value'])->toBe('Chemex black')
        ->and($rendered->fields[0]['placeholder'])->toBe('Chemex 6 cup');
});

it('says on the product that its variations inherit the name', function (): void {
    Functions\when('get_the_ID')->justReturn(7);
    Functions\when('get_post_meta')->justReturn('');
    $rendered = recordRenderedFields();

    (new ReceiptNameField())->renderForProduct();

    expect($rendered->fields[0]['description'])->toContain('Variations whose own box is empty');
});
