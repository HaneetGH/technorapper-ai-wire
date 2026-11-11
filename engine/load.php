<?php
/**
 * CMS-agnostic publish layer. No WordPress functions.
 * WordPress, the standalone reader, and later Ghost/webhook hosts all consume this.
 */
if (!defined('TR_AI_WIRE_ENGINE_DIR')) {
    define('TR_AI_WIRE_ENGINE_DIR', __DIR__ . '/');
}

require_once TR_AI_WIRE_ENGINE_DIR . 'class-article.php';
require_once TR_AI_WIRE_ENGINE_DIR . 'class-store.php';
require_once TR_AI_WIRE_ENGINE_DIR . 'class-dispatcher.php';
require_once TR_AI_WIRE_ENGINE_DIR . 'class-webhook.php';
require_once TR_AI_WIRE_ENGINE_DIR . 'class-static-site.php';
