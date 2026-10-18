<?php

declare(strict_types=1);

namespace Orchestra\Seating\Database;

use wpdb;

/**
 * Creates and migrates the tables of orchestra-seating.
 */
final class Schema
{
    /** Increase whenever a table definition changes; dbDelta() applies the difference. */
    public const VERSION = 1;

    public const OPTION_VERSION = 'orchestra_seating_schema_version';

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function plansTable(): string
    {
        return $this->wpdb->prefix . 'orchestra_seating_plans';
    }

    public function seatsTable(): string
    {
        return $this->wpdb->prefix . 'orchestra_seating_seats';
    }

    public function migrateIfOutdated(): void
    {
        $installed = \get_option(self::OPTION_VERSION, 0);
        if (\is_numeric($installed) && (int) $installed >= self::VERSION) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charsetCollate = $this->wpdb->get_charset_collate();

        // "rows" is a reserved word in MySQL 8, hence the prefixed column names.
        // dbDelta() is picky: one column per line, two spaces after PRIMARY KEY, KEY instead of INDEX.
        \dbDelta("CREATE TABLE {$this->plansTable()} (
  project_id bigint(20) unsigned NOT NULL,
  grid_rows int(11) NOT NULL,
  grid_columns int(11) NOT NULL,
  piece_id bigint(20) unsigned DEFAULT NULL,
  seat_organizers tinyint(1) NOT NULL DEFAULT 1,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (project_id)
) {$charsetCollate};");

        \dbDelta("CREATE TABLE {$this->seatsTable()} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  seat_row int(11) NOT NULL,
  seat_column int(11) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY project_user (project_id,user_id),
  UNIQUE KEY project_cell (project_id,seat_row,seat_column),
  KEY user_id (user_id)
) {$charsetCollate};");

        \update_option(self::OPTION_VERSION, self::VERSION, true);
    }

    /**
     * Drops all tables. Only for uninstalling.
     */
    public function drop(): void
    {
        $this->wpdb->query("DROP TABLE IF EXISTS {$this->seatsTable()}");
        $this->wpdb->query("DROP TABLE IF EXISTS {$this->plansTable()}");
        \delete_option(self::OPTION_VERSION);
    }
}
