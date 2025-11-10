<?php

namespace TypesenseIndex\Helper;

class Id
{

    /**
     * Get the id
     *
     * @return void
     */
    public static function getId($postId)
    {
        if (is_multisite()) {
            return str_replace(".", "-", parse_url(network_site_url())['host']) . "-" . get_current_blog_id() . "-" . $postId;
        }
        return str_replace(".", "-", parse_url(home_url())['host']) . "-0-" . $postId;
    }
}
