<?php
/**
 * JSON article store on disk. The standalone reader and static export share this.
 */
class TR_AI_Wire_Engine_Article_Store {
    private $dir;

    public function __construct($dir) {
        $this->dir = rtrim((string) $dir, '/');
    }

    public function directory() {
        return $this->dir;
    }

    public function put($article) {
        $article = TR_AI_Wire_Engine_Article::from_array($article);
        if ($article['title'] === '' || $article['slug'] === '') {
            return array('ok' => false, 'error' => 'Article needs a title and slug.');
        }
        if (!$this->ensure_dir()) {
            return array('ok' => false, 'error' => 'Could not create the article directory.');
        }
        $path = $this->path_for($article['slug']);
        $json = TR_AI_Wire_Engine_Article::to_json($article);
        $ok = (bool) file_put_contents($path, $json . "\n", LOCK_EX);
        if (!$ok) {
            return array('ok' => false, 'error' => 'Could not write the article file.');
        }
        return array('ok' => true, 'error' => '', 'path' => $path, 'slug' => $article['slug']);
    }

    public function get($slug) {
        $path = $this->path_for($slug);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        return TR_AI_Wire_Engine_Article::from_json($raw);
    }

    public function all() {
        if (!is_dir($this->dir)) {
            return array();
        }
        $files = glob($this->dir . '/*.json');
        if (!is_array($files)) {
            return array();
        }
        $articles = array();
        foreach ($files as $file) {
            $raw = file_get_contents($file);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $article = TR_AI_Wire_Engine_Article::from_json($raw);
            if ($article['title'] === '') {
                continue;
            }
            $articles[] = $article;
        }
        usort($articles, function ($a, $b) {
            return strcmp((string) ($b['published_at'] . $b['slug']), (string) ($a['published_at'] . $a['slug']));
        });
        return $articles;
    }

    private function path_for($slug) {
        return $this->dir . '/' . TR_AI_Wire_Engine_Article::slug($slug) . '.json';
    }

    private function ensure_dir() {
        if (is_dir($this->dir)) {
            return is_writable($this->dir);
        }
        return mkdir($this->dir, 0755, true);
    }
}
