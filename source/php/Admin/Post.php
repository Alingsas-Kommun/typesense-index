<?php

namespace TypesenseIndex\Admin;

use TypesenseIndex\Helper\Index;
use \TypesenseIndex\Helper\Indexable as Indexable;

class Post
{
  public function __construct()
  {
    //Add Typesense metabox
    add_action('add_meta_boxes', array($this, 'addTypesenseMetabox'));

    //Save actions
    add_action('save_post', array($this, 'saveExcludeFromSearch'), 10);
    add_action('save_post', array($this, 'saveCustomBoostValue'), 10);
    add_action('save_post', array($this, 'saveCustomTags'), 10);
  }

  /**
   * Add Typesense metabox
   *
   * @return void
   */
  public function addTypesenseMetabox()
  {
    // Get the current screen
    $screen = get_current_screen();
    if (!$screen) {
      return;
    }
    
    // Get the current post type
    $currentPostType = $screen->post_type;
    
    // Get indexable post types
    $indexablePostTypes = Indexable::postTypes();
    
    // Only add metabox if the current post type is indexable
    if (!empty($indexablePostTypes) && in_array($currentPostType, $indexablePostTypes)) {
      add_meta_box(
        'typesense-search-options',
        __('Typesense Search Options', 'typesense-index'),
        array($this, 'typesenseMetaboxContent'),
        $currentPostType,
        'side',
        'default'
      );
    }
  }

  /**
   * Render Typesense metabox content
   *
   * @param object $post The post object
   * @return void
   */
  public function typesenseMetaboxContent($post)
  {
    // Security nonce for verification
    wp_nonce_field('typesense_meta_box', 'typesense_meta_box_nonce');

    // Get Typesense record
    $document = Index::getDocument($post->ID);

    $checked = checked(true, get_post_meta($post->ID, 'exclude_from_search', true), false);

    $customBoost = get_post_meta($post->ID, 'custom_typesense_boost_value', true);
    $customBoost = empty($customBoost) && !empty($document->custom_boost) ? intval($document->custom_boost) : $customBoost;

    $searchTags = get_post_meta($post->ID, 'custom_typesense_tags', true);
    $searchTags = empty($searchTags) && !empty($document->custom_tags) ? esc_textarea($document->custom_tags) : $searchTags;
    
    echo '
      <style>
        .typesense-metabox {
          display: flex;
          flex-flow: column;
          gap: .75rem;
        }
        .typesense-metabox .field-row {
          display: flex;
          align-items: center;
        }
        .typesense-metabox .field-label {
          display: flex;
          align-items: center;
          margin-right: 8px;
        }
        .typesense-metabox .tags-row label {
          display: block;
          margin-bottom: 5px;
        }
        .typesense-metabox textarea {
          width: 100%;
        }
        .typesense-metabox input[type="number"] {
          width: 50px;
        }
        .typesense-metabox .field-icon {
          color: #828791;
          margin-right: 5px;
        }
      </style>

      <div class="typesense-metabox">
        <div class="field-row">
          <span class="field-label">
            <span class="dashicons dashicons-hidden field-icon"></span>
            <label for="exclude-from-search">' . __('Exclude from search', 'typesense-index') . '</label>
          </span>
          <input type="hidden" value="false" name="exclude-from-search">
          <input id="exclude-from-search" type="checkbox" name="exclude-from-search" value="true" ' . $checked . '>
        </div>
        
        <div class="field-row">
          <span class="field-label">
            <span class="dashicons dashicons-chart-line field-icon"></span>
            <label for="custom-typesense-boost-value">' . __('Custom search boost', 'typesense-index') . '</label>
          </span>
          <input id="custom-typesense-boost-value" type="number" name="custom-typesense-boost-value" value="' . $customBoost . '">
        </div>
        
        <div class="tags-row">
          <label for="custom-typesense-tags">
            <span class="dashicons dashicons-tag field-icon"></span>
            ' . __('Extra search tags', 'typesense-index') . '
          </label>
          <textarea id="custom-typesense-tags" name="custom-typesense-tags" rows="3">' . $searchTags . '</textarea>
        </div>
      </div>
    ';
  }

  /**
   * Exclude from search toggle option
   *
   * @param int $postId
   * @return bool
   */
  public function saveExcludeFromSearch($postId)
  {
    // Check if our nonce is set and verify it
    if (!isset($_POST['typesense_meta_box_nonce']) || !wp_verify_nonce($_POST['typesense_meta_box_nonce'], 'typesense_meta_box')) {
      return $postId;
    }

    // Check if we're not doing autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
      return $postId;
    }

    // Check the user's permissions
    if ('page' == $_POST['post_type']) {
      if (!current_user_can('edit_page', $postId)) {
        return $postId;
      }
    } else {
      if (!current_user_can('edit_post', $postId)) {
        return $postId;
      }
    }

    if (isset($_POST['exclude-from-search']) && $_POST['exclude-from-search'] === 'false') {
      delete_post_meta($postId, 'exclude_from_search');
      return true;
    } elseif (isset($_POST['exclude-from-search']) && $_POST['exclude-from-search'] === 'true') {
      update_post_meta($postId, 'exclude_from_search', true);
      return false;
    }
  }

  /**
   * Custom boost value for search, the higher the earlier in search
   *
   * @param int $postId
   * @return bool
   */
  public function saveCustomBoostValue($postId)
  {
    // Skip nonce and permission checks as they're done in saveExcludeFromSearch
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
      return $postId;
    }

    if (isset($_POST['custom-typesense-boost-value']) && ($_POST['custom-typesense-boost-value'] === '0' || empty($_POST['custom-typesense-boost-value']))) {
      delete_post_meta($postId, 'custom_typesense_boost_value');
      return true;
    } elseif (isset($_POST['custom-typesense-boost-value'])) {
      update_post_meta($postId, 'custom_typesense_boost_value', $_POST['custom-typesense-boost-value']);
      return false;
    }
  }

  /**
   * Save custom search tags
   *
   * @param int $postId
   * @return bool
   */
  public function saveCustomTags($postId)
  {
    // Skip nonce and permission checks as they're done in saveExcludeFromSearch
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
      return $postId;
    }

    if (isset($_POST['custom-typesense-tags'])) {
      if (empty($_POST['custom-typesense-tags'])) {
        delete_post_meta($postId, 'custom_typesense_tags');
      } else {
        update_post_meta($postId, 'custom_typesense_tags', sanitize_textarea_field($_POST['custom-typesense-tags']));
      }
    }
    
    return true;
  }
}
