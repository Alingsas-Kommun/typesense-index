<?php

namespace TypesenseIndex\Admin;

use \TypesenseIndex\Helper\Index as Instance;

class Actions
{
    public function __construct()
    {
        // Handle admin posts
        add_action('admin_post_create_typesense_collection', array($this, 'handleCreateCollection'));
        add_action('admin_post_empty_typesense_collection', array($this, 'handleEmptyCollection'));
    }

    /**
     * Handle create_typesense_collection requests.
     * For now: validate nonce and redirect back to the referring page
     * with ?collection-created=true|false depending on result.
     *
     * @return void
     */
    public function handleCreateCollection()
    {
        check_admin_referer('create_typesense_collection_nonce', 'create_typesense_collection_nonce_field');

        $result = Instance::createCollection();

        $referer = wp_get_referer();
        $redirect = add_query_arg('collection-created', $result ? 'true' : 'false', $referer);

        wp_safe_redirect($redirect);

        exit;
    }

    /**
     * Handle empty_typesense_collection requests.
     * Validate nonce and redirect back to the referring page
     * with ?collection-emptied=true|false depending on result.
     *
     * @return void
     */
    public function handleEmptyCollection()
    {
        check_admin_referer('empty_typesense_collection_nonce', 'empty_typesense_collection_nonce_field');

        $result = Instance::emptyCollection();

        $referer = wp_get_referer();
        parse_str(parse_url($referer, PHP_URL_QUERY), $query_params);
        $collection_params = array_filter(
            $query_params,
            function ($key) {
                return strpos($key, 'collection-') === 0;
            },
            ARRAY_FILTER_USE_KEY
        );
        if (!empty($collection_params)) {
            $referer = remove_query_arg(array_keys($collection_params), $referer);
        }

        $redirect = add_query_arg('collection-emptied', $result ? 'true' : 'false', $referer);

        wp_safe_redirect($redirect);

        exit;
    }
}
