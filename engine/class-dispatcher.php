<?php
interface TR_AI_Wire_Engine_Destination {
    public function publish($article);
}

/**
 * Fan-out to CMS destinations. One destination failing does not roll back others.
 */
class TR_AI_Wire_Engine_Dispatcher {
    public function publish($article, $destinations) {
        $article = TR_AI_Wire_Engine_Article::from_array($article);
        $results = array();
        foreach ((array) $destinations as $name => $destination) {
            $name = (string) $name;
            if (!$destination instanceof TR_AI_Wire_Engine_Destination) {
                $results[$name] = array('ok' => false, 'error' => 'Destination is not valid.');
                continue;
            }
            try {
                $result = $destination->publish($article);
                $results[$name] = is_array($result) ? $result : array('ok' => false, 'error' => 'Destination returned no result.');
            } catch (Exception $e) {
                $results[$name] = array('ok' => false, 'error' => $e->getMessage());
            }
        }
        return $results;
    }
}
