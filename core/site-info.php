<?php

  /**
   * This class adds a custom REST endpoint to the WordPress API that returns
   * information about the site, such as post types and plugins.
   * 
   * This is used by the CLI to generate various TypeScript hints and provide
   * feedback on the current state of the site.
   */
  class EDSiteInfo {
    static function init() {
      add_action('rest_api_init', function() {
        register_rest_route('ed/v1', '/dev/site-info', [
          'methods' => 'GET',
          'callback' => ['EDSiteInfo', 'handleSiteInfo']
        ]);
      });
    }

    static function handleSiteInfo($data) {
      $payload = $data->get_json_params();

      $info = [
        'postTypes' => [],
        'coreBlocks' => [],
        'plugins' => [],
        'blockTags' => []
      ];

      // Get post types
      $types = \WPGraphQL::get_allowed_post_types('objects');
      foreach ($types as $type) {
        $info['postTypes'][$type->name] = [
          'label' => $type->label,
          'name' => $type->name,
          'hasArchive' => $type->has_archive,
          'graphqlSingleName' => $type->graphql_single_name,
          'graphqlPluralName' => $type->graphql_plural_name,
          'excludeFromSearch' => $type->exclude_from_search
        ];
      }

      // Core blocks
      foreach (EDBlocks::$coreBlockTags as $block => $tags) {
        foreach ($tags as $tag) {
          if (!in_array($tag, $info['blockTags'])) {
            $info['blockTags'][] = $tag;
          }
        }
        if (!in_array($block, $info['coreBlocks'])) {
          $info['coreBlocks'][] = $block;
        }
      }
      foreach (EDBlocks::$blockGroupTargets as $block) {
        if (!in_array($block, $info['coreBlocks'])) {
          $info['coreBlocks'][] = $block;
        }
      }

      // Get plugins
      require_once ABSPATH . 'wp-admin/includes/plugin.php';
  		$plugins = apply_filters('all_plugins', get_plugins());
      foreach ($plugins as $plugin) {
        $info['plugins'][] = [
          'name' => $plugin['Name'],
          'slug' => $plugin['TextDomain'],
          'version' => $plugin['Version'],
          'url' => $plugin['PluginURI'],
        ];
      }

      return $info;
    }
  }