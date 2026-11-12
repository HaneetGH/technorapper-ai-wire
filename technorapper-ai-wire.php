<?php
/**
 * Plugin Name: TechnoRapper AI Wire
 * Description: Turns configured RSS feed items into attributed, AI-reviewed WordPress drafts or published posts with daily and API usage limits.
 * Version: 0.2.77
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: TechnoRapper
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: technorapper-ai-wire
 */

if (!defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    add_action('admin_notices', 'tr_ai_wire_php_version_notice');
    return;
}

if (!function_exists('tr_ai_wire_php_version_notice')) {
    function tr_ai_wire_php_version_notice() {
        echo '<div class="notice notice-error"><p>TechnoRapper AI Wire requires PHP 7.4 or later.</p></div>';
    }
}

if (class_exists('TechnoRapper_AI_Wire')) {
    return;
}

define('TR_AI_WIRE_FILE', __FILE__);
define('TR_AI_WIRE_DIR', plugin_dir_path(__FILE__));

require_once TR_AI_WIRE_DIR . 'includes/class-schema.php';
require_once TR_AI_WIRE_DIR . 'includes/class-settings.php';
require_once TR_AI_WIRE_DIR . 'includes/class-tenants.php';
require_once TR_AI_WIRE_DIR . 'includes/class-admin.php';
require_once TR_AI_WIRE_DIR . 'includes/class-queue.php';
require_once TR_AI_WIRE_DIR . 'includes/class-feeds.php';
require_once TR_AI_WIRE_DIR . 'includes/class-ai.php';
require_once TR_AI_WIRE_DIR . 'includes/class-pipeline.php';
require_once TR_AI_WIRE_DIR . 'includes/class-seo.php';
require_once TR_AI_WIRE_DIR . 'includes/class-publisher.php';
require_once TR_AI_WIRE_DIR . 'includes/class-scores.php';
require_once TR_AI_WIRE_DIR . 'includes/class-social.php';
require_once TR_AI_WIRE_DIR . 'includes/class-social-reel.php';
require_once TR_AI_WIRE_DIR . 'includes/class-social-youtube.php';
require_once TR_AI_WIRE_DIR . 'includes/class-instagram-oauth.php';
require_once TR_AI_WIRE_DIR . 'includes/class-youtube-oauth.php';
require_once TR_AI_WIRE_DIR . 'includes/class-jobs.php';
require_once TR_AI_WIRE_DIR . 'includes/class-plugin.php';

register_activation_hook(TR_AI_WIRE_FILE, array('TechnoRapper_AI_Wire', 'activate'));
register_deactivation_hook(TR_AI_WIRE_FILE, array('TechnoRapper_AI_Wire', 'deactivate'));
new TechnoRapper_AI_Wire();
