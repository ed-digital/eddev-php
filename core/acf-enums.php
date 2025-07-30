<?php

class ACFEnumItem {
  public string $graphql_name;
  public function __construct(
    public string $name,
    public string $label,
    public string $base_type,
    public $choices
  ) {
    $this->graphql_name = graphql_format_type_name($name) . "Option";
  }
}

class ACFEnums {

  /** @type ACFEnumItem */
  public static $field_types = [];

  public static ACFEnumItem|null $current = null;

  /**
   * Register a new ACF Enum field type
   */
  static function register(ACFEnumItem $field) {
    self::$field_types[$field->name] = $field;
    add_filter("acf/load_field/type={$field->name}", [__CLASS__, 'load_field']);
    add_filter("acf/load_value/type={$field->name}", [__CLASS__, 'load_value'], 10, 3);

    /** Create a scalar type, with a TypeScript union of all options */
    $opts = [];
    foreach ($field->choices as $key => $label) {
      $opts[] = json_encode($key);
    }
    ED()->registerTypedScalar($field->graphql_name, count($opts) ? implode("|", $opts) : 'String');

    /** If ACF has already initted, register the field with the ACF registry now */
    if (acf_did('init')) {
      self::$current = $field;
      self::update_acf_registry();
    }
  }

  protected static function is_multi_value($field_type) {
    return in_array($field_type, ['checkbox']);
  }

  /**
   * Register the field with ACF by creating a clone of the base field
   */
  protected static function update_acf_registry() {
    if (!class_exists("acf_field_button_group")) return;

    if (self::$current->base_type === 'button_group') {
      acf()->fields->register_field_type(new class extends acf_field_button_group {
        public function initialize() {
          parent::initialize();
          $this->name = ACFEnums::$current->name;
          $this->label = ACFEnums::$current->label;
        }
      });
    } else if (self::$current->base_type === "select") {
      acf()->fields->register_field_type(new class extends acf_field_select {
        public function initialize() {
          parent::initialize();
          $this->name = ACFEnums::$current->name;
          $this->label = ACFEnums::$current->label;
        }
      });
    } else if (self::$current->base_type === "radio") {
      acf()->fields->register_field_type(new class extends acf_field_radio {
        public function initialize() {
          parent::initialize();
          $this->name = ACFEnums::$current->name;
          $this->label = ACFEnums::$current->label;
        }
      });
    } else if (self::$current->base_type === "checkbox") {
      acf()->fields->register_field_type(new class extends acf_field_checkbox {
        public function initialize() {
          parent::initialize();
          $this->name = ACFEnums::$current->name;
          $this->label = ACFEnums::$current->label;
        }
      });
    } else {
      throw new Error("Unsupported ACF Enum type: " . self::$current->base_type);
    }
  }

  /**
   * When loading an enum field, ensure the value is a valid option.
   */
  static function load_value($value, $postID, $field) {
    if (isset(self::$field_types[$field['type']])) {
      $field_type = self::$field_types[$field['type']];
      if (self::is_multi_value($field_type->base_type)) {
        $value = is_array($value) ? $value : [];
        foreach ($value as $key => $val) {
          if (!isset($field_type->choices[$val])) {
            unset($value[$key]);
          }
        }
      } else {
        if (!isset($field_type->choices[$value])) {
          if (@$field['default_value']) {
            return $field['default_value'];
          }
          return null;
        }
      }
    }
    return $value;
  }

  /**
   * Hook into ACF to load the field type
   * The choices get injected, and the 'type' is changed to the base type
   * The type is only changed when loaded in the admin — otherwise, it's left as-is to ensure the GraphQL type can be resolved later
   */
  static function load_field($field) {
    if (isset(self::$field_types[$field['type']])) {
      $field_type = self::$field_types[$field['type']];
      $field['choices'] = $field_type->choices;
      if (is_admin() && !acf_is_screen('acf-field-group')) {
        $field['type'] = $field_type->base_type;
      }
    }
    return $field;
  }

  /** Let WPGraphQL ACF know that it should include fields of the defined typed */
  static function _graphql_supported_fields($fields) {
    return array_merge($fields, array_keys(self::$field_types));
  }

  /** Register all field types with the ACF registry */
  static function install_field_types() {
    foreach (self::$field_types as $field) {
      self::$current = $field;
      self::update_acf_registry();
    }
  }

  /**
   * Determine the GraphQL type for any ACF enum fields we're aware of
   */
  static function _graphql_acf_register_graphql_field($resolver, $type_name, $field_name, $config) {
    $enum_type = $config['acf_field']['type'];
    if ($enum_type && isset(self::$field_types[$enum_type])) {
      $field_type = self::$field_types[$enum_type];
      $enumTypeName = $field_type->graphql_name;
      if (self::is_multi_value($field_type->base_type)) {
        $resolver['type'] = ['list_of' => ['non_null' => $enumTypeName]];
      } else {
        $resolver['type'] = $enumTypeName;
      }
    }
    return $resolver;
  }

  static function init() {
    add_action('acf/include_field_types', [__CLASS__, 'install_field_types']);
    add_filter('wpgraphql_acf_supported_fields', [__CLASS__, '_graphql_supported_fields']);
    add_filter('wpgraphql_acf_register_graphql_field', [__CLASS__, '_graphql_acf_register_graphql_field'], 1, 4);
  }
}
