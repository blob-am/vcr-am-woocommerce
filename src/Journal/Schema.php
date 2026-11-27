<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Journal;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The journal table: its name, its shape, and making sure it exists.
 *
 * Checked on every request against an autoloaded option rather than on
 * activation, because a plugin updated over FTP never runs its activation
 * hook, and a journal that silently does not exist is worse than none.
 */
final class Schema
{
    public const TABLE = 'vcr_journal';

    public const OPTION_VERSION = 'vcr_journal_schema';

    /** Bump when the CREATE TABLE below changes; dbDelta applies the difference. */
    public const VERSION = '1';

    public static function tableName(\wpdb $wpdb): string
    {
        return $wpdb->prefix . self::TABLE;
    }

    public function ensure(): void
    {
        if (get_option(self::OPTION_VERSION) === self::VERSION) {
            return;
        }

        /** @var \wpdb|null $wpdb */
        global $wpdb;

        if (! $wpdb instanceof \wpdb) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // dbDelta parses this text itself: two spaces after PRIMARY KEY, one
        // column per line, KEY not INDEX.
        dbDelta(sprintf(
            'CREATE TABLE %s (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  event varchar(64) NOT NULL,
  order_id bigint(20) unsigned DEFAULT NULL,
  trace_id char(32) DEFAULT NULL,
  data longtext NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY order_id (order_id, id)
) %s;',
            self::tableName($wpdb),
            $wpdb->get_charset_collate(),
        ));

        // Stamped only once the table answers, so a host that refused the
        // CREATE is asked again on the next request instead of never.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- asks whether the plugin's own table exists; WordPress has no API for it, and a cached answer would defeat the check.
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(self::tableName($wpdb))));

        if ($found === self::tableName($wpdb)) {
            update_option(self::OPTION_VERSION, self::VERSION, true);
        }
    }
}
