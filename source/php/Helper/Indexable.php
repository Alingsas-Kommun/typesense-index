<?php

namespace TypesenseIndex\Helper;

class Indexable
{

    public static function postTypes()
    {
        
        // Only index certain post types
        $postTypes = ['page', 'nyheter', 'driftinformation', 'lediga-jobb', 'event'];

        return apply_filters('TypesenseIndex/IndexablePostTypes', $postTypes);
    }


    public static function postStatuses()
    {
        $postStatuses = ['publish'];
        return apply_filters('TypesenseIndex/IndexablePostStatuses', $postStatuses);
    }
}
