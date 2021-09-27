<?php

  use WPGraphQL\Registry\TypeRegistry;
  use WPGraphQL\ACF\Config;

  class EDBlocks {

    static $blocks;

    static function init() {
      // Initial load of blocks
      self::loadBlocks();

      $config = new BlockQL();
      add_action('graphql_register_types', [$config, 'init'], 10, 1);
    }

    static function loadBlocks() {
      $files = glob(ED()->themePath."/blocks/**/*.tsx");

      foreach ($files as $file) {
        $meta = self::extractMetadata($file);
        $meta['supports']['jsx'] = true;
        $meta['render_callback'] = ['EDBlocks', 'renderBlockJSON'];
        acf_register_block_type($meta);
        self::$blocks['acf/'.$meta['name']] = $meta;
      }

    }

    // This function is used for block previews only
    // It should produce the same props that the frontend receives
    static function renderBlockJSON($args) {
      $block = self::$blocks[$args['name']];
      // $blockData = json_parse($_POST['block']);
      // BlockQLRoot::setContext($args);
      $fields = acf_get_block_fields( $args );
      foreach ( $fields as $field ) {
        // $args['data'][$field['name']] = $args['data'][$field['key']];
        // unset($args['data'][$field['key']]);
        $args['data'][ "_{$field['name']}"] = $field['key'];
      }

      // dump($args);
      // die();

      $result = BlockQL::runBlockQuery($block, [
        'id' => $args['id'],
        'name' => $args['name'],
        'data' => $args['data']
      ]);

      echo json_encode($result);
    }

    static function blockFieldName($name) {
      $parts = explode("/", str_replace("acf/", "", $name));
      $parts = array_map(function($i) {
        return Config::camel_case($i);
      }, $parts);
      return implode("_", $parts);
    }

    static function extractMetadata($file) {
      $contents = file_get_contents($file);
      
      // Ensure the header comment is set
      if (!preg_match("/^\/\*.+?\*\//s", $contents, $matches)) {
        throw new Error("The block file '".str_replace(ED()->themePath, "", $file)."' does not contain a valid comment header.");
      }

      // Pre-parse the comment into simple key/values
      $lines = explode("\n", $contents);
      $comment = [
        'name' => str_replace(ED()->themePath."/blocks/", "", str_replace(".tsx", "", $file))
      ];
      foreach ($lines as $line) {
        if (preg_match("/\s*\*?\s*([^:]+):\s*(.+)\s*/", $line, $matches)) {
          $comment[strtolower($matches[1])] = $matches[2];
        }
      }

      // Convert into an acf_register_post_type compatible array
      $id = str_replace(ED()->themePath."/blocks/", "", str_replace(".tsx", "", $file));
      return [
        'id' => $id,
        'name' => preg_replace("/[^a-z0-9-]+/i", "-", $comment['name']),
        'graphql_field_name' => self::blockFieldName($id),
        'title' => $comment['title'],
        'description' => $comment['description'],
        'keywords' => $comment['keywords'],
        'category' => $comment['category'],
        'icon' => $comment['icon'],
        'types' => preg_split("/[,\s]+/", $comment['types']),
        'mode' => $comment['mode'] ?? 'preview',
        'align' => $comment['align'],
        'align_text' => $comment['align text'],
        'align_content' => $comment['align content'],
        'supports' => [
          "align" => self::parseBoolOrString($comment['supports align'], false),
          "align_text" => self::parseBoolOrString($comment['supports align text'], false),
          "align_content" => self::parseBoolOrString($comment['supports align content'], false),
          "full_height" => self::parseBoolOrString($comment['supports full height'], false),
          "mode" => self::parseBoolOrString($comment['supports auto'], true),
          "multiple" => self::parseBoolOrString($comment['supports multiple'], false)
        ]
      ];
    }

    protected static function parseBoolOrString($str, $default) {
      if ($str === 'true') return true;
      if ($str === 'false') return false;
      if ($str) return $str;
      return $default;
    }

  }

  class BlockQL extends Config {

    public $rules = [];

    public function init(TypeRegistry $type_registry) {
      $this->type_registry = $type_registry;
      $this->rules = $this->getBlockProcessingRules();
      // dump($this->blockProcessingRules);
      // die();

      // Register the root block query
      register_graphql_object_type('CurrentBlock', [
        "description" => "The current Gutenberg block in context.",
        "fields" => []
      ]);
      register_graphql_field('RootQuery', 'block', [
        'type' => ['non_null' => 'CurrentBlock'],
        'description' => "The current Gutenberg block in context.",
        'resolve' => function($root, $args, $context, $info) {
          return new BlockQLRoot();
        }
      ]);
      register_graphql_scalar("ContentBlocks", [
        "description" => "Content blocks in ED.'s standard content blocks format.",
        "serialize" => function($value) {
          return $value;
        },
        "parseValue" => function($value) {
          return $value;
        },
        "parseLiteral" => function($value) {
          return $value;
        }
      ]);
      register_graphql_field('ContentNode', 'contentBlocks', [
        'type' => ['non_null' => 'ContentBlocks'],
        'description' => "The current Gutenberg block in context.",
        'resolve' => function($root, $args, $context, $info) {
          $result = $this->parseBlocks($root->contentRaw);
          return $result;
        }
      ]);
      add_filter('graphql_acf_get_root_id', function($id, $root) {
        if ($root instanceof BlockQLRoot) {
          return $root->id;
        }
        return $id;
      }, 10, 2);

      // Add field groups
      $field_groups = acf_get_field_groups();

      if (empty($field_groups) || !is_array( $field_groups)) {
        return;
      }

      // Loop over each field group
      foreach ($field_groups as $field_group) {

        // $field_group_name = isset( $field_group['graphql_field_name'] ) ? $field_group['graphql_field_name'] : $field_group['title'];
        // $field_group_name = Utils::format_field_name( $field_group_name );

        // $manually_set_graphql_types = isset( $field_group['map_graphql_types_from_location_rules'] ) ? (bool) $field_group['map_graphql_types_from_location_rules'] : false;

        $blockNames = [];
        foreach ($field_group['location'] as $ruleset) {
          foreach ($ruleset as $rule) {
            if ($rule['operator'] == '==' && $rule['param'] == 'block') {
              $blockNames[] = $rule['value'];
            }
          }
        }

        // Skip this field group, if it doesn't target a block
        if (count($blockNames) === 0) continue;

        // Create a new schema type for each block
        foreach ($blockNames as $blockName) {
          $block = EDBlocks::$blocks[$blockName];

          if (!$block) continue;

          /**
           * Prepare default info
           */
          $field_name = $block['graphql_field_name'];
          $field_group['type'] = 'group';
          $field_group['name'] = $field_name;
          $config              = [
            'name'            => $field_name,
            'acf_field'       => $field_group,
            'acf_field_group' => null,
            'resolve'         => function ( $root ) use ( $field_group ) {
              return isset( $root ) ? $root : null;
            }
          ];

          $config['acf_field']['graphql_types'] = ['CurrentBlock'];
          $config['acf_field']['name'] = $field_name;
          $config['acf_field']['graphql_field_name'] = $field_name;
          $config['acf_field']['show_in_graphql'] = 1;

          $qualifier = "Fields defined in the \"".$field_group['title']."\" field type";
          $config['description'] = $field_group['description'] ? $field_group['description'] . ' | ' . $qualifier : $qualifier;

          // dump("CurrentBlock", $field_name, $config);
          // die();
          $this->register_graphql_field("CurrentBlock", $field_name, $config);
          
        }
      }
  
    }


    public function getBlockProcessingRules() {
      $types = WP_Block_Type_Registry::get_instance()->get_all_registered();
      $rules = [];
      foreach ($types as $name => $type) {
        $rule = 'render';
        if ($name === 'core/column' || $name === 'core/columns') {
          $rule = 'layout';
        }
        $rules[$name] = $rule;
      }
      return $rules;
    }

    public function parseBlocks($content) {
      return $this->processBlocks(parse_blocks($content));
    }

    public function runBlockQuery($meta, $attributes) {
      $queryFile = ED()->themePath . "/blocks/" . $meta['id'] . ".graphql";
      if (!file_exists($queryFile)) {
        // No .graphql file exists, therefore no props
        return [];
      }
      $contents = file_get_contents($queryFile);
      $params = EDTemplates::getQueryParams();
      // dump($attributes);
      // die();
      BlockQLRoot::setContext($attributes);
      $result = graphql([
        'query' => $contents . FragmentLoader::getAll(),
        'variables' => $params
      ]);

      if ($result['errors']) {
        return ["errors" => $result['errors']];
      }
      
      // Extract result data into props
      $props = [
        'data' => []
      ];
      foreach ($result['data'] as $key => $value) {
        if ($key === 'block') {
          // Extract the block data
          foreach ($value as $blockKey => $result) {
            if ($blockKey !== $meta['graphql_field_name']) {
              throw new Error("Invalid block name in block query for \"".$meta['title']."\" - expected '".$meta['graphql_field_name']."' but found \"{$blockKey}\"");
            }
            $props = array_merge($props, $result);
          }
        } else {
          $props['data'][$key] = $value;
        }
      }
      return $props;
      // dump($result);
      // dump($contents, $params);
    }

    public function isCoreLayoutBlock($block) {
      if ($block['blockName'] === 'core/column') return true;
      return false;
    }

    public function isCoreInlineBlock($blockName) {
      return false;
    }

    public function processSingleBlock($block) {
      if (strpos($block['blockName'], "acf/") === 0) {
        // ACF blocks should have their 
        $meta = EDBlocks::$blocks[$block['blockName']];
        $block['props'] = $this->runBlockQuery($meta, $block['attrs']);
        $block['inline'] = $block['attrs']['inline'];
        $block['rule'] = 'react';
        unset($block['wpClassName']);
        return $block;
      } else {
        $rule = $this->rules[$block['blockName']];
        if ($rule === 'render') {
          $block['innerHTML'] = apply_filters('the_content', render_block($block));
          unset($block['innerBlocks']);
          unset($block['innerContent']);
        } else if ($rule === 'layout') {
          unset($block['innerHTML']);
          unset($block['innerContent']);
        }
        $block['rule'] = $rule;
        return $block;
      }
    }

    public function processBlocks($blocks) {
      // Filter out empty blocks
      $blocks = array_filter($blocks, function($block) {
        if (!$block['blockName'] && preg_match("/^\s*$/", $block['innerHTML'])) {
          // Empty block
          return false;
        } else {
          return true;
        }
      });

      // Process each block
      return array_values(array_map(function($block) {
        $block = $this->processSingleBlock($block);
        if (is_array($block['innerBlocks']) && count($block['innerBlocks'])) {
          $block['innerBlocks'] = $this->processBlocks($block['innerBlocks']);
        }
        return $block;
      }, $blocks));
    }

  }

  class BlockQLRoot {

    public static $context = null;
    public $id = null;

    public static function setContext($context) {
      self::$context = $context;
    }
    
    public function __construct() {
      $this->id = self::$context['id'];
      acf_setup_meta(
        self::$context['data'],
        self::$context['id'],
        false
      );
    }

  }