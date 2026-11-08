<?php
/**
 * Plugin Name: TechnoRapper AI Wire
 * Description: Turns configured RSS feed items into attributed, AI-reviewed WordPress drafts or published posts with daily and API usage limits.
 * Version: 0.2.28
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: TechnoRapper
 * License: GPLv2 or later
 * Text Domain: technorapper-ai-wire
 */

if (!defined('ABSPATH')) {
    exit;
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
require_once TR_AI_WIRE_DIR . 'includes/class-seo.php';
require_once TR_AI_WIRE_DIR . 'includes/class-publisher.php';
require_once TR_AI_WIRE_DIR . 'includes/class-scores.php';
require_once TR_AI_WIRE_DIR . 'includes/class-social.php';
require_once TR_AI_WIRE_DIR . 'includes/class-plugin.php';

register_activation_hook(TR_AI_WIRE_FILE, array('TechnoRapper_AI_Wire', 'activate'));
register_deactivation_hook(TR_AI_WIRE_FILE, array('TechnoRapper_AI_Wire', 'deactivate'));
new TechnoRapper_AI_Wire();
