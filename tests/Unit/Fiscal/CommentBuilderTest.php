<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Fiscal\CommentBuilder;
use Mockery;
use WC_Order;

/**
 * @param  string|null  $transactionId  `null` = the getter is never expected to
 *                                       be called (order-number-only sources).
 */
function orderForComment(string $orderNumber = '1234', ?string $transactionId = null): WC_Order
{
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_order_number')->andReturn($orderNumber);

    if ($transactionId !== null) {
        $order->allows('get_transaction_id')->andReturn($transactionId);
    }

    return $order;
}

it('prefixes the WooCommerce order number', function (): void {
    $comment = (new CommentBuilder())->build(
        orderForComment('1234'),
        Configuration::COMMENT_SOURCE_ORDER_NUMBER,
    );

    expect($comment)->toBe('WooCommerce #1234');
});

it('respects a sequential-order-number prefix from get_order_number()', function (): void {
    $comment = (new CommentBuilder())->build(
        orderForComment('WC-000512'),
        Configuration::COMMENT_SOURCE_ORDER_NUMBER,
    );

    expect($comment)->toBe('WooCommerce #WC-000512');
});

it('returns the raw transaction id', function (): void {
    $comment = (new CommentBuilder())->build(
        orderForComment('1234', 'pi_3QabcXYZ'),
        Configuration::COMMENT_SOURCE_TRANSACTION_ID,
    );

    expect($comment)->toBe('pi_3QabcXYZ');
});

it('returns null when the transaction id source has no value', function (): void {
    $comment = (new CommentBuilder())->build(
        orderForComment('1234', '  '),
        Configuration::COMMENT_SOURCE_TRANSACTION_ID,
    );

    expect($comment)->toBeNull();
});

it('combines order number and transaction id when both are present', function (): void {
    $comment = (new CommentBuilder())->build(
        orderForComment('1234', 'pi_3QabcXYZ'),
        Configuration::COMMENT_SOURCE_ORDER_AND_TRANSACTION,
    );

    expect($comment)->toBe('WooCommerce #1234 (pi_3QabcXYZ)');
});

it('falls back to the order number alone when the combined source has no transaction id', function (): void {
    $comment = (new CommentBuilder())->build(
        orderForComment('1234', ''),
        Configuration::COMMENT_SOURCE_ORDER_AND_TRANSACTION,
    );

    expect($comment)->toBe('WooCommerce #1234');
});

it('returns null when comments are turned off', function (): void {
    $comment = (new CommentBuilder())->build(
        orderForComment('1234'),
        Configuration::COMMENT_SOURCE_OFF,
    );

    expect($comment)->toBeNull();
});

it('returns null for an unknown stored source', function (): void {
    $comment = (new CommentBuilder())->build(
        orderForComment('1234'),
        'legacy_unknown_value',
    );

    expect($comment)->toBeNull();
});

it('caps the comment at the 500-character VCR limit', function (): void {
    $longTransactionId = str_repeat('x', 600);

    $comment = (new CommentBuilder())->build(
        orderForComment('1234', $longTransactionId),
        Configuration::COMMENT_SOURCE_TRANSACTION_ID,
    );

    expect($comment)->not->toBeNull()
        ->and(mb_strlen($comment ?? ''))->toBe(500);
});
