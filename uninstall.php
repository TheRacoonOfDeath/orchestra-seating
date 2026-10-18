<?php

/**
 * Removes the seating tables and the schema version when the plugin is deleted.
 *
 * Deactivating the plugin keeps them.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

$orchestraSeatingDatabase = $GLOBALS['wpdb'];
if ($orchestraSeatingDatabase instanceof wpdb) {
    (new Orchestra\Seating\Database\Schema($orchestraSeatingDatabase))->drop();
}
