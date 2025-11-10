<?php

namespace TypesenseIndex\Admin;

use \TypesenseIndex\Helper\Index as Instance;
use \TypesenseIndex\Helper\Options as Options;

class Settings
{

    private $typesense_index_options;

    public function __construct()
    {
        //Local settings
        add_action('admin_menu', array($this, 'addPluginPage'));
        add_action('admin_init', array($this, 'pluginPageInit'));
    }

    /**
     * Register the plugins page
     *
     * @return void
     */
    public function addPluginPage()
    {
        add_options_page(
            __("Typesense search", 'typesense-index'),
            __("Typesense search", 'typesense-index'),
            'manage_options',
            'typesense-index',
            array($this, 'typesenseIndexCreateAdminPage')
        );
    }

    /**
     * View
     *
     * @return void
     */
    public function typesenseIndexCreateAdminPage()
    {
        $isConfigured = Options::isConfigured();
        $canConnect = Options::isConfigured() && Instance::canConnect();
        $collectionExists = Instance::collectionExists();
?>
        <div class="wrap">
            
            <?php if (isset($_GET['collection-created'])) : ?>
                <?php if ($_GET['collection-created'] === 'true') : ?>
                    <div class="notice notice-success is-dismissible">
                        <p><?php _e("Collection created successfully.", 'typesense-index'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="notice notice-error is-dismissible">
                        <p><?php _e("Failed to create collection.", 'typesense-index'); ?></p>
                    </div>  
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!$isConfigured) : ?>
                <div class="notice notice-error">
                    <p><?php _e("You need to configure your Typesense instance settings.", 'typesense-index'); ?></p>
                </div>
            <?php endif; ?>
            <?php if ($isConfigured && !$canConnect) : ?>
                <div class="notice notice-error">
                    <p><?php _e("Could not connect to Typesense instance. Please check your configuration.", 'typesense-index'); ?></p>
                </div>
            <?php endif; ?>
            <?php if ($collectionExists === 'notfound') : ?>
                <div class="notice notice-error">
                    <p><?php _e("Collection does not exist. Please check your configuration or create it using the function below.", 'typesense-index'); ?></p>
                </div>
            <?php endif; ?>
            <?php if ($collectionExists === 'unauthorized') : ?>
                <div class="notice notice-error">
                    <p><?php _e("Unauthorized access to Typesense. Please check your API key and permissions.", 'typesense-index'); ?></p>
                </div>
            <?php endif; ?>

            <h2><?php _e("Typesense settings", 'typesense-index'); ?></h2>
            <form method="post" action="options.php?sendTypesenseSettings=true">
                <?php
                settings_fields('typesense_index_option_group');
                do_settings_sections('typesense-index-admin');
                submit_button();
                ?>
            </form>

            <?php if ($isConfigured && $canConnect) : ?>
                <?php if ($collectionExists !== 'unauthorized') : ?>
                    <div style="margin-bottom:40px;">
                        <h2><?php _e("Collection", 'typesense-index'); ?></h2>
                        <?php if ($collectionExists === true) : ?>
                            <p><?php printf(__("Your collection exists and has the name %s.", 'typesense-index'), '<b>' .Options::collectionName() . '</b>'); ?></p>
                        <?php elseif ($collectionExists === 'notfound') : ?>
                            <p><?php _e("Your Typesense collection does not exist. You can create it using the button below.", 'typesense-index'); ?></p>
                            
                            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                                <input type="hidden" name="action" value="create_typesense_collection">
                                <?php wp_nonce_field('create_typesense_collection_nonce', 'create_typesense_collection_nonce_field'); ?>
                                <button type="submit" class="button button-primary">
                                    <?php _e("Create collection", 'typesense-index'); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($collectionExists === true) :
                $recordCount = Instance::getRecordCount(); ?>
                <h2><?php _e("Indexed records", 'typesense-index'); ?></h2>
                <p><?php printf(__("Your collection currently has %s records indexed.", 'typesense-index'), '<b>' . $recordCount . '</b>'); ?></p>
                <?php if ($recordCount == 0) : ?>
                    <p><?php _e("Since your index is empty, you might want to add all necessary documents using the indexing functions.<br>
                                 You can do this using WP-CLI command <b>typesense-index build</b>.", 'typesense-index'); ?></p>
                <?php endif; ?>

                <?php $recordCountsByPostType = Instance::getRecordCountByPostType(); ?>
                <?php if (!empty($recordCountsByPostType)) : ?>
                    <table class="widefat fixed" cellspacing="0" style="max-width:400px;">
                        <thead>
                            <tr>
                                <th id="columnname" class="manage-column column-columnname" scope="col">Post type</th>
                                <th id="columnname" class="manage-column column-columnname" scope="col">Records</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recordCountsByPostType as $postType => $count) : ?>
                                <tr class="alternate">
                                    <td class="column-columnname"><?php echo esc_html($postType); ?></td>
                                    <td class="column-columnname"><?php echo esc_html($count); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <br>

                    <p><b><?php _e("You can empty the collection using the button below.", 'typesense-index'); ?></b></p>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="empty_typesense_collection">
                        <?php wp_nonce_field('empty_typesense_collection_nonce', 'empty_typesense_collection_nonce_field'); ?>
                        <button type="submit" class="button button-primary">
                            <?php _e("Empty collection", 'typesense-index'); ?>
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

        </div>
<?php
    }

    /**
     * Register settings
     *
     * @return void
     */
    public function pluginPageInit()
    {
        register_setting(
            'typesense_index_option_group',
            'typesense_index',
            array($this, 'typesenseIndexSanitize')
        );

        add_settings_section(
            'typesense_index_setting_section',
            'Settings',
            array($this, 'typesenseSettingsSectionCallback'),
            'typesense-index-admin'
        );

        add_settings_field(
            'host',
            'Typesense host
            <small style="display:block; font-weight: normal;">
              May be overridden by TYPESENSEINDEX_HOST constant
            <small>',
            array($this, 'typesenseHostCallback'),
            'typesense-index-admin',
            'typesense_index_setting_section'
        );

        add_settings_field(
            'api_key',
            'Admin API key
            <small style="display:block; font-weight: normal;">
              May be overridden by TYPESENSEINDEX_API_KEY constant
            </small>',
            array($this, 'typesenseApiKeyCallback'),
            'typesense-index-admin',
            'typesense_index_setting_section'
        );

        add_settings_field(
            'collection_name',
            'Collection name
            <small style="display:block; font-weight: normal;">
              May be overridden by TYPESENSEINDEX_COLLECTION_NAME constant
            </small>',
            array($this, 'typesenseCollectionNameCallback'),
            'typesense-index-admin',
            'typesense_index_setting_section'
        );
    }

    /**
     * Load option
     *
     * @return void
     */
    public function typesenseSettingsSectionCallback()
    {
        $this->typesense_index_options = get_option('typesense_index');
    }

    /**
     * Sanitize
     *
     * @param  array $input             Unsanitized values
     * @return array $sanitary_values   Sanitized values
     */
    public function typesenseIndexSanitize($input)
    {
        $sanitary_values = array();

        if (isset($input['host'])) {
            $sanitary_values['host'] = sanitize_text_field($input['host']);
        }

        if (isset($input['api_key'])) {
            $sanitary_values['api_key'] = sanitize_text_field($input['api_key']);
        }

        if (isset($input['public_api_key'])) {
            $sanitary_values['public_api_key'] = sanitize_text_field($input['public_api_key']);
        }

        if (isset($input['collection_name'])) {
            $sanitary_values['collection_name'] = sanitize_text_field($input['collection_name']);
        }

        return $sanitary_values;
    }

    /**
     * Print field, with data.
     *
     * @return void
     */
    public function typesenseHostCallback()
    {
        $constant = defined('TYPESENSEINDEX_HOST') && !empty(constant('TYPESENSEINDEX_HOST'))
            ? constant('TYPESENSEINDEX_HOST')
            : false;
        $dbValue = isset($this->typesense_index_options['host']) 
            ? esc_attr($this->typesense_index_options['host']) 
            : false;

        $value = $constant ? $constant : $dbValue;
        $readonly = $constant ? 'readonly' : '';

        printf('<input class="regular-text" type="text" name="typesense_index[host]" id="host" placeholder="http://localhost:8108" value="%s" %s>', $value, $readonly);
    }

    /**
     * Print field, with data.
     *
     * @return void
     */
    public function typesenseApiKeyCallback()
    {
        $constant = defined('TYPESENSEINDEX_API_KEY') && !empty(constant('TYPESENSEINDEX_API_KEY'))
            ? constant('TYPESENSEINDEX_API_KEY')
            : false;
        $dbValue = isset($this->typesense_index_options['api_key']) 
            ? esc_attr($this->typesense_index_options['api_key']) 
            : false;

        $value = $constant ? $constant : $dbValue;
        $readonly = $constant ? 'readonly' : '';

        printf('<input class="regular-text" type="password" name="typesense_index[api_key]" id="api_key" value="%s" %s>', $value, $readonly);
    }

    /**
     * Print field, with data.
     *
     * @return void
     */
    public function typesenseCollectionNameCallback()
    {   
        $constant = defined('TYPESENSEINDEX_COLLECTION_NAME') && !empty(constant('TYPESENSEINDEX_COLLECTION_NAME'))
            ? constant('TYPESENSEINDEX_COLLECTION_NAME')
            : false;
        $dbValue = isset($this->typesense_index_options['collection_name']) 
            ? esc_attr($this->typesense_index_options['collection_name']) 
            : false;

        $value = $constant ? $constant : $dbValue;
        $readonly = $constant ? 'readonly' : '';

        printf('<input class="regular-text" type="text" name="typesense_index[collection_name]" id="collection_name" value="%s" %s>', $value, $readonly);
    }
}
