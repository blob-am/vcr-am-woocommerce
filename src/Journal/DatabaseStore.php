<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Journal;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The journal in the site's own database, in a table of its own.
 *
 * Not the WooCommerce log: that one is a file or a table depending on a store
 * setting, has no API to read it back, and is pruned after 30 days by default.
 */
final class DatabaseStore implements Store
{
    public function append(string $event, ?int $orderId, ?string $traceId, array $data): void
    {
        $wpdb = self::database();
        $encoded = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            throw new StoreException('Journal data could not be encoded as JSON.');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table; there is no WordPress API for it.
        $inserted = $wpdb->insert(
            Schema::tableName($wpdb),
            [
                'created_at' => gmdate('Y-m-d H:i:s'),
                'event' => $event,
                'order_id' => $orderId,
                'trace_id' => $traceId,
                'data' => $encoded,
            ],
            ['%s', '%s', '%d', '%s', '%s'],
        );

        if ($inserted === false) {
            throw new StoreException('Journal insert failed: ' . $wpdb->last_error);
        }
    }

    public function recent(?int $orderId, int $limit): array
    {
        $wpdb = self::database();
        $table = Schema::tableName($wpdb);

        $sql = $orderId === null
            ? $wpdb->prepare('SELECT id, created_at, event, order_id, trace_id, data FROM %i ORDER BY id DESC LIMIT %d', $table, $limit)
            : $wpdb->prepare('SELECT id, created_at, event, order_id, trace_id, data FROM %i WHERE order_id = %d ORDER BY id DESC LIMIT %d', $table, $orderId, $limit);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table, read for a report a person asked for; $sql is the output of prepare() above.
        $rows = $wpdb->get_results($sql, ARRAY_A);

        if (! is_array($rows) || $wpdb->last_error !== '') {
            throw new StoreException('Journal read failed: ' . $wpdb->last_error);
        }

        $entries = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $entries[] = self::toEntry($row);
            }
        }

        return $entries;
    }

    public function prune(string $beforeUtc): int
    {
        $wpdb = self::database();

        $sql = $wpdb->prepare('DELETE FROM %i WHERE created_at < %s', Schema::tableName($wpdb), $beforeUtc);

        if (! is_string($sql)) {
            throw new StoreException('Journal prune query could not be prepared.');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table; $sql is the output of prepare() above.
        $deleted = $wpdb->query($sql);

        if (! is_int($deleted)) {
            throw new StoreException('Journal prune failed: ' . $wpdb->last_error);
        }

        return $deleted;
    }

    /**
     * @param array<mixed> $row
     */
    private static function toEntry(array $row): Entry
    {
        $rawData = $row['data'] ?? null;
        $data = is_string($rawData) ? json_decode($rawData, true) : null;

        if (! is_array($data)) {
            throw new StoreException('Journal line holds data that is not a JSON object.');
        }

        $orderId = $row['order_id'] ?? null;
        $traceId = $row['trace_id'] ?? null;

        /** @var array<string, mixed> $data */
        return new Entry(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : throw new StoreException('Journal line has no id.'),
            createdAt: is_string($row['created_at'] ?? null) ? $row['created_at'] : throw new StoreException('Journal line has no time.'),
            event: is_string($row['event'] ?? null) ? $row['event'] : throw new StoreException('Journal line has no event.'),
            orderId: is_numeric($orderId) ? (int) $orderId : null,
            traceId: is_string($traceId) && $traceId !== '' ? $traceId : null,
            data: $data,
        );
    }

    private static function database(): \wpdb
    {
        /** @var \wpdb|null $wpdb */
        global $wpdb;

        if (! $wpdb instanceof \wpdb) {
            throw new StoreException('The WordPress database is not available.');
        }

        return $wpdb;
    }
}
