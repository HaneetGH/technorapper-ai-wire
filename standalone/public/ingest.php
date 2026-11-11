<?php
require dirname(__DIR__) . '/bootstrap.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":false,"error":"POST an approved article JSON body."}';
    exit;
}

$raw = file_get_contents('php://input');
$header = isset($_SERVER['HTTP_X_AI_WIRE_SIGNATURE']) ? (string) $_SERVER['HTTP_X_AI_WIRE_SIGNATURE'] : '';
$config = tr_ai_wire_standalone_config();
$secret = (string) ($config['ingest_secret'] ?? '');

header('Content-Type: application/json; charset=utf-8');
if (!TR_AI_Wire_Engine_Webhook::verify($secret, $raw, $header)) {
    http_response_code(401);
    echo '{"ok":false,"error":"Invalid signature."}';
    exit;
}

$article = TR_AI_Wire_Engine_Article::from_json($raw);
$article['status'] = $article['status'] === 'draft' ? 'draft' : 'publish';
if ($article['site_name'] === '') {
    $article['site_name'] = (string) $config['site_name'];
}
$saved = tr_ai_wire_standalone_store()->put($article);
if (empty($saved['ok'])) {
    http_response_code(400);
    echo '{"ok":false,"error":"Could not store the article."}';
    exit;
}

http_response_code(204);
