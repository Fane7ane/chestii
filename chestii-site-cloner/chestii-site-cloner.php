<?php
/**
 * Plugin Name: Chestii Site Cloner
 * Description: Exportă și importă pachete de site WordPress (bază de date + fișiere wp-content).
 * Version: 0.1.0
 * Author: Chestii
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/class-chestii-site-cloner.php';

add_action('plugins_loaded', static function () {
    Chestii_Site_Cloner::init();
});
