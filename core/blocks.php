<?php

use ED\AssetManifest;
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

    add_action('admin_init', function () {
      wp_add_inline_script('wp-block-editor', "window.ED_BLOCK_TAGS = " . json_encode(self::$coreBlockTags), 'after');
    });

    /**
     * A bit of a hack which allows the Taxonomy field to work with block previews in the editor.
     * This may be fixed by ACF in the future, but essentially the live-preview when editing ACF fields
     * from 'post meta' blocks which have load_terms breaks. This is because the field is trying to load
     * from the post, instead of loading from the parameters sent along with the preview request.
     */
    add_action('wp_ajax_acf/ajax/fetch-block', function () {
      $info = @json_decode(stripslashes($_POST['block']));
      if (!$info || !isset($info->name)) return;
      $block = self::$blocks[@$info->name];
      if (!$block || !isset($block['use_post_meta'])) return;
      if ($block['use_post_meta']) {
        add_filter('acf/load_field', function ($field) {
          $field['load_terms'] = false;
          $field['save_terms'] = false;
          return $field;
        });
      }
    }, -5);

    add_filter('block_categories_all', function ($categories) {
      $categories[] = array(
        'slug' => 'layouts',
        'title' => 'Layout',
        'icon' => ''
      );
      return $categories;
    });

    add_action('admin_init', function () {
      add_filter('the_post', function ($post) {
        $post->post_content = str_replace('"mode":"edit"', '"mode":"preview"', $post->post_content);
        return $post;
      });
    });

    add_filter('block_editor_settings_all', function ($settings) {
      $settings['fontLibraryEnabled'] = false;
      $settings['enableOpenverseMediaCategory'] = false;
      return $settings;
    });

    add_filter('should_load_remote_block_patterns', '__return_false');

    add_filter('allowed_block_types_all', function ($types, $ctx) {
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
        if (!empty($post)) {
          // Exclude by post type, if a post type array has been specified
          if (@is_array(($def['types']))) {
            if (!in_array($post->post_type, $def['types'])) continue;
          }
          // Exclude by template
          if ($post->post_type === 'page') {
            if (@is_array($def['templates']) && @!in_array($templateName, $def['templates'])) {
              continue;
            }
          }
        }

        $allowedBlocks[] = $name;
      }

      $allowedBlocks[] = 'core/list-item';
      $allowedBlocks[] = "core/block";

      return $allowedBlocks;
    }, 2, 3);
  }

  static function getBlock($name, $fallback = false) {
    if (isset(self::$blocks[$name])) {
      return self::$blocks[$name];
    }
    if ($fallback) {
      return [
        'id' => $name
      ];
    }
    return null;
  }

  static function loadBlocks() {
    if (!function_exists("acf_register_block_type")) {
      return;
    }

    $themeInfo = EDThemeInfo::load();

    foreach ($themeInfo['blocks'] as $block) {
      // Skip _editor and _core
      if (preg_match("/^_[^\/]+$/", $block['id'])) continue;
      $block['supports']['jsx'] = true;
      $block['render_callback'] = ['EDBlocks', 'renderBlockJSON'];
      $block['use_post_meta'] = isset($block['postmeta']);
      $block['acf_block_version'] = 2;
      $block['validate'] = true;
      $block['styles'] = isset($block['blockStyles']) ? $block['blockStyles'] : null;
      acf_register_block_type($block);
      self::$blocks[$block['acfName']] = $block;
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
    if (@$lock['type']) {
      add_action('admin_init', function () use ($lock) {
        $postType = get_post_type_object($lock['type']);
        $postType->template = $lock['content'];
        $postType->template_lock = $lock['mode'] ?? 'all';
      });
    }
    if (@$lock['template']) {
      add_filter('block_editor_settings', function ($settings, $post) use ($lock) {
        if ($post->post_type === @$lock['type']) {
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
    $fields = acf_get_block_fields($args);
    foreach ($fields as $field) {
      // $args['data'][$field['name']] = $args['data'][$field['key']];
      // unset($args['data'][$field['key']]);
      $args['data']["_{$field['name']}"] = $field['key'];
    }

    $result = BlockQL::runBlockQuery($block, [
      'id' => $args['id'],
      'name' => $args['name'],
      'data' => $args['data']
    ], 0);

    echo json_encode($result);
  }

  static function blockFieldName($name) {
    $parts = explode("/", str_replace("acf/", "", $name));
    $parts = array_map(function ($i) {
      return Config::camel_case($i);
    }, $parts);
    return implode("_", $parts);
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
      'resolve' => function ($root, $args, $context, $info) {
        return new BlockQLRoot();
      }
    ]);

    register_graphql_scalar("ContentBlocks", [
      "description" => "Content blocks in ED.'s standard content blocks format.",
      "serialize" => function ($value) {
        return $value;
      },
      "parseValue" => function ($value) {
        return $value;
      },
      "parseLiteral" => function ($value) {
        return $value;
      }
    ]);

    register_graphql_scalar("Json", [
      "description" => "Any JSON data",
      "serialize" => function ($value) {
        return $value;
      },
      "parseValue" => function ($value) {
        return $value;
      },
      "parseLiteral" => function ($value) {
        return $value->value;
      }
    ]);

    register_graphql_interface_type('WithContentBlocks', [
      'fields' => [
        'contentBlocks' => [
          'type' => ['non_null' => 'ContentBlocks'],
          'description' => "The current Gutenberg block in context.",
          'args' => [
            'include' => [
              'type' => ['list_of' => ['non_null' => 'String']],
              'description' => 'Include only the matching blocks. You can specify a list of block names or tags, use flags as "key=value" pairs. Block names can use wildcards, like "blog/*".'
            ],
            'exclude' => [
              'type' => ['list_of' => ['non_null' => 'String']],
              'description' => 'Exclude only the matching blocks. You can specify a list of block names or tags, use flags as "key=value" pairs. Block names can use wildcards, like "blog/*".'
            ],
            'flattenExcluded' => [
              'type' => 'Boolean',
              'description' => 'When a block is excluded by a filter, replace it with its inner blocks.'
            ],
            'maxDepth' => [
              'type' => 'Int',
              'description' => 'Maximum inner blocks depth to include.'
            ],
            'limit' => [
              'type' => 'Int',
              'description' => 'Limit the number of top-level blocks returned'
            ]
          ],
          'resolve' => function ($root, $args, $context, $info) {
            $post = get_post($root->ID);
            $content = apply_filters('ed_blocks_pre_content_' . $post->post_type, $post->post_content, $post);
            return $this->processBlocks(parse_blocks($content), $root->ID, $args);
          }
        ]
      ]
    ]);

    register_graphql_interfaces_to_types(['WithContentBlocks'], ['ContentNode']);

    add_filter('graphql_acf_get_root_id', function ($id, $root) {
      if ($root instanceof BlockQLRoot) {
        return $root->id;
      }
      return $id;
    }, 10, 2);

    $this->registerBlockFields();
  }

  public function registerBlockFields() {
    // Add field groups
    $field_groups = acf_get_field_groups();

    if (empty($field_groups) || !is_array($field_groups)) {
      return;
    }

    // Track block field groups
    $blockFieldConfigs = [];
    $postFieldConfigs = [];

    // Loop over each field group
    foreach ($field_groups as $field_group) {

      // Get a list of block names this field group targets
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
        $block = EDBlocks::getBlock($blockName);

        if (!$block) continue;

        $field_name = $block['graphqlFieldName'];
        $field_group['type'] = 'group';
        $field_group['name'] = $field_name;
        $field_group['sub_fields'] = acf_get_fields($field_group['key']);

        if (isset($block['postmeta'])) {
          $postMeta = $block['postmeta'];
          if (isset($postMeta['fieldName']) && isset($postMeta['postTypes'])) {
            $post_field = $postMeta['fieldName'];
            foreach ($postMeta['postTypes'] as $postTypeName) {
              $postTypeObject = get_post_type_object($postTypeName);
              if (!empty($postTypeObject) && isset($postTypeObject->graphql_single_name)) {
                $target_type = $postTypeObject->graphql_single_name;
                $config = null;
                if (!isset($postFieldConfigs[$target_type])) {
                  $postFieldConfigs[$target_type] = [];
                }
                if (!isset($postFieldConfigs[$target_type][$post_field])) {
                  $config = [
                    'name' => $post_field,
                    'acf_field' => $field_group,
                    'acf_field_group' => null,
                    'resolve' => function ($root) {
                      return isset($root) ? $root : null;
                    }
                  ];
                  $config['acf_field']['graphql_types'] = [$target_type];
                  $config['acf_field']['name'] = $post_field;
                  $config['acf_field']['graphql_field_name'] = $post_field;
                  $config['acf_field']['show_in_graphql'] = 1;
                  $qualifier = "Fields defined in the \"" . $field_group['title'] . "\" field group";
                  $config['description'] = $field_group['description'] ? $field_group['description'] . ' | ' . $qualifier : $qualifier;
                  $postFieldConfigs[$target_type][$post_field] = $config;
                } else {
                  $postFieldConfigs[$target_type][$post_field]['acf_field']['sub_fields'] = array_merge(
                    $postFieldConfigs[$target_type][$post_field]['acf_field']['sub_fields'] ?? [],
                    $field_group['sub_fields']
                  );
                }
              }
            }
          }
        }

        if (!isset($blockFieldConfigs[$blockName])) {
          $config = [
            'name' => $field_name,
            'acf_field' => $field_group,
            'acf_field_group' => null,
            'resolve' => function ($root) {
              return isset($root) ? $root : null;
            }
          ];
          $config['acf_field']['graphql_types'] = ['CurrentBlock'];
          $config['acf_field']['name'] = $field_name;
          $config['acf_field']['graphql_field_name'] = $field_name;
          $config['acf_field']['show_in_graphql'] = 1;
          $config['postmeta_fields'] = [];

          $qualifier = "Fields defined in the \"" . $field_group['title'] . "\" field group";
          $config['description'] = $field_group['description'] ? $field_group['description'] . ' | ' . $qualifier : $qualifier;
          $blockFieldConfigs[$blockName] = $config;
        } else {
          $blockFieldConfigs[$blockName]['acf_field']['sub_fields'] = array_merge(
            $blockFieldConfigs[$blockName]['acf_field']['sub_fields'] ?? [],
            $field_group['sub_fields']
          );
        }
      }
    }

    foreach ($blockFieldConfigs as $config) {
      $field_name = $config['name'];
      $this->register_graphql_field("CurrentBlock", $field_name, $config);
    }

    foreach ($postFieldConfigs as $target_type => $fields) {
      foreach ($fields as $field_name => $config) {
        $this->register_graphql_field($target_type, $field_name, $config);
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

  public function parseBlocks($content, $postID) {
    return $this->processBlocks(parse_blocks($content), $postID);
  }

  public static function runBlockQuery($meta, $attributes, $postID) {
    // Load the query
    $query = new \ED\GraphQLQuery("blocks/" . $meta['id'], EDTemplates::getQueryParams());

    if (!$query->exists()) return null;

    $willExecute = $query->prepare();
    if ($willExecute) {
      if (!isset($attributes['id'])) {
        $attributes['id'] = "block_" . acf_get_block_id($attributes, [], isset($meta['use_post_meta']));
      }
      BlockQLRoot::setContext($attributes);
      if (isset($meta['use_post_meta'])) {
        acf_add_block_meta_values($attributes, $postID);
      }
    }

    $result = $query->getResult();

    // Extract result data into props
    $props = [];
    if (!isset($result['data'])) {
      return null;
    }
    foreach ($result['data'] as $key => $value) {
      // Extract the block data
      if ($key === 'block') {
        foreach ($value as $blockKey => $result) {
          if ($blockKey !== $meta['graphqlFieldName']) {
            QueryMonitor::logError("Invalid block name in block query for \"" . $meta['title'] . "\" - expected '" . $meta['graphqlFieldName'] . "' but found \"{$blockKey}\"");
          }
          $props = array_merge($props, $result ?? []);
        }
      } else {
        $props[$key] = $value;
      }
    }


    return $props;
  }

  public function isCoreLayoutBlock($block) {
    if ($block['blockName'] === 'core/column') return true;
    return false;
  }

  public function processSingleBlock($block, $postID) {
    if (strpos($block['blockName'], "acf/") === 0) {
      // ACF blocks should have their 
      $meta = EDBlocks::getBlock($block['blockName']);
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
      AssetManifest::importChunk("blocks/" . $meta['id'] . ".tsx", 'modulepreload');
      $block['props'] = $this->runBlockQuery($meta, $block['attrs'], $postID);
      if (isset($block['attrs']['inline'])) {
        $block['inline'] = $block['attrs']['inline'];
      }
      if (isset($block['attrs']['values'])) {
        $block['values'] = [];
        foreach ($block['attrs']['values'] as $type => $values) {
          $block['values'] = [];
          foreach ($values as $key => $value) {
            $block['values'][$type][$key] = EDInlineTypes::resolveValue($type, $value);
          }
        }
      }

      // Add the className attribute as a property, using the default block style if one is set
      $block['class'] = isset($block['attrs']['className']) ? $block['attrs']['className'] : null;
      if (!isset($block['class']) && isset($meta['defaultBlockStyle'])) {
        $block['class'] = "is-style-" . $meta['defaultBlockStyle'];
      }

      $block['flags'] = $meta['flags'];
      $block['tags'] = $meta['tags'];
      $block['slug'] = $meta['id'] ?? $meta['acfName'];

      unset($block['attrs']);
      unset($block['innerContent']);
      unset($block['innerHTML']);
      return $block;
    } else {
      $rule = @$this->rules[$block['blockName']];
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

  public function processBlocks($blocks, $postID, $args = [
    'include' => null,
    'exclude' => null,
    'limit' => null,
    'maxDepth' => null,
    'flattenExcluded' => false
  ]) {
    $args = [
      'include' => isset($args['include']) ? $args['include'] : null,
      'exclude' => isset($args['exclude']) ? $args['exclude'] : null,
      'limit' => isset($args['limit']) ? $args['limit'] : null,
      'maxDepth' => isset($args['maxDepth']) ? $args['maxDepth'] : null,
      'flattenExcluded' => isset($args['flattenExcluded']) ? $args['flattenExcluded'] : false
    ];

    // Expand pattern blocks
    $expanded = [];
    foreach ($blocks as $block) {
      if ($block['blockName'] === 'core/block') {
        $patternId = $block['attrs']['ref'];
        $post = get_post($patternId);
        if ($post) {
          $patternBlocks = apply_filters("ed_load_pattern_blocks", parse_blocks($post->post_content), $post, $postID, $args);
          foreach ($patternBlocks as $patternBlock) {
            $expanded[] = $patternBlock;
          }
        }
      } else if ($block['blockName'] === "core/slot-group") {
        if (isset($args['include']) && @count($args['include']) && $args['flattenExcluded'] && !in_array("core/slot-group", $args['include'])) {
          foreach ($block['innerBlocks'] ?? [] as $innerBlock) {
            $expanded[] = $innerBlock;
          }
          continue;
        }
        $expanded[] = [
          'blockName' => 'core/slot-group',
          'slotId' => @$block['attrs']['props']['id'],
          'innerBlocks' => $block['innerBlocks']
        ];
      } else {
        $expanded[] = $block;
      }
    }
    $blocks = $expanded;

    // Filter out empty blocks
    $blocks = array_filter($blocks, function ($block) {
      if (!$block['blockName'] && preg_match("/^\s*$/", $block['innerHTML'])) {
        // Empty block
        return false;
      } else {
        return true;
      }
    });

    $limit = (int)$args['limit'] > 0 ? (int)$args['limit'] : null;
    unset($args['limit']);

    // Process each block
    $input = $blocks;
    $blocks = [];
    foreach ($input as $block) {
      // Apply limit
      if ($limit > 0 && count($blocks) >= $limit) break;

      // Get block meta
      $meta = EDBlocks::getBlock($block['blockName'], true);

      $included = true;
      $childrenOnly = false;

      // Handle frontendMode property
      if ($meta && isset($meta['frontendMode'])) {
        if ($meta['frontendMode'] === 'hidden') {
          if ($args['flattenExcluded']) {
            $childrenOnly = true;
            $included = false;
          } else {
            continue;
          }
        } else if ($meta['frontendMode'] === 'childrenOnly') {
          $childrenOnly = true;
          $included = false;
        }
      }

      // Apply include/exclude filters
      if (is_array($args['include']) && count($args['include'])) {
        $matches = self::matchBlock($meta, $block, $args['include']);
        if (!$matches) {
          $included = false;
        }
      }
      if (is_array($args['exclude']) && count($args['exclude'])) {
        $matches = self::matchBlock($meta, $block, $args['exclude']);
        if ($matches) {
          $included = false;
        }
      }

      if ($args['flattenExcluded'] && !$included) {
        $childrenOnly = true;
      }

      if ($childrenOnly) {
        if (is_array(@$block['innerBlocks']) && count(@$block['innerBlocks'])) {
          $children = $this->processBlocks($block['innerBlocks'], $postID, [
            'include' => $args['include'],
            'exclude' => $args['exclude'],
            'limit' => $limit,
            'maxDepth' => $args['maxDepth'] - 1,
            'flattenExcluded' => $included ? false : $args['flattenExcluded']
          ]);
          foreach ($children as $block) {
            $blocks[] = $block;
          }
        }
        continue;
      }

      if ($included) {
        $block = $this->processSingleBlock($block, $postID);
        if (isset($block['innerBlocks']) && is_array($block['innerBlocks']) && count($block['innerBlocks'])) {
          $block['innerBlocks'] = $this->processBlocks($block['innerBlocks'], $postID, [
            'include' => $args['include'],
            'exclude' => $args['exclude'],
            'limit' => $limit,
            'maxDepth' => $args['maxDepth'] - 1
          ]);
        } else {
          unset($block['innerBlocks']);
        }
        $blocks[] = $block;
      }
    }

    // Group blocks
    if (count(EDBlocks::$blockGroupTargets) > 0) {
      $blocks = $this->groupBlocks($blocks);
    }

    return $blocks;
  }

  public function matchBlock($meta, $block, $filter) {
    if (!is_array($filter)) {
      return true;
    }
    foreach ($filter as $cond) {
      // Match by ACF block name or ED block name
      if (strpos($cond, '*') !== false && function_exists('fnmatch')) {
        if (isset($meta['id']) && fnmatch($cond, $meta['id'])) {
          return true;
        }
        if (isset($meta['acfName']) && fnmatch($cond, $meta['acfName'])) {
          return true;
        }
      } else {
        if (isset($meta['id']) && $cond === $meta['id']) {
          return true;
        }
        if (isset($meta['acfName']) && $cond === $meta['acfName']) {
          return true;
        }
      }
      // Match by flag key/value
      if (isset($meta['flags']) && is_array($meta['flags'])) {
        if (preg_match("/^([^:=]+)=([^:=]+)$/", $cond, $matches)) {
          $key = $matches[1];
          $val = $matches[2];
          if (isset($meta['flags'][$key])) {
            $flagVal = $meta['flags'][$key];
            if ($flagVal == $val) {
              return true;
            }
          }
        }
      }
      // Match by tag
      if (isset($meta['tags']) && in_array($cond, $meta['tags'])) {
        return true;
      }
    }
    return false;
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
      $target = isset(EDBlocks::$blockGroupTargets[$block['blockName']]) ? EDBlocks::$blockGroupTargets[$block['blockName']] : null;
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
      'innerHTML' => implode("\n", $this->currentGroupHTML),
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
