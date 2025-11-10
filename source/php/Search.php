<?php

namespace TypesenseIndex;

use \TypesenseIndex\Helper\Index as Instance;
use TypesenseIndex\Helper\Indexable;

class Search
{
    // Total hit count for search phrase
    private static $totalHitCount = 0;

    // Hit count for external access according to possible current filter
    private static $hitCount = 0;

    // Store facet counts for external access
    private static $facetCounts = [];

    // Store structured highlights for external access
    private static $highlights = [];

    public function __construct()
    {
        add_action('init', array($this, 'maybeInitTypesense'));
    }

    public function maybeInitTypesense()
    {
        // Skip admin and non-search pages
        if (is_admin() || !isset($_GET['s']) || trim($_GET['s']) === '') {
            return;
        }

        // Skip if Typesense isn’t available
        if (Instance::canConnect() === false) {
            return;
        }

        // Safe to add actions and filters now
        add_action('pre_get_posts', array($this, 'doTypesenseQuery'));
        add_filter('found_posts', array($this, 'adjustFoundPosts'), 50, 2);
        add_filter('posts_results', array($this, 'restoreSearchQueryParams'), 10, 2);
    }

    /**
     * Do typesense query
     *
     * @param $query
     * @return void
     */
    public function doTypesenseQuery(\WP_Query $query)
    {
        if (!is_admin() && $query->is_main_query() && $query->is_search && self::isSearchPage()) {

            $perPage = empty($query->get('posts_per_page'))
                ? get_option('posts_per_page')
                : $query->get('posts_per_page');
            $type = isset($_GET['type']) ? trim($_GET['type']) : false;

            //Check if backend search should run or not
            if (self::backendSearchActive()) {

                // Search params
                $searchParams = [
                    'q'                          => $query->query['s'],
                    'query_by'                   => 'custom_tags,post_title,post_excerpt,content',
                    'query_by_weights'           => '4,3,2,1',
                    'facet_by'                   => 'post_type',
                    'sort_by'                    => 'custom_boost:desc,_text_match:desc',
                    'include_fields'             => 'post_id',
                    'highlight_fields'           => 'post_title,post_excerpt,content',
                    'highlight_full_fields'      => 'post_title,post_excerpt',
                    'highlight_affix_num_tokens' => 20,
                    'per_page'                   => $perPage,
                    'page'                       => $query->get('paged'),
                    'enable_highlight_v1'        => false,
                ];

                // Post type
                if ($type) {
                    $searchParams['filter_by'] = 'post_type:=' . $type;
                }

                // Do search
                try {
                    $search = Instance::getCollection()->documents->search($searchParams);
                } catch (\Exception $e) {
                    return $query;
                }

                // If we've queried for a specific type we need to fetch facet count in an additional query
                if ($type) {
                    $searchParams['per_page'] = 0;
                    unset($searchParams['filter_by']);

                    $facetSearch = Instance::getCollection()->documents->search($searchParams);

                    // Parse and store facet counts
                    self::parseFacetCounts($facetSearch);

                    // Store the total hit count from Typesense for pagination
                    self::$totalHitCount = $facetSearch['found'];
                } else {
                    // Parse and store facet counts
                    self::parseFacetCounts($search);

                    // Store the total hit count from Typesense for pagination
                    self::$totalHitCount = $search['found'];
                }

                // Store the active hit count from Typesense for pagination
                self::$hitCount = $search['found'];

                // Parse and store highlights for external access
                self::$highlights = self::structureHighlights($search);

                // Set posts IDs to query for
                $query->query_vars['post__in'] = self::getPostIdArray($search['hits']);

                // Disable default WordPress search behaviour
                $query->set('_s', $query->get('s'));
                $query->set('s', false);

                // Order by typesense order
                $query->set('orderby', 'post__in');

                // Set post types to query for and fool WordPress to believe we're on first page
                // since we're only querying for X amount of posts at a time and more pages than
                // one won't be available
                $query->set('post_type', Indexable::postTypes());
                $query->set('_paged', $query->get('paged'));
                $query->set('paged', 1);
            }

            return $query;
        }
    }

    /**
     * Adjust the found_posts count to match Typesense hit count
     *
     * @param int $found_posts The number of found posts
     * @param WP_Query $query The WP_Query instance
     * @return int Modified count of found posts
     */
    public function adjustFoundPosts($found_posts, $query)
    {
        if (!is_admin() && $query->is_main_query() && $query->is_search && self::isSearchPage() && self::backendSearchActive()) {
            return self::$hitCount;
        }

        return $found_posts;
    }

    /**
    * Restore the original search parameters after Typesense search.
    *
    * @param array $posts The posts array.
    * @param \WP_Query $query The WP_Query instance.
    * @return array Modified posts array.
     */
    public function restoreSearchQueryParams($posts, \WP_Query $query)
    {
        if (!is_admin() && $query->is_main_query() && $query->is_search()) {
            $query->query_vars['s'] = $query->get('_s');
            $query->query_vars['paged'] = $query->get('_paged');
            $query->set('s', $query->get('_s'));
            $query->set('paged', $query->get('_paged'));
        }
        return $posts;
    }

    /**
     * Get id's if result array
     *
     * @param   array $response   The full response array
     * @return  array             Array containing results
     */
    private static function getPostIdArray($response)
    {
        $result = array();
        foreach ($response as $item) {
            $result[] = $item['document']['post_id'];
        }

        return $result;
    }

    /**
     * Check if search page is active page
     *
     * @return boolean
     */
    private static function isSearchPage()
    {

        if (is_multisite() && (defined('SUBDOMAIN_INSTALL') && SUBDOMAIN_INSTALL === false)) {
            if (trim(strtok($_SERVER["REQUEST_URI"], '?'), "/") == trim(get_blog_details()->path, "/") && is_search()) {
                return true;
            }
        }

        if (trim(strtok($_SERVER["REQUEST_URI"], '?'), "/") == "" && is_search()) {
            return true;
        }

        return false;
    }


    /**
     * Check if backend search should run
     *
     * @return boolean
     */
    private static function backendSearchActive()
    {   
        //Backend search active
        $backendSearchActive = apply_filters('TypesenseIndex/BackendSearchActive', true);

        //Query typesense for search result
        if ($backendSearchActive || is_post_type_archive()) {
            return true;
        }

        return false;
    }

    /**
     * Parse facet counts from Typesense search response
     *
     * @param array $searchResponse The response from Typesense search
     * @return void
     */
    private static function parseFacetCounts($searchResponse)
    {
        // Reset facet counts
        self::$facetCounts = [];

        // Check if facet_counts exists in the response
        if (!isset($searchResponse['facet_counts']) || empty($searchResponse['facet_counts'])) {
            return;
        }

        // Process each facet field
        foreach ($searchResponse['facet_counts'] as $facetField) {
            if (!isset($facetField['field_name']) || !isset($facetField['counts'])) {
                continue;
            }

            $fieldName = $facetField['field_name'];
            self::$facetCounts[$fieldName] = [];

            // Process each facet value for this field
            foreach ($facetField['counts'] as $facetValue) {
                if (isset($facetValue['value']) && isset($facetValue['count'])) {
                    self::$facetCounts[$fieldName][$facetValue['value']] = [
                        'count' => $facetValue['count'],
                        'highlighted' => isset($facetValue['highlighted']) ? $facetValue['highlighted'] : $facetValue['value']
                    ];
                }
            }
        }

        // Allow filtering of facet counts
        self::$facetCounts = apply_filters('TypesenseIndex/FacetCounts', self::$facetCounts, $searchResponse);
    }

    /**
     * Get hit count for query, regardless of filtered type
     *
     * @return int Hit count
     */
    public static function getTotalHitCount()
    {
        return self::$totalHitCount;
    }

    /**
     * Get hit count for query
     *
     * @return int Hit count
     */
    public static function getHitCount()
    {
        return self::$hitCount;
    }

    /**
     * Get all facet counts from the last search
     *
     * @return array Associative array of facet counts
     */
    public static function getFacetCounts()
    {
        return self::$facetCounts;
    }

    /**
     * Get facet counts for a specific field
     *
     * @param string $fieldName The facet field name
     * @return array|null Array of facet values and counts, or null if field doesn't exist
     */
    public static function getFacetCountsByField($fieldName)
    {
        return isset(self::$facetCounts[$fieldName]) ? self::$facetCounts[$fieldName] : null;
    }

    /**
     * Get structured highlights from the last search
     * 
     * @return array Structured highlights with post_id as key and fields as nested keys
     */
    public static function getHighlights()
    {
        return self::$highlights;
    }

    /**
     * Structure highlights by post_id
     *
     * @param array $searchResponse The response from Typesense search
     * @return array Structured highlights with post_id as key and fields as nested keys
     */
    public static function structureHighlights($searchResponse)
    {
        $structuredHighlights = [];

        // Check if hits exist in the response
        if (!isset($searchResponse['hits']) || empty($searchResponse['hits'])) {
            return $structuredHighlights;
        }

        // Process each hit
        foreach ($searchResponse['hits'] as $hit) {
            // Skip if document or post_id is missing
            if (!isset($hit['document']) || !isset($hit['document']['post_id'])) {
                continue;
            }

            $postId = $hit['document']['post_id'];
            $structuredHighlights[$postId] = [];

            // Process highlights if they exist
            if (isset($hit['highlight']) && is_array($hit['highlight'])) {
                foreach ($hit['highlight'] as $fieldName => $fieldData) {
                    if (isset($fieldData['value'])) {
                        $structuredHighlights[$postId][$fieldName] = $fieldData['value'];
                    } elseif (isset($fieldData['snippet']) && !isset($fieldData['value'])) {
                        $structuredHighlights[$postId][$fieldName] = $fieldData['snippet'];
                    }
                }
            }
        }

        return $structuredHighlights;
    }
}
