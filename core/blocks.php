<?php

  use WPGraphQL\Registry\TypeRegistry;
  use WPGraphQL\ACF\Config;

  class EDBlocks {

    static $blocks;
    static $coreBlockTags = [];
    static $blockGroupTargets = [];

    static function init() {
      // Initial load of blocks
      self::loadBlocks();

      $config = new BlockQL();
      add_action('graphql_register_types', [$config, 'init'], 10, 1);

      add_action('admin_init', function() {
        wp_add_inline_script('wp-block-editor', "window.ED_BLOCK_TAGS = " . json_encode(self::$coreBlockTags), 'after');
      });

      // add_action('block_editor_settings_all', function($settings) {
      //   $settings['blockTags'] = self::$coreBlockTags;
      //   // $blocks = WP_Block_Type_Registry::get_instance()->get_all_registered();
      //   // foreach ($blocks as $block) {
      //   //   if (self::$coreBlockTags[$block->name]) {
      //   //     $block->tags = self::$coreBlockTags[$block->name];
      //   //   }
      //   // }
      //   return $settings;
      // });

      add_action('admin_init', function() {
        add_filter('the_post', function($post) {
          $post->post_content = str_replace('"mode":"edit"', '"mode":"preview"', $post->post_content);
          return $post;
        });
      });

      add_filter('allowed_block_types_all', function($types, $ctx) {
        // // Get the current template, if one exists.
        $post = $ctx->post;
        $templateName = @get_page_template_slug($_GET['post']);
        if (!$templateName) $templateName = "default";

        if ($templateName) {
          $templateName = str_replace("views/", "", str_replace(".tsx", "", $templateName));
        }
    
        // // Get all block types declared to ACF
        $blockTypes = acf_get_block_types();
        $allowedBlocks = array_keys(self::$coreBlockTags);
    
        foreach ($blockTypes as $name => $def) {
          if (is_callable(@$def['test'])) {
            if (!$def['test']($post, $templateName)) {
              continue;
            }
          } else {
            if ($post->post_type === 'page') {
              if (@is_array($def['templates']) && @!in_array($templateName, $def['templates'])) {
                // Don't allow this block, since the current template is not on the whitelist
                continue;
              }
            }
          }
          $allowedBlocks[] = $name;
        }
    
        return $allowedBlocks;
      }, 2, 3);
    }

    static function loadBlocks() {
      if (!function_exists("acf_register_block_type")) {
        return;
      }
      $files = glob(ED()->themePath."/blocks/**/*.tsx");

      foreach ($files as $file) {
        $meta = self::extractMetadata($file);
        $meta['supports']['jsx'] = true;
        $meta['render_callback'] = ['EDBlocks', 'renderBlockJSON'];
        acf_register_block_type($meta);
        self::$blocks['acf/'.$meta['name']] = $meta;
      }

    }

    static function tagCoreBlocks($tag, $blocks) {
      foreach ($blocks as $block) {
        self::$coreBlockTags[$block][] = $tag;
      }
    }

    static function groupCoreBlocks($targetName, $blocks) {
      foreach ($blocks as $block) {
        self::$blockGroupTargets[$block] = $targetName;
      }
    }

    static function templateLock($lock) {
      if ($lock['type']) {
        add_action('admin_init', function() use($lock) {
          $postType = get_post_type_object($lock['type']);
          $postType->template = $lock['content'];
          $postType->template_lock = 'all';
        });
      } else if ($lock['template']) {
        add_filter('block_editor_settings', function($settings, $post) use($lock) {
          if ($post->post_type === $lock['type']) {
            $settings['template'] = $lock['content'];
            $settings['template_lock'] = 'all';
          } else if ($post->post_type === "page" && $lock['template']) {
            // Disabled, because not really possible atm...
  
            $templateName = @get_page_template_slug($post->ID);
            if (!$templateName) $templateName = "default";
            if ($templateName) {
              $templateName = str_replace("views/", "", str_replace(".tsx", "", $templateName));
            }
  
            if ($templateName === $lock['template']) {
              $settings['template'] = $lock['content'];
              $settings['template_lock'] = 'all';
            }
          }
          return $settings;
        }, 10, 2);
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
          $key = strtolower($matches[1]);
          $value = $matches[2];
          if (!@$comment[$key]) {
            $comment[$key] = $value;
          }
        }
      }

      // Convert into an acf_register_post_type compatible array
      $id = str_replace(ED()->themePath."/blocks/", "", str_replace(".tsx", "", $file));

      $templates = @$comment['templates'] ? preg_split("/[,\s]+/", $comment['templates']) : [];
      if (count($templates) === 0) {
        $templates = null;
        // $templates = ['default'];
      }

      return [
        'id' => $id,
        'name' => preg_replace("/[^a-z0-9-]+/i", "-", @$comment['name']),
        'graphql_field_name' => self::blockFieldName($id),
        'title' => @$comment['title'],
        'description' => @$comment['description'],
        'keywords' => @$comment['keywords'],
        'category' => @$comment['category'],
        'icon' => @$comment['icon'],
        'post_types' => preg_split("/[,\s]+/", @$comment['types']),
        'mode' => @$comment['mode'] ?? 'preview',
        'align' => @$comment['align'],
        'align_text' => @$comment['align text'],
        'align_content' => @$comment['align content'],
        'templates' => $templates,
        'tags' => preg_split("/[,\s]+/", @$comment['tags']),
        'childTags' => preg_split("/[,\s]+/", @$comment['child tags']),
        "canCache" => self::parseBoolOrString(@$comment['cache'], false),
        'supports' => [
          "align" => self::parseBoolOrString(@$comment['supports align'], false),
          "align_text" => self::parseBoolOrString(@$comment['supports align text'], false),
          "align_content" => self::parseBoolOrString(@$comment['supports align content'], false),
          "full_height" => self::parseBoolOrString(@$comment['supports full height'], false),
          "mode" => self::parseBoolOrString(@$comment['supports auto'], true),
          "multiple" => self::parseBoolOrString(@$comment['supports multiple'], false),
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

      // Register the root block query
      register_graphql_object_type('CurrentBlock', [
        "description" => "The current Gutenberg block in context.",
        "fields" => [
          'nothing' => [
            'type' => 'Int'
          ]
        ]
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
          $post = get_post($root->ID);
          $result = $this->parseBlocks($post->post_content);
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
          $block = @EDBlocks::$blocks[$blockName];

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

    public static function runBlockQuery($meta, $attributes) {
      $cacheKey = null;
      $queryFile = ED()->themePath . "/blocks/" . $meta['id'] . ".graphql";
      $contents = QueryLoader::loadQueryFile($queryFile);
      if (!$contents) return;

      // If caching is support by this block (via Supports memo, then calculate the cache key and look for a stored value)
      if (@$meta['canCache']) {
        $cacheKey = md5(@$contents."_".$attributes['name']."_".json_encode($attributes['data']). "_" . json_encode($attributes['inline']));
      }
      if ($cacheKey) {
        $cached = get_transient($cacheKey);
        if ($cached) {
          QueryMonitor::push($queryFile, "block (cached)");
          QueryMonitor::pop();
          return $cached;
        }
      }

      QueryMonitor::push($queryFile, "block");
      $params = EDTemplates::getQueryParams();
      if (@!$attributes['id']) $attributes['id'] = 'block_'.md5((string)rand(0, 10000000));
      BlockQLRoot::setContext($attributes);
      $result = graphql([
        'query' => $contents . FragmentLoader::getAll(),
        'variables' => $params
      ]);

      if (@$result['errors']) {
        foreach ($result['errors'] as $err) {
          QueryMonitor::logError($err['message']);
        }
        QueryMonitor::pop();
        return ["errors" => $result['errors']];
      }
      
      // Extract result data into props
      $props = [];
      foreach ($result['data'] as $key => $value) {
        // Extract the block data
        if ($key === 'block') {
          foreach ($value as $blockKey => $result) {
            if ($blockKey !== $meta['graphql_field_name']) {
              QueryMonitor::logError("Invalid block name in block query for \"".$meta['title']."\" - expected '".$meta['graphql_field_name']."' but found \"{$blockKey}\"");
            }
            $props = array_merge($props, $result);
          }
        } else {
          $props[$key] = $value;
        }
      }
      QueryMonitor::pop();

      if ($cacheKey) {
        set_transient($cacheKey, $props, 60 * 60 * 24);
      }

      return $props;
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
        // $attrs = [
        //   'id' => $block['attrs']['id'],
        //   'data' => []
        // ];
        // foreach ($block['attrs']['data'] as $key => $val) {
        //   if (strpos($key, "_") === 0) {
        //     $attrs['data'][$val] = $block['attrs']['data'][substr($key, 1)];
        //     $attrs['data'][$key] = $val;
        //   }
        // }
        $block['props'] = $this->runBlockQuery($meta, $block['attrs']);
        $block['inline'] = @$block['attrs']['inline'];
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
      $blocks = array_values(array_map(function($block) {
        $block = $this->processSingleBlock($block);
        if (is_array(@$block['innerBlocks']) && count(@$block['innerBlocks'])) {
          $block['innerBlocks'] = $this->processBlocks($block['innerBlocks']);
        }
        return $block;
      }, $blocks));

      // Group blocks
      if (count(EDBlocks::$blockGroupTargets) > 0) {
        $blocks = $this->groupBlocks($blocks);
      }
      return $blocks;
    }

    public function groupBlocks($blocks) {
      $grouper = new BlockGrouper();
      $result = $grouper->groupBlocks($blocks);;
      return $result;
    }

  }

  class BlockGrouper {
    private $result = [];

    private $currentTarget = null;
    private $currentGroupHTML = [];

    public function groupBlocks($blocks) {
      foreach ($blocks as $block) {
        $target = EDBlocks::$blockGroupTargets[$block['blockName']];
        if ($target !== $this->currentTarget) {
          if ($this->currentTarget) {
            $this->finalizeGroup();
          }
          if ($target) {
            $this->currentTarget = $target;
            $this->currentGroupHTML = [];
          }
        }
        if ($target) {
          $this->currentGroupHTML[] = $block['innerHTML'];
        } else {
          $this->result[] = $block;
        }
      }

      $this->finalizeGroup();

      return $this->result;
    }

    public function finalizeGroup() {
      if (!$this->currentTarget) return;

      $this->result[] = [
        'grouped' => true,
        'blockName' => $this->currentTarget,
        'attrs' => (object)[],
        'innerBlocks' => [],
        'innerHTML' => implode("\n", $this->currentGroupHTML),
        'innerContent' => [],
        'props' => (object)[],
        'rule' => 'render'
      ];
      $this->currentTarget = null;
      $this->currentGroupHTML = [];
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
        self::$context['data'] ?? [],
        self::$context['id'],
        false
      );
    }

  }