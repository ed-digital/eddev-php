<?php

class EDInlineTypes {
  static $inlineTypes = [];

  static function init() {
    add_action('graphql_register_types', function () {
      register_graphql_object_type('CurrentInlineType', [
        'fields' => [
          'none' => [
            'type' => 'String'
          ]
        ]
      ]);
      register_graphql_field('RootQuery', 'inlineTypes', [
        'type' => 'CurrentInlineType',
        'resolve' => function () {
          return [];
        }
      ]);
    });
  }

  static function registerInlineType($name, $config) {
    register_graphql_field('CurrentInlineType', $name, [
      'type' => $config['objectType'],
      'args' => [
        'value' => [
          'type' => 'Json'
        ]
      ],
      'resolve' => function ($root, $args) use ($name) {
        return self::resolveObject($name, $args['value']);
      }
    ]);

    self::$inlineTypes[$name] = $config;
  }

  protected static function resolveObject($name, $value) {
    $type = self::$inlineTypes[$name];
    if ($type && is_callable($type['resolve'])) {
      return $type['resolve']($value);
    }

    return null;
  }

  static function resolveValue($name, $value) {
    $input = json_encode($value);
    $type = self::$inlineTypes[$name];
    $query = "
      query {
        inlineTypes {
          {$name}(value: $input) {
            ...{$type['fragment']}
          }
        }
      }
    " . FragmentLoader::getAll();

    $value = graphql([
      'query' => $query
    ]);
    if (isset($value['errors'])) {
      throw new Exception(json_encode($value['errors']));
    }
    return @$value['data']['inlineTypes'][$name];
  }
}

EDInlineTypes::init();
