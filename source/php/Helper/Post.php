<?php

namespace TypesenseIndex\Helper;

class Post
{
    public static function appendModuleContent($content, \WP_Post $post)
    {
        // Modules
        $modules = \Modularity\Editor::getPostModules($post->ID);
        $onlyModules = [];

        // Normalize modules array
        foreach ($modules as $item) {
            if (!isset($item['modules']) || count($item['modules']) === 0) {
                continue;
            }

            $onlyModules = array_merge($onlyModules, $item['modules']);
        }

        foreach ($onlyModules as $module) {
            // Skip widgets 
            if ($module->post_type === 'mod-wpwidget') {
                continue;
            }

            // Skip hidden modules
            if ($module->hidden) {
                continue;
            }

            //Get output for module
            $output = \Modularity\App::$display->outputModule(
                $module,
                array('edit_module' => false),
                array(),
                false
            );

            //Concat to end result
            if (!empty($output)) {
                $content .= "\r\n" . $output;
            }
        }

        return $content;
    }
}
