<?php
/**
 * Engine tests. No WordPress. Exit 0 on pass.
 */
$root = dirname(__DIR__);
require $root . '/load.php';

$failures = 0;
function engine_assert($ok, $message) {
    global $failures;
    if ($ok) {
        echo "ok  $message\n";
        return;
    }
    $failures++;
    echo "FAIL  $message\n";
}

$article = TR_AI_Wire_Engine_Article::from_array(array(
    'title' => 'Qualcomm data center push',
    'lede' => 'A named chip launch.',
    'body_html' => '<p>Grounded body.</p>',
    'tags' => array('Qualcomm', 'Qualcomm', ''),
    'source_url' => 'https://example.com/story',
    'status' => 'publish',
    'site_name' => 'Test Wire',
));
engine_assert($article['slug'] === 'qualcomm-data-center-push', 'slug from title');
engine_assert($article['id'] === 'qualcomm-data-center-push', 'id falls back to slug');
engine_assert($article['tags'] === array('Qualcomm'), 'tags are unique and non-empty');
engine_assert($article['seo_title'] === 'Qualcomm data center push', 'seo_title defaults to title');

$json = TR_AI_Wire_Engine_Article::to_json($article);
$roundtrip = TR_AI_Wire_Engine_Article::from_json($json);
engine_assert($roundtrip['title'] === $article['title'], 'JSON round-trip keeps title');

$secret = 'test-secret';
$sig = TR_AI_Wire_Engine_Webhook::sign($secret, $json);
engine_assert(TR_AI_Wire_Engine_Webhook::verify($secret, $json, $sig), 'webhook signature verifies');
engine_assert(!TR_AI_Wire_Engine_Webhook::verify('other', $json, $sig), 'wrong secret is rejected');
engine_assert(!TR_AI_Wire_Engine_Webhook::verify($secret, $json, ''), 'empty signature is rejected');

$dir = sys_get_temp_dir() . '/tr-ai-wire-engine-' . getmypid();
@mkdir($dir, 0755, true);
$static = new TR_AI_Wire_Engine_Static_Site($dir);
$saved = $static->publish($article);
engine_assert(!empty($saved['ok']), 'static site publish succeeds');
engine_assert(is_file($dir . '/qualcomm-data-center-push.json'), 'JSON file written');
engine_assert(is_file($dir . '/qualcomm-data-center-push.html'), 'HTML file written');
engine_assert(is_file($dir . '/index.html'), 'index.html written');
$html = file_get_contents($dir . '/qualcomm-data-center-push.html');
engine_assert(strpos($html, 'Qualcomm data center push') !== false, 'HTML contains the title');
engine_assert(strpos($html, '<p>Grounded body.</p>') !== false, 'HTML contains the body');

$posted = array();
$webhook = new TR_AI_Wire_Engine_Webhook(
    'https://cms.example/ingest',
    $secret,
    function ($url, $headers, $body) use (&$posted) {
        $posted = array('url' => $url, 'headers' => $headers, 'body' => $body);
        return array('code' => 204, 'body' => '', 'error' => '');
    }
);
$dispatch = new TR_AI_Wire_Engine_Dispatcher();
$results = $dispatch->publish($article, array('webhook' => $webhook, 'static' => $static));
engine_assert(!empty($results['webhook']['ok']), 'dispatcher webhook ok');
engine_assert(!empty($results['static']['ok']), 'dispatcher static ok');
engine_assert($posted['url'] === 'https://cms.example/ingest', 'webhook posted to the given URL');
engine_assert(isset($posted['headers'][TR_AI_Wire_Engine_Webhook::SIGNATURE_HEADER]), 'signature header sent');
engine_assert(TR_AI_Wire_Engine_Webhook::verify($secret, $posted['body'], $posted['headers'][TR_AI_Wire_Engine_Webhook::SIGNATURE_HEADER]), 'posted body matches signature');

$failing = new TR_AI_Wire_Engine_Webhook(
    'https://cms.example/ingest',
    $secret,
    function () {
        return array('code' => 503, 'error' => 'down');
    }
);
$isolated = $dispatch->publish($article, array('webhook' => $failing, 'static' => $static));
engine_assert(empty($isolated['webhook']['ok']), 'failed webhook is reported');
engine_assert(!empty($isolated['static']['ok']), 'static still succeeds when webhook fails');

foreach (glob($dir . '/*') as $file) {
    @unlink($file);
}
@rmdir($dir);

if ($failures > 0) {
    echo $failures . " failed\n";
    exit(1);
}
echo "all passed\n";
exit(0);
