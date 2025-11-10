<?php

namespace TypesenseIndex\Helper;

use TypesenseIndex\Helper\Options as Options;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\HttplugClient;

class Index
{
    private static $_collection = null;
    private static $_client = null;

    /**
     * Get the Typesense collection
     *
     * @return \Typesense\Collection
     */
    public static function getCollection()
    {
        // Used cached instance
        if (!is_null(self::$_collection)) {
            return self::$_collection;
        }

        // Ensure client is created
        $client = self::getClient();

        // Get collection by accessing it as an array index
        $collectionName = Options::collectionName();

        // The collection will be created when schema is sent in sendTypesenseSettings()
        return self::$_collection = $client->collections[$collectionName];
    }

    /**
     * Get a single document from the Typesense collection where post_id matches the provided id.
     *
     * @param string|int $id
     * @return object|null Document array if found, null otherwise.
     */
    public static function getDocument(int $id)
    {
        try {
            $collection = self::getCollection();

            // Get the Typesense document id for the provided post id
            $documentId = \TypesenseIndex\Helper\Id::getId($id);

            // Retrieve the document by id
            $document = $collection->documents[$documentId]->retrieve();

            return (object) $document;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the number of records in the Typesense collection.
     *
     * @return int
     */
    public static function getRecordCount()
    {
        try {
            $collection = self::getCollection()->retrieve();
            return isset($collection['num_documents']) ? (int)$collection['num_documents'] : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get record counts grouped by post_type_name using faceting.
     *
     * @return array Associative array of post_type_name => count
     */
    public static function getRecordCountByPostType()
    {
        try {
            $collection = self::getCollection();
            $searchParameters = [
                'q' => '*',
                'query_by' => 'post_title,content',
                'facet_by' => 'post_type_name',
                'max_facet_values' => 1000 // Increase if you expect more post types
            ];
            $results = $collection->documents->search($searchParameters);

            $counts = [];
            if (isset($results['facet_counts'])) {
                foreach ($results['facet_counts'] as $facet) {
                    if ($facet['field_name'] === 'post_type_name' && isset($facet['counts'])) {
                        foreach ($facet['counts'] as $count) {
                            $counts[$count['value']] = $count['count'];
                        }
                    }
                }
            }
            return $counts;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get or create the Typesense client
     *
     * @return \Typesense\Client
     */
    public static function getClient()
    {
        if (!is_null(self::$_client)) {
            return self::$_client;
        }

        // Create compliant HTTP client
        $symfonyNative = HttpClient::create([
            'timeout' => 2, // total timeout in seconds
        ]);
        $client = new HttplugClient($symfonyNative);

        // Create Typesense client configuration
        $config = [
            'api_key' => Options::apiKey(),
            'nodes' => [
                [
                    'host' => parse_url(Options::host(), PHP_URL_HOST),
                    'port' => parse_url(Options::host(), PHP_URL_PORT) ?: '8108',
                    'protocol' => parse_url(Options::host(), PHP_URL_SCHEME) ?: 'http',
                    'path' => parse_url(Options::host(), PHP_URL_PATH) ?: '',
                ]
            ],
            'num_retries' => 2,
            'client' => $client,
        ];

        // Add custom headers
        $config['headers'] = [
            'X-Client-Cli' => defined('WP_CLI_VERSION') ? constant('WP_CLI_VERSION') : 'false',
            'X-Client-Cron' => defined('DOING_CRON') ? 'true' : 'false',
            'X-Client-User' => get_current_user_id(),
        ];

        $config = apply_filters('TypesenseIndex/Config', $config) ?? $config;

        // Initialize Typesense client
        return self::$_client = new \Typesense\Client($config);
    }

    /**
     * Create collection with proper schema
     *
     * @return void
     */
    public static function createCollection()
    {
        if (!Options::isConfigured() && !self::canConnect()) {
            return false;
        }

        // Create collection schema for Typesense
        $schema = [
            'name' => Options::collectionName(),
            'fields' => [
                ['name' => 'blog_id', 'type' => 'int32'],
                ['name' => 'post_id', 'type' => 'string'],
                ['name' => 'post_date', 'type' => 'int64'],
                ['name' => 'post_modified', 'type' => 'int64'],
                ['name' => 'post_title', 'type' => 'string'],
                ['name' => 'post_excerpt', 'type' => 'string'],
                ['name' => 'content', 'type' => 'string'],
                ['name' => 'permalink', 'type' => 'string'],
                ['name' => 'post_type', 'type' => 'string', 'facet' => true],
                ['name' => 'post_type_name', 'type' => 'string', 'facet' => true],
                ['name' => 'custom_boost', 'type' => 'int32', 'optional' => true],
                ['name' => 'custom_tags', 'type' => 'string', 'optional' => true],
            ],
        ];

        $client = self::getClient();

        try {
            $client->collections->create($schema);
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Delete all documents in the Typesense collection.
     *
     * @return bool True on success, false on failure.
     */
    public static function emptyCollection()
    {
        try {
            $collection = self::getCollection();
            $collection->documents->delete(['filter_by' => 'blog_id:>0']);
            return true;
        } catch (\Exception $e) {
            var_dump($e->getMessage());exit;
            return false;
        }
    }

    /**
     * Check if the configured Typesense collection exists.
     *
     * @return bool|string True if exists, 'unauthorized', 'notfound', or false on error.
     */
    public static function collectionExists()
    {
        try {
            self::getCollection()->retrieve();
        } catch (\Typesense\Exceptions\RequestUnauthorized $e) {
            return 'unauthorized';
        } catch (\Typesense\Exceptions\ObjectNotFound $e) {
            return 'notfound';
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Run health check using the Typesense client.
     *
     * @return bool
     */
    public static function canConnect()
    {
        try {
            self::getClient()->health->retrieve();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
