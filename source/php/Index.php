<?php

namespace TypesenseIndex;

use \TypesenseIndex\Helper\Index as Instance;
use \TypesenseIndex\Helper\Id as Id;
use \TypesenseIndex\Helper\Indexable as Indexable;
use \TypesenseIndex\Helper\Log as Log;
use \TypesenseIndex\Helper\Post;

class Index
{
    //Priority on hooks
    private static $_priority = 999;

    public function __construct()
    {
        //Add & update
        add_action('save_post', array($this, 'index'), self::$_priority);

        //Remove
        add_action('delete_post', array($this, 'delete'), self::$_priority);
        add_action('wp_trash_post', array($this, 'delete'), self::$_priority);

        //Bulk action
        add_action('TypesenseIndex/IndexPostId', array($this, 'index'), self::$_priority, 1);
    }

    /**
     * Delete post from index
     *
     * @param int $postId
     * @return void
     */
    public function delete($postId)
    {
        try {
            return Instance::getCollection()->documents[Id::getId($postId)]->delete();
        } catch (\Exception $e) {
            Log::error('Could not delete record: ' . Id::getId($postId) . '. Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Submit post to index
     *
     * @param int|WP_Post $post
     * @return void
     */
    public function index($post)
    {
        list($post, $postId) = self::getPostAndPostId($post);

        //Check if post should be removed
        $shouldPostBeRemoved = [isset($_POST['exclude-from-search']) && $_POST['exclude-from-search'] == "true", get_post_status($post) !== 'publish'];

        if (in_array(true, $shouldPostBeRemoved)) {
            self::delete($postId);
        }

        //Check if is indexable post
        if (!self::shouldIndex($post)) {
            return;
        }

        //Check if the new post differs from indexed record
        if (!self::hasChanged($postId)) {
            return;
        }

        // Keep old locale
        $old_locale = get_locale();

        // Switch to english locale to avoid issues with labels
        switch_to_locale( 'en_US' );

        //Get post data
        $post = self::getPost($post);

        //Sanity check (convert data)
        $post = _wp_json_sanity_check($post, 10);

        try {
            //Catch error here. 
            json_encode($post, JSON_THROW_ON_ERROR);

            try {
                Instance::getCollection()->documents->upsert($post);
            } catch (\Exception $e) {
                Log::error('Could not save post: ' . $post['id'] . '. Error: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            error_log("Typesense Index: Could not save post. " . $post['ID']);
        }
        
        switch_to_locale( $old_locale );
    }

    /**
     * Determine if the post should be indexed.
     *
     * @param int|WP_Post $post
     * @return boolean
     */
    private static function shouldIndex($post)
    {
        list($post, $postId) = self::getPostAndPostId($post);

        //Do not index on autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE == true) {
            return false;
        }

        //Do no index revisions
        if (wp_is_post_revision($post)) {
            return false;
        }

        //Check if published post (or any other allowed value)
        if (!in_array(get_post_status($post), Indexable::postStatuses())) {
            return false;
        }

        //Get post type details
        if (!in_array(get_post_type($post), Indexable::postTypes())) {
            return false;
        }

        //Do not index checkbox
        if (get_post_meta($postId, 'exclude_from_search', true)) {
            return false;
        }

        //Anything else
        if (!apply_filters('TypesenseIndex/ShouldIndex', true, $postId)) {
            return false;
        }

        return true;
    }

    /**
     * Check if record in typesense matches locally stored record.
     *
     * @param int|WP_Post $post
     * @return boolean
     */
    private static function hasChanged($post)
    {
        list($post, $postId) = self::getPostAndPostId($post);

        //Make search
        try {
            $response = Instance::getCollection()->documents[Id::getId($postId)]->retrieve();
            $response = (object) ['results' => [$response]];
        } catch (\Exception $e) {
            Log::error('Could not retrieve document for comparison: ' . Id::getId($postId) . '. Error: ' . $e->getMessage());
            return true; // If we can't retrieve the document, assume it has changed
        }

        //Get result
        if (isset($response->results) && is_array($response->results) && !empty($response->results)) {
            $indexRecord = is_array($response->results) ? array_pop($response->results) : [];
        } else {
            $indexRecord = [];
        }

        //Get stored record
        $storedRecord = self::getPost($post);

        //Check for null responses, update needed
        if (is_null($indexRecord) || is_null($storedRecord)) {
            return true;
        }

        //Filter out everything that dosen't matter
        $indexRecord    = self::streamlineRecord($indexRecord);
        $storedRecord   = self::streamlineRecord(self::getPost($postId));

        //Diff posts
        if (serialize($indexRecord) != serialize($storedRecord)) {
            return true; //Post has updates
        }
        return false; //Post has no updates
    }

    /**
     * Streamline record, basically tells what to use
     * to compare posts for update checking.
     *
     * @param array $record
     * @return array
     */
    private static function streamlineRecord($record)
    {

        //List of fields to compare
        $comparables = apply_filters('TypesenseIndex/Compare', [
            'id',
            'post_title',
            'post_excerpt',
            'content',
            'permalink',
            'custom_boost',
            'custom_tags',
        ]);

        //Prepare comparables
        $record = (array) array_intersect_key($record, array_flip($comparables));

        //Sort (resolves different orders)
        array_multisort($record);

        //Send back
        return $record;
    }

    /**
     * Get post by ID
     *
     * @param int|WP_Post $post
     * @return array
     */
    private static function getPost($post)
    {
        list($post, $postId) = self::getPostAndPostId($post);

        if ($post = get_post($post)) {
            $customBoost = get_post_meta($post->ID, 'custom_typesense_boost_value', true);
            $searchTags = get_post_meta($post->ID, 'custom_typesense_tags', true);

            $customBoost = empty($customBoost) ? null : intval($customBoost);
            $searchTags = empty($searchTags) ? null : $searchTags;

            //Post details
            $result =  array(
                'id' => Id::getId($postId),
                'post_id' => (string) $post->ID,
                'post_title' => apply_filters('the_title', $post->post_title),
                'post_excerpt' => self::getTheExcerpt($post),
                'content' => self::extractTextFromHtml($post->post_content),
                'permalink' => get_permalink($post->ID),
                'post_date' => strtotime($post->post_date),
                'post_modified' => strtotime($post->post_modified),
                'post_type' => get_post_type($postId),
                'post_type_name' => get_post_type_object(get_post_type($postId))->label,
                'custom_boost' => apply_filters('TypesenseIndex/Index/CustomBoost', $customBoost, $post) ?? 0,
                'custom_tags' => apply_filters('TypesenseIndex/Index/CustomTags', $searchTags, $post) ?? '',
                'blog_id' => get_current_blog_id(),
            );

            //Remove multiple spaces
            foreach ($result as $key => $field) {
                if (in_array($key, array('post_title', 'post_excerpt', 'content'))) {
                    $result[$key] = preg_replace('/\s+/', ' ', $field);
                }
            }

            // Add modules to content
            $result['content'] = self::extractTextFromHtml(Post::appendModuleContent($result['content'], $post));

            return apply_filters('TypesenseIndex/Record', $result, $postId);
        }

        return null;
    }

    public static function extractTextFromHtml($html)
    {
        // Suppress HTML parsing errors
        libxml_use_internal_errors(true);

        // Load HTML into DOMDocument
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

        // Remove <script> and <style> elements
        $tagsToRemove = ['script', 'style', 'noscript'];
        foreach ($tagsToRemove as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            // Must iterate backwards because the NodeList is live
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                $node->parentNode->removeChild($node);
            }
        }

        // Use XPath to get all text nodes
        $xpath = new \DOMXPath($dom);
        $textNodes = $xpath->query('//text()[normalize-space()]');

        $lines = [];
        foreach ($textNodes as $textNode) {
            $text = trim($textNode->nodeValue);
            if ($text !== '') {
                $lines[] = $text;
            }
        }

        // Combine into one text block with line breaks
        $result = implode("\n", $lines);

        // Clean up excessive whitespace
        $result = preg_replace("/\n{2,}/", "\n", $result);

        return trim($result);
    }

    public static function getTheExcerpt($post, int $numberOfWords = 55)
    {
        $excerpt = get_the_excerpt($post);

        if (empty($excerpt) || strlen($excerpt) > 10) {
            $excerpt = !empty($post->post_content)
                ? $post->post_content
                : $excerpt;
        }

        $blocks = parse_blocks($excerpt);
        if (is_countable($blocks) && !empty($blocks)) {
            $excerpt = "";
            foreach ($blocks as $block) {
                $excerpt .= render_block($block) . " " . PHP_EOL;
            }
        }

        $excerpt = preg_replace('/\[(.*?)\]/', '', $excerpt);

        return wp_trim_words(
            strip_tags($excerpt),
            $numberOfWords,
            "..."
        );
    }

    /**
     * Get post and post id
     *
     * @param int|WP_Post $post
     * @return array [WP_Post, int] or [int, int] depending on input.
     */
    private static function getPostAndPostId($post)
    {
        $postId = $post;

        if (is_a($post, 'WP_Post')) {
            $postId = $post->ID;
        }

        return [$post, $postId];
    }
}
