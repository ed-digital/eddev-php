<?php

class EDGravityForms {

  static function setup() {    
    if (class_exists("GFAPI")) {
      add_action('graphql_register_types', ["EDGravityForms", "register"]);

      add_action('rest_api_init', function () {
        register_rest_route('ed/v1', '/gf/submit', [
          'methods' => 'POST',
          'callback' => ['EDGravityForms', 'handleSubmit']
        ]);
      });

      add_action('init', function () {
        self::registerACFField();
      });

      add_action('init', function ($wp) {
        if ($_SERVER['REQUEST_URI'] == '/wp-json/ed/v1/gf/submit/') {
          header('Content-Type: application/json');
          if (@!$_POST['formID']) {
            echo json_encode(['error' => 'No form ID provided']);
            exit;
          }
          $result = self::handleSubmitFromPostData();
          unset($result['form']);
          echo json_encode($result);
          exit;
        }
      }, 1000);

      add_action('gform_after_submission', [self::class, 'handle_multi_file_meta'], 10, 2);
    }
  }

  static function register() {

    register_graphql_object_type('EDGravityFormItem', [
      'description' => 'Provides an embeddable Gravity Forms form, as HTML',
      'fields' => [
        'form' => [
          'type' => 'EDGravityFormData',
          'description' => 'The HTML code which can be be included on the frontend'
        ]
      ]
    ]);

    register_graphql_scalar('EDGravityFormData', [
      'description' => 'Contains the data necessary to render a Gravity Forms form.',
      'serialize' => function ($value) {
        return $value;
      },
      'parseValue' => function ($value) {
        return $value;
      },
      'parseLiteral' => function ($value) {
        return $value;
      }
    ]);

    register_graphql_field('RootQuery', 'gravityForm', [
      'type' => 'EDGravityFormItem',
      'args' => [
        'formId' => [
          'type' => 'ID'
        ],
      ],
      'resolve' => function ($source, $args, $context, $info) {
        $form = self::getFormByID($args['formId']);
        return [
          'form' => $form
        ];
      }
    ]);
  }

  static function registerACFField() {
    // Register our ACF field type, as well as define how it should be treated via GraphQL
    ED()->registerFieldType('gravity-form', [
      'label' => 'Gravity Form (ED.)',
      'type' => 'EDGravityFormData',
      // Load the value from ACF, and ensure it's valid.
      'loadValue' => function ($value, $postID, $field) {
        return $value;
      },
      // Using the ACF value (from $value), load a post object.
      'resolve' => function ($root, $args, $context, $info, $value) {
        $formID = (int)$value;
        if ($formID) {
          return self::getFormByID($value);
        } else {
          return null;
        }
      },
      'render' => function ($field) {
        $forms = GFAPI::get_forms();
?>
      <select name="<?= $field['name'] ?>">
        <option value="">Choose a form</option>
        <? foreach ($forms as $form): ?>
          <option value="<?= $form['id'] ?>" <?= ($form['id'] == $field['value']) ? 'selected' : '' ?>><?= $form['title'] ?></option>
        <? endforeach ?>
      </select>
<?php
      }
    ]);
  }

  static function getFormByID($formID) {
    $form = GFAPI::get_form($formID);
    $hiddenFields = [
      "is_trash",
      "is_active",
      "date_created",
      "confirmations",
      "notifications",
      "autoResponder",
      "useCurrentUserAsAuthor",
      "template_id",
      "postAuthor",
      "postCategory",
      "postStatus",
      "version",
      "nextFieldId",
      "feeds"
    ];
    foreach ($hiddenFields as $key) {
      unset($form[$key]);
    }
    return $form;
  }

  static function handleSubmit($data) {
    $form_id = absint($_POST['formID']);
    $form = GFAPI::get_form($form_id);

    foreach ($form['fields'] as $field) {
      if ($field->get_input_type() === 'fileupload' && $field->multipleFiles) {
        $field_id = 'input_' . $field->id;
        $files = $_FILES[$field_id] ?? null;
      
        if ($files && is_array($files['name'])) {
          $upload_root = GFFormsModel::get_upload_path($form_id, $field->id);
          $upload_url  = GFFormsModel::get_upload_url($form_id, $field->id);
      
          $subdir = date('Y/m');
          $upload_subdir = trailingslashit($upload_root) . $subdir;
          $upload_suburl = trailingslashit($upload_url) . $subdir;

          if (!is_dir($upload_subdir)) {
            wp_mkdir_p($upload_subdir);
          }

          $uploaded_urls = [];
      
          foreach ($files['name'] as $i => $original_name) {
            $tmp_name = $files['tmp_name'][$i];
            if (!$tmp_name || !is_uploaded_file($tmp_name)) continue;
      
            $filename = self::generate_safe_filename($original_name, $upload_root);
            $target_path = trailingslashit($upload_subdir) . $filename;
            $public_url = trailingslashit($upload_url) . $filename;
            $absolute_url = trailingslashit($upload_suburl) . $filename;
      
            if (move_uploaded_file($tmp_name, $target_path)) {
              $uploaded_urls[] = $absolute_url;
            }
          }
          self::$multi_file_field_urls[$form_id][$field->id][] = $uploaded_urls;

          $_POST[$field_id] = json_encode($uploaded_urls);
          $_POST['values'][$field_id] = json_encode($uploaded_urls);      
          unset($_FILES[$field_id]);
        }
      }      
    }

    $payload = $data->get_json_params();
    $result = GFAPI::submit_form($payload['formID'], $payload['values']);
    return $result;
  }

  static function handleSubmitFromPostData() {
    $form_id = absint($_POST['formID']);
    $form = GFAPI::get_form($form_id);

    foreach ($form['fields'] as $field) {
      if ($field->get_input_type() === 'fileupload' && $field->multipleFiles) {
        $field_id = 'input_' . $field->id;
        $files = $_FILES[$field_id] ?? null;
      
        if ($files && is_array($files['name'])) {
          $upload_root = GFFormsModel::get_upload_path($form_id, $field->id);
          $upload_url  = GFFormsModel::get_upload_url($form_id, $field->id);
      
          $subdir = date('Y/m');
          $upload_subdir = trailingslashit($upload_root) . $subdir;
          $upload_suburl = trailingslashit($upload_url) . $subdir;

          if (!is_dir($upload_subdir)) {
            wp_mkdir_p($upload_subdir);
          }

          $uploaded_urls = [];
      
          foreach ($files['name'] as $i => $original_name) {
            $tmp_name = $files['tmp_name'][$i];
            if (!$tmp_name || !is_uploaded_file($tmp_name)) continue;
      
            $filename = self::generate_safe_filename($original_name, $upload_root);
            $target_path = trailingslashit($upload_subdir) . $filename;
            $public_url = trailingslashit($upload_url) . $filename;
            $absolute_url = trailingslashit($upload_suburl) . $filename;
      
            if (move_uploaded_file($tmp_name, $target_path)) {
              $uploaded_urls[] = $absolute_url;
            }
          }
          self::$multi_file_field_urls[$form_id][$field->id][] = $uploaded_urls;

          $_POST[$field_id] = json_encode($uploaded_urls);
          $_POST['values'][$field_id] = json_encode($uploaded_urls);      
          unset($_FILES[$field_id]);
        }
      }      
    }
    
    $result = GFAPI::submit_form($_POST['formID'], @$_POST['values']);
    return $result;
  }

  public static function handle_multi_file_meta($entry, $form) {
    global $wpdb;

    foreach ($form['fields'] as $field) {
      if ($field->get_input_type() === 'fileupload' && $field->multipleFiles) {
        $field_id = $field->id;
        $field_key = 'input_' . $field_id;

        $urls = self::$multi_file_field_urls[$form['id']][$field->id] ?? null;
        if ($urls && is_array($urls)) {
          $meta_key = (string) $field->id;
          $meta_value = wp_json_encode($urls[0]);

          $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d AND meta_key = %s AND item_index = ''",
            $entry['id'],
            $meta_key
          ));

          if ($existing) {
            $wpdb->update(
              "{$wpdb->prefix}gf_entry_meta",
              [ 'meta_value' => $meta_value ],
              [ 'entry_id' => $entry['id'], 'meta_key' => $meta_key, 'item_index' => '' ]
            );
          } else {
            $wpdb->insert(
              "{$wpdb->prefix}gf_entry_meta",
              [
                'form_id'    => $form['id'],
                'entry_id'   => $entry['id'],
                'meta_key'   => $meta_key,
                'meta_value' => $meta_value,
                'item_index' => '',
              ]
            );
          }
        }
      }
    }
  }

  private static array $multi_file_field_urls = [];
      
  static function generate_safe_filename($original_name, $target_dir) {
    $base = sanitize_file_name(pathinfo($original_name, PATHINFO_FILENAME));
    $ext  = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $base = preg_replace('/[^a-zA-Z0-9-_]/', '-', $base);
  
    $timestamp = time();
    $filename = "{$base}-{$timestamp}.{$ext}";
    $counter = 1;
  
    while (file_exists(trailingslashit($target_dir) . $filename)) {
      $filename = "{$base}-{$timestamp}-{$counter}.{$ext}";
      $counter++;
    }
  
    return $filename;
  } 
}

EDGravityForms::setup();
