<?php

function __getACFEnumClass($type) {
  if (class_exists("acf_field_select")) {
    class acf_enum_field_select extends acf_field_select {
      public $args = [];
      public $name = "";
      public $label = "";
      public $_name = "";
      public $enumName = "";

      public function __construct($name, $args) {
        $this->_name = $name;
        $this->enumName = graphql_format_type_name($name) . "Option";
        $this->args = $args;
        parent::__construct();

        add_filter("acf/load_field/type={$this->_name}", function ($field) {
          $field["choices"] = $this->args["options"];
          return $field;
        });

        add_filter('wpgraphql_acf_supported_fields', function ($types) {
          $types[] = $this->_name;
          return $types;
        });

        $opts = [];
        foreach ($this->args['options'] as $key => $label) {
          $opts[] = json_encode($key);;
        }
        ED()->register_typed_scalar($this->enumName, count($opts) ? implode("|", $opts) : 'String');

        add_filter('wpgraphql_acf_register_graphql_field', function ($resolver, $type_name, $field_name, $config) {
          if ($config['acf_field']['type'] === $this->name) {
            $resolver['type'] = $this->enumName;
          }
          return $resolver;
        }, 1, 4);
      }

      public function initialize() {
        parent::initialize();
        $this->name = $this->_name;
        $this->label = $this->args["label"];
      }
    }
  }

  return acf_enum_field_select::class;
}
