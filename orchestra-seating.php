<?php

/**
 * Plugin Name:      Orchestra Seating
 * Plugin URI:       https://github.com/TheRacoonOfDeath/orchestra-seating
 * Description:      Seating plans for the projects of the Orchestra plugin suite.
 * Version:          0.3.0
 * Author:           Julian Nickerl
 * License:          MIT
 * Text Domain:      orchestra-seating
 * Domain Path:      /languages
 * Requires at least: 6.5
 * Requires PHP:     8.3
 * Requires Plugins: orchestra-core, orchestra-projects
 */

/*
 * orchestra-library and orchestra-members are required too, but through orchestra-projects only:
 * WordPress reports a false circular dependency when a plugin also lists the dependencies of one
 * of its dependencies. Plugin::registerFeatures() checks that all of them are loaded.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

Orchestra\Seating\Plugin::boot(__FILE__);
