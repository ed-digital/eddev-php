<?php

  class EDGravityForms {

    static function setup() {
      if (class_exists("GFAPI")) {
        add_action('graphql_register_types', ["EDGravityForms", "register"]);

        add_action('rest_api_init', function() {
          register_rest_route('ed/v1', '/gf/submit', [
            'methods' => 'POST',
            'callback' => ['EDGravityForms', 'handleSubmit']
          ]);
        });

        add_action('init', function() {
          self::registerACFField();
        });
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
        'serialize' => function($value) {
          return $value;
        },
        'parseValue' => function($value) {
          return $value;
        },
        'parseLiteral' => function($value) {
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
        'resolve' => function($source, $args, $context, $info) {
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
        'loadValue' => function($value, $postID, $field) {
          return $value;
        },
        // Using the ACF value (from $value), load a post object.
        'resolve' => function($root, $args, $context, $info, $value) {
          $formID = (int)$value;
          if ($formID) {
            return self::getFormByID($value);
          } else {
            return null;
          }
        },
        'render' => function($field) {
          $forms = GFAPI::get_forms();
          ?>
            <select name="<?=$field['name']?>">
              <option value="">Choose a form</option>
              <? foreach ($forms as $form): ?>
                <option value="<?=$form['id']?>" <?=($form['id'] == $field['value']) ? 'selected' : ''?>><?=$form['title']?></option>
              <? endforeach ?>
            </select>
          <?
        }
      ]);
    }

    static function getFormByID($formID) {
      $form = GFAPI::get_form($formID);
      unset($form['is_trash']);
      unset($form['is_active']);
      unset($form['date_created']);
      unset($form['confirmations']);
      unset($form['notifications']);
      return $form;
    }

    static function handleSubmit($data) {
      $payload = $data->get_json_params();

      $result = GFAPI::submit_form($payload['formID'], $payload['values']);

      return $result;
    }

  }

  EDGravityForms::setup();