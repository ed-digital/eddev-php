<?php

  class EDGravityForms {

    static function setup() {
      if (class_exists("GFAPI")) {
        add_action('graphql_register_types', ["EDGravityForms", "register"]);
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
          $form = GFAPI::get_form($args['formId']);
          unset($form['is_trash']);
          unset($form['is_active']);
          unset($form['date_created']);
          unset($form['confirmations']);
          unset($form['notifications']);
          return [
            'form' => $form
          ];
        }
      ]);
    }

  }

  EDGravityForms::setup();