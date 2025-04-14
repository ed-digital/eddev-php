<?php

namespace ED;

class TemplateParts {

  static $registeredTemplateParts = [];

  static function setup() {
    add_action("acf/init", [__CLASS__, "registerACF"]);
    add_action("graphql_register_types", [__CLASS__, "registerGraphQL"]);
    add_filter('get_block_templates', [__CLASS__, "_filter_block_templates"], 10, 3);
    add_filter('get_block_template', [__CLASS__, "_filter_block_template"], 10, 3);
    add_filter('wp_theme_json_data_theme', [__CLASS__, '_filter_theme_json'], 10, 1);
  }

  static function registerTemplatePart($meta) {
    self::$registeredTemplateParts[$meta['name']] = $meta;
  }

  protected static function createWpPartObject($meta) {
    $part = new \WP_Block_Template();
    $part->type = "wp_template_part";
    $part->theme = get_stylesheet();
    $part->slug = $meta['name'];
    $part->status = "publish";
    $part->area = $meta['area'] ?? 'general';
    $part->title = $meta['title'];
    $part->content = self::getInitialContent($meta);
    $part->source = 'Theme';
    $part->has_theme_file = 1;
    $part->is_custom = 1;
    $part->origin = 'theme';
    $part->id = get_stylesheet() . '//' . $meta['name'];
    return $part;
  }

  static function getInitialContent($meta) {
    return "<!-- wp:paragraph -->
<!-- /wp:paragraph -->";
  }

  static function _filter_block_templates($query_result, $query, $template_type) {
    if (is_array($query_result) && $template_type === 'wp_template_part') {
      $slugs = [];
      foreach ($query_result as $value) {
        $slugs[] = $value->slug;
      }
      foreach (self::$registeredTemplateParts as $meta) {
        if (!in_array($meta['name'], $slugs)) {
          $query_result[] = self::createWpPartObject($meta);
        }
      }
    }
    return $query_result;
  }

  static function _filter_block_template($block_template, $id, $template_type) {
    if (!$block_template && $template_type === 'wp_template_part') {
      $slug = preg_split('/\/\//', $id)[1];
      $meta = @self::$registeredTemplateParts[$slug];
      if ($meta) {
        return self::createWpPartObject($meta);
      }
    }
    return $block_template;
  }

  static function _filter_theme_json($theme) {
    if (count(self::$registeredTemplateParts) === 0) {
      return $theme;
    }
    $data = $theme->get_data();
    $templateParts = isset($data["templateParts"]) ? $data["templateParts"] : [];
    foreach (self::$registeredTemplateParts as $meta) {
      $templateParts[] = [
        "name" => $meta['name'],
        "title" => $meta['title'],
        "area" => $meta['area']
      ];
    }
    $theme->update_with([
      ...$data,
      "templateParts" => $templateParts
    ]);
    return $theme;
  }

  static function registerGraphQL() {
    register_graphql_type('TemplatePart', [
      'description' => 'A template part',
      'fields' => [
        'id' => [
          'type' => 'ID',
          'description' => 'The ID of the template part'
        ],
        'name' => [
          'type' => 'String',
          'description' => 'The name of the template part'
        ]
      ]
    ]);

    // Apply the WithContentBlocks interface, so that the `contentBlocks` field can be queried/filtered, the same as posts.
    register_graphql_interfaces_to_types('WithContentBlocks', ['TemplatePart']);

    // Register the `templatePart` field to the RootQuery for getting a single template part by name.
    register_graphql_field('RootQuery', 'templatePart', [
      'type' => 'TemplatePart',
      'args' => [
        'name' => ['type' => 'String']
      ],
      'resolve' => function ($root, $args, $context, $info) {
        $post = self::getTemplatePart($args['name']);
        return $post;
      }
    ]);

    $templatePartFields = [];
    foreach (self::$registeredTemplateParts as $meta) {
      $templatePartFields[graphql_format_field_name($meta['name'])] = [
        'type' => 'TemplatePart',
        'resolve' => function ($root, $args, $context, $info) use ($meta) {
          $post = self::getTemplatePart($meta['name']);
          return $post;
        }
      ];
    }
    if (count($templatePartFields) == 0) {
      $templatePartFields['nothing'] = [
        'type' => 'String',
        'resolve' => function () {
          return "This field intentionally left blank.";
        }
      ];
    }
    register_graphql_type('TemplateParts', [
      'description' => 'Mounting point for all template parts',
      'fields' => $templatePartFields
    ]);
    register_graphql_field('RootQuery', 'templateParts', [
      'type' => 'TemplateParts',
      'resolve' => function () {
        return new \stdClass();
      }
    ]);
  }
  static function registerACF() {
    // Register our ACF field type, as well as define how it should be treated via GraphQL
    ED()->registerFieldType('block-pattern', [
      'label' => 'Synced Block Pattern (ED.)',
      'type' => 'TemplatePart',
      // Load the value from ACF, and ensure it's valid.
      'loadValue' => function ($value, $postID, $field) {
        return $value;
      },
      // Using the ACF value (from $value), load a post object.
      'resolve' => function ($root, $args, $context, $info, $value) {
        if (!$value) return null;
        $post = get_post($value);
        if (!$post) return null;
        return $post;
      },
      'render' => function ($field) {
        $patterns = get_posts([
          'post_type' => 'wp_block',
          'posts_per_page' => -1,
          'post_status' => 'publish',
          'suppress_filters' => false
        ]);
?>
      <select name="<?= $field['name'] ?>">
        <option value="">None</option>
        <? foreach ($patterns as $pattern): ?>
          <option value="<?= $pattern->ID ?>" <?= ($pattern->ID == $field['value']) ? 'selected' : '' ?>><?= $pattern->post_title ?></option>
        <? endforeach ?>
      </select>
<?php
      }
    ]);
  }

  static function getTemplatePart($name) {
    return @get_posts([
      'name' => $name,
      'post_type' => 'wp_template_part'
    ])[0];
  }
}

TemplateParts::setup();
