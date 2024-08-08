<?php

  class EDSecurity {
    static function init() {
      if (!headers_sent()) {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: frame-ancestors none');
      }

      if (function_exists('add_filter')) {
        @self::disableUserEnumeration();
      }
    }

    static function disableUserEnumeration() {
      if(!is_admin()) {
        add_filter('query_vars', function($public_query_vars) {
          foreach(['author', 'author_name'] as $var) {
            $key = array_search($var, $public_query_vars);
            if (false !== $key) {
              unset($public_query_vars[$key]);
            }
          }
          return $public_query_vars;
        });
      }
      add_action('rest_authentication_errors', function( $access ) {
        if (is_user_logged_in()) {
          return $access;
        }
      
        if ((preg_match('/users/i', $_SERVER['REQUEST_URI']) !== 0) ||(isset($_REQUEST['rest_route']) && (preg_match('/users/i', $_REQUEST['rest_route']) !== 0))) {
          return new \WP_Error(
            'rest_cannot_access',
            'Only authenticated users can access the User endpoint REST API.',
            [
              'status' => rest_authorization_required_code()
            ]
          );
        }
      
        return $access;
      });
    }
  }

  EDSecurity::init();