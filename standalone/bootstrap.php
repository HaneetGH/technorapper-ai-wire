<?php
$standalone_root = __DIR__;
require_once dirname($standalone_root) . '/engine/load.php';

function tr_ai_wire_standalone_config() {
    static $config = null;
    if (is_array($config)) {
        return $config;
    }
    $path = dirname(__FILE__) . '/config.php';
    $example = dirname(__FILE__) . '/config.example.php';
    $loaded = is_file($path) ? require $path : require $example;
    $defaults = array(
        'site_name' => 'AI Wire',
        'site_tagline' => 'Reviewed feed stories',
        'ingest_secret' => 'change-me',
        'base_url' => 'http://127.0.0.1:8080',
    );
    $config = array_merge($defaults, is_array($loaded) ? $loaded : array());
    return $config;
}

function tr_ai_wire_standalone_store() {
    static $store = null;
    if ($store instanceof TR_AI_Wire_Engine_Article_Store) {
        return $store;
    }
    $dir = dirname(__FILE__) . '/data/articles';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $store = new TR_AI_Wire_Engine_Article_Store($dir);
    return $store;
}

function tr_ai_wire_standalone_articles() {
    return tr_ai_wire_standalone_store()->all();
}

function tr_ai_wire_standalone_article($slug) {
    return tr_ai_wire_standalone_store()->get($slug);
}

function tr_ai_wire_standalone_e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
