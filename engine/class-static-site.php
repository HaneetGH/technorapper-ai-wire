<?php
/**
 * Writes JSON plus a self-contained HTML file. GitHub Pages, S3, and the
 * standalone reader can all use the same directory.
 */
class TR_AI_Wire_Engine_Static_Site implements TR_AI_Wire_Engine_Destination {
    private $store;

    public function __construct($dir) {
        $this->store = new TR_AI_Wire_Engine_Article_Store($dir);
    }

    public function publish($article) {
        $article = TR_AI_Wire_Engine_Article::from_array($article);
        $saved = $this->store->put($article);
        if (empty($saved['ok'])) {
            return $saved;
        }
        $html_path = $this->store->directory() . '/' . $article['slug'] . '.html';
        $ok = (bool) file_put_contents($html_path, $this->render_article($article), LOCK_EX);
        if (!$ok) {
            return array('ok' => false, 'error' => 'Could not write the HTML file.');
        }
        $index_ok = (bool) file_put_contents(
            $this->store->directory() . '/index.html',
            $this->render_index($this->store->all()),
            LOCK_EX
        );
        if (!$index_ok) {
            return array('ok' => false, 'error' => 'Could not write the index file.');
        }
        return array(
            'ok' => true,
            'error' => '',
            'path' => $html_path,
            'slug' => $article['slug'],
        );
    }

    private function render_article($article) {
        $title = $this->e($article['title']);
        $lede = $this->e($article['lede']);
        $site = $this->e($article['site_name'] !== '' ? $article['site_name'] : 'AI Wire');
        $source = $article['source_url'] !== ''
            ? '<p class="source">Source: <a href="' . $this->e($article['source_url']) . '" rel="noopener noreferrer">' . $this->e($article['source_title'] !== '' ? $article['source_title'] : $article['source_url']) . '</a></p>'
            : '';
        $image = $article['featured_image_url'] !== ''
            ? '<p><img src="' . $this->e($article['featured_image_url']) . '" alt="' . $title . '"></p>'
            : '';
        $meta = $this->e($article['meta_description'] !== '' ? $article['meta_description'] : $article['lede']);
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $title . '</title>'
            . '<meta name="description" content="' . $meta . '">'
            . '<style>body{font-family:Georgia,serif;max-width:42rem;margin:2rem auto;padding:0 1rem;line-height:1.5}img{max-width:100%;height:auto}nav a{color:inherit}</style>'
            . '</head><body><nav><a href="index.html">' . $site . '</a></nav>'
            . '<article><h1>' . $title . '</h1><p class="lede"><strong>' . $lede . '</strong></p>'
            . $image . $article['body_html'] . $source
            . '</article></body></html>';
    }

    private function render_index($articles) {
        $items = '';
        foreach ((array) $articles as $article) {
            if (($article['status'] ?? '') === 'draft') {
                continue;
            }
            $items .= '<li><a href="' . $this->e($article['slug']) . '.html">' . $this->e($article['title']) . '</a>'
                . ($article['lede'] !== '' ? '<p>' . $this->e($article['lede']) . '</p>' : '')
                . '</li>';
        }
        if ($items === '') {
            $items = '<li>No published articles yet.</li>';
        }
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>AI Wire</title>'
            . '<style>body{font-family:Georgia,serif;max-width:42rem;margin:2rem auto;padding:0 1rem;line-height:1.5}li{margin:0 0 1rem}</style>'
            . '</head><body><h1>AI Wire</h1><ul>' . $items . '</ul></body></html>';
    }

    private function e($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
