<?php
require dirname(__DIR__) . '/bootstrap.php';

$slug = isset($_GET['slug']) ? (string) $_GET['slug'] : '';
$article = tr_ai_wire_standalone_article($slug);
if (!$article || ($article['status'] ?? '') === 'draft') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Article not found.';
    exit;
}

$config = tr_ai_wire_standalone_config();
$site = $config['site_name'];
$meta = $article['meta_description'] !== '' ? $article['meta_description'] : $article['lede'];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo tr_ai_wire_standalone_e($article['title']); ?></title>
    <meta name="description" content="<?php echo tr_ai_wire_standalone_e($meta); ?>">
    <style>
        body { font-family: Georgia, serif; max-width: 42rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; color: #111; }
        img { max-width: 100%; height: auto; }
        nav { margin: 0 0 1.5rem; }
        .lede { font-size: 1.15rem; }
        .source { color: #444; }
        a { color: inherit; }
    </style>
</head>
<body>
    <nav><a href="index.php"><?php echo tr_ai_wire_standalone_e($site); ?></a></nav>
    <article>
        <h1><?php echo tr_ai_wire_standalone_e($article['title']); ?></h1>
        <?php if ($article['lede'] !== '') : ?>
            <p class="lede"><strong><?php echo tr_ai_wire_standalone_e($article['lede']); ?></strong></p>
        <?php endif; ?>
        <?php if ($article['featured_image_url'] !== '') : ?>
            <p><img src="<?php echo tr_ai_wire_standalone_e($article['featured_image_url']); ?>" alt="<?php echo tr_ai_wire_standalone_e($article['title']); ?>"></p>
        <?php endif; ?>
        <?php echo $article['body_html']; ?>
        <?php if ($article['source_url'] !== '') : ?>
            <p class="source">Source: <a href="<?php echo tr_ai_wire_standalone_e($article['source_url']); ?>" rel="noopener noreferrer"><?php echo tr_ai_wire_standalone_e($article['source_title'] !== '' ? $article['source_title'] : $article['source_url']); ?></a></p>
        <?php endif; ?>
    </article>
</body>
</html>
