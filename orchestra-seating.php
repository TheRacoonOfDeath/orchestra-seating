<?php

/**
 * Plugin Name:      Orchestra Seating
 * Plugin URI:       https://github.com/TheRacoonOfDeath/orchestra-seating
 * Description:      Seating plans for the projects of the Orchestra plugin suite.
 * Version:          0.1.0
 * Author:           Julian Nickerl
 * License:          MIT
 * Text Domain:      orchestra-seating
 * Domain Path:      /languages
 * Requires at least: 6.5
 * Requires PHP:     8.3
 * Requires Plugins: orchestra-core, orchestra-projects, orchestra-library, orchestra-members
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

Orchestra\Seating\Plugin::boot(__FILE__);
