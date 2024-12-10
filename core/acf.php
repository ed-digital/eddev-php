<?php

class ACFSyncEnforcer {
  static function setup() {
    add_action('current_screen', function () {
      $acf = acf_get_instance('ACF_Admin_Field_Groups');
      if (@$_GET['post']) {
        $post = get_post($_GET['post']);
        if ($post) {
          if ($post->post_type == 'acf-field-group') {
            $acf->setup_sync();
            $acf->check_sync();
            $acf->check_duplicate();
            foreach ($acf->sync as $sync) {
              if ($sync['ID'] == $_GET['post']) {
                add_action('admin_notices', function () use ($acf) {
                  echo '<div class="notice notice-error"><p>Dan here — this field group has been modified externally by Git, and needs to be synced before it can be edited.<br /><a href="/wp-admin/edit.php?post_type=acf-field-group&post_status=sync">Continue</a></p></div>';
                  echo '<style>#poststuff { display: none; }</style>';
                  // echo $acf->render_admin_table_column_local_status($sync);
                });
              }
            }
          }
        }
      }
    }, 0, 10000);
  }
}

class ACFAssetEndpoint {
  static function setup() {
    add_action('rest_api_init', function () {
      register_rest_route('ed/v1', '/media/(?P<id>[0-9\/\_\-]+)', [
        'methods' => 'GET',
        'callback' => ['ACFAssetEndpoint', 'handle']
      ]);
    });
  }

  static function handle($data) {
    return acf_get_attachment($data['id']);
  }
}

ACFSyncEnforcer::setup();
ACFAssetEndpoint::setup();

if (class_exists("acf_field")) {
  class EDACFField extends acf_field {

    public $name = 'FIELD_NAME';
    public $label = 'FIELD_LABEL';
    public $category = 'basic';
    public $graphqlTypeName = '';

    public $hooks = [];

    public $defaults = [];

    public $l10n = [];

    function __construct($name, $args) {
      $this->name = $name;
      $this->label = $args['label'];
      $this->category = $args['category'] ?? 'basic';
      $this->defaults = $args['defaultSettings'] ?? [];

      $this->hooks['loadValue'] = @$args['loadValue'];
      $this->hooks['updateValue'] = @$args['updateValue'];
      $this->hooks['validate'] = @$args['validate'];
      $this->hooks['resolve'] = @$args['resolve'];
      $this->hooks['render'] = @$args['render'];
      $this->hooks['renderSettings'] = @$args['renderSettings'];

      $this->graphqlTypeName = @$args['type'] ? $args['type'] : 'ED' . ucfirst(WPGraphQL\ACF\Config::camel_case($name . '_Field_Value'));

      // Register object type?
      if (@$args['objectType']) {
        register_graphql_object_type($this->graphqlTypeName, $args['objectType']);
      }

      // Add this field type to WPGraphQL ACF's supported field types
      add_filter('wpgraphql_acf_supported_fields', function ($types) {
        $types[] = $this->name;
        return $types;
      });

      // // Convert the database value to the normalized value
      // add_filter('graphql_acf_field_value', function ($value, $acf_field, $root, $id) {
      //   if ($acf_field['type'] === $this->name) {
      //     $loadValue = $this->hooks['loadValue'];
      //     // $resolve = $this->hooks['resolve'];
      //     if ($loadValue) {
      //       $value = $loadValue($value, $id, $acf_field);
      //     }
      //     // if ($resolve) {
      //     //   $value = $resolve($value, $acf_field, $root, $id);
      //     // }

      //     return $value;
      //   }
      //   return $value;
      // }, 1, 4);

      add_filter('wpgraphql_acf_register_graphql_field', function ($resolver, $type_name, $field_name, $config) {
        if ($config['acf_field']['type'] === $this->name) {
          $resolver['type'] = $this->graphqlTypeName;
          if ($this->hooks['resolve']) {
            $originalResolve = $resolver['resolve'];
            $resolver['resolve'] = function ($root, $args, $context, $info) use ($originalResolve) {
              $value = $originalResolve($root, $args, $context, $info);
              return $this->hooks['resolve']($root, $args, $context, $info, $value);
            };
          }
        }
        return $resolver;
      }, 1, 4);

      parent::__construct();
    }

    public function render_field($field) {
      if ($this->hooks['render']) {
        $this->hooks['render']($field);
      } else {
?>
        <input data-settings="<?= esc_attr(json_encode($field)) ?>" type="hidden" name="<?= $field['name'] ?>" value="<?= esc_attr(json_encode($field['value'])) ?>" />
<?php
      }
    }

    public function render_field_settings($field) {
      $hook = @$this->hooks['renderSettings'];
      if ($hook) {
        $hook($field);
      }
    }

    /*
      *  load_value()
      *
      *  This filter is applied to the $value after it is loaded from the db
      *
      *  @type	filter
      *  @since	3.6
      *  @date	23/01/13
      *
      *  @param	$value (mixed) the value found in the database
      *  @param	$post_id (mixed) the $post_id from which the value was loaded
      *  @param	$field (array) the field array holding all the field options
      *  @return	$value
      */

    function load_value($value, $post_id, $field) {
      $loadValue = $this->hooks['loadValue'];
      if ($loadValue) {
        $value = $loadValue($value, $post_id, $field);
        return $value;
      }

      if (is_string($value) && strlen($value) > 0) {
        $value = json_decode($value, true);
        return $value;
      } else {
        return null;
      }
    }

    /*
      *  update_value()
      *
      *  This filter is applied to the $value before it is saved in the db
      *
      *  @type	filter
      *  @since	3.6
      *  @date	23/01/13
      *
      *  @param	$value (mixed) the value found in the database
      *  @param	$post_id (mixed) the $post_id from which the value was loaded
      *  @param	$field (array) the field array holding all the field options
      *  @return	$value
      */

    function update_value($value, $post_id, $field) {

      return $value;
    }

    /*
      *  validate_value()
      *
      *  This filter is used to perform validation on the value prior to saving.
      *  All values are validated regardless of the field's required setting. This allows you to validate and return
      *  messages to the user if the value is not correct
      *
      *  @type	filter
      *  @date	11/02/2014
      *  @since	5.0.0
      *
      *  @param	$valid (boolean) validation status based on the value and the field's required setting
      *  @param	$value (mixed) the $_POST value
      *  @param	$field (array) the field array holding all the field options
      *  @param	$input (string) the corresponding input name for $_POST value
      *  @return	$valid
      */


    // function validate_value($valid, $value, $field, $input){

    //   // Basic usage
    //   if($value < @$field['custom_minimum_setting']) {
    //     $valid = false;
    //   }


    //   // Advanced usage
    //   if($value < @$field['custom_minimum_setting']) {
    //     $valid = __('The value is too little!','TEXTDOMAIN');
    //   }


    //   // return
    //   return $valid;

    // }

    /*
      *  load_field()
      *
      *  This filter is applied to the $field after it is loaded from the database
      *
      *  @type	filter
      *  @date	23/01/2013
      *  @since	3.6.0	
      *
      *  @param	$field (array) the field array holding all the field options
      *  @return	$field
      */

    function load_field($field) {

      return $field;
    }

    /*
      *  update_field()
      *
      *  This filter is applied to the $field before it is saved to the database
      *
      *  @type	filter
      *  @date	23/01/2013
      *  @since	3.6.0
      *
      *  @param	$field (array) the field array holding all the field options
      *  @return	$field
      */

    function update_field($field) {
      return $field;
    }
  }
}

/**
 * Hides the notice about WPGraphQL ACF v2 being available
 */
if (!class_exists('WPGraphQLAcf')) {
  class WPGraphQLAcf {
  }
}
