<?php
$tests_dir = getenv('WP_TESTS_DIR');
if (!$tests_dir || !file_exists($tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, "WordPress test library not found. Set WP_TESTS_DIR.\n");
    exit(1);
}
$polyfills_path = getenv('WP_TESTS_PHPUNIT_POLYFILLS_PATH');
if ($polyfills_path) {
    define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills_path);
}
require_once $tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', function () {
    require dirname(__DIR__) . '/technorapper-ai-wire.php';
});

require $tests_dir . '/includes/bootstrap.php';
