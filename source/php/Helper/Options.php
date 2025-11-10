<?php

namespace TypesenseIndex\Helper;

class Options
{
    /**
     * Checking if basic config is present
     *
     * @return bool
     */

    public static function isConfigured()
    {
        return !(bool) (empty(self::host()) || empty(self::apiKey()));
    }

    /**
     * Get the Typesense host
     *
     * @return string $host
     */
    public static function host()
    {
        if (defined('TYPESENSEINDEX_HOST') && !empty(constant('TYPESENSEINDEX_HOST'))) {
            return constant('TYPESENSEINDEX_HOST');
        }

        //Database
        $dbOption = self::getOptions()['host'];
        if (!empty($dbOption) && is_string($dbOption)) {
            return $dbOption;
        }

        return false;
    }

    /**
     * Get the admin API key
     *
     * @return string $apiKey
     */
    public static function apiKey()
    {
        if (defined('TYPESENSEINDEX_API_KEY') && !empty(constant('TYPESENSEINDEX_API_KEY'))) {
            return constant('TYPESENSEINDEX_API_KEY');
        }

        //Database
        $dbOption = self::getOptions()['api_key'];
        if (!empty($dbOption) && is_string($dbOption)) {
            return $dbOption;
        }

        return false;
    }

    /**
     * Get collection name
     *
     * @return string $collectionName
     */
    public static function collectionName()
    {
        //Constant
        if (defined('TYPESENSEINDEX_COLLECTION_NAME') && !empty(constant('TYPESENSEINDEX_COLLECTION_NAME'))) {
            $collection = constant('TYPESENSEINDEX_COLLECTION_NAME');
        } else {
            //Database
            $dbOption = self::getOptions()['collection_name'];
            if (!empty($dbOption) && is_string($dbOption)) {
                $collection = $dbOption;
            }
        }

        if (empty($collection)) {
            return false;
        }

        $env = wp_get_environment_type();
        $blog_id = get_current_blog_id();

        $prefixedCollection = "{$env}_{$blog_id}_{$collection}";
        $filteredCollection = apply_filters("TypesenseIndex/CollectionName", $prefixedCollection);

        return $filteredCollection;
    }

    /**
     * Get option and ensure that all keys exists.
     *
     * @return array
     */
    private static function getOptions()
    {
        return array_merge(
            array_flip(['api_key', 'collection_name']),
            array_filter((array) get_option('typesense_index'))
        );
    }
}
