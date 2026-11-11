<?php
require dirname(__DIR__) . '/bootstrap.php';

$articles = tr_ai_wire_standalone_articles();
$config = tr_ai_wire_standalone_config();
$site = $config['site_name'];
$tagline = $config['site_tagline'];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo tr_ai_wire_standalone_e($site); ?></title>
    <style>
        body { font-family: Georgia, serif; max-width: 42rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; color: #111; }
        h1 { font-size: 1.75rem; }
        p.tag { color: #444; }
        li { margin: 0 0 1.25rem; }
        a { color: inherit; }
    </style>
</head>
<body>
    <h1><?php echo tr_ai_wire_standalone_e($site); ?></h1>
    <p class="tag"><?php echo tr_ai_wire_standalone_e($tagline); ?></p>
    <ul>
        <?php if (!$articles) : ?>
            <li>No published articles yet. POST an approved article to <code>/ingest.php</code> or import JSON into <code>data/articles</code>.</li>
        <?php else : ?>
            <?php foreach ($articles as $article) : ?>
                <?php if (($article['status'] ?? '') === 'draft') { continue; } ?>
                <li>
                    <a href="article.php?slug=<?php echo rawurlencode($article['slug']); ?>"><?php echo tr_ai_wire_standalone_e($article['title']); ?></a>
                    <?php if ($article['lede'] !== '') : ?>
                        <p><?php echo tr_ai_wire_standalone_e($article['lede']); ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        <?php endif; ?>
    </ul>
</body>
</html>
