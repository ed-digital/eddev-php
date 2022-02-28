<?php

  class Routes {
    static $routes = [];
    static function init() {
      add_filter('query_vars', function($query_vars) {
        $query_vars[] = 'custom_route';
        return $query_vars;
      });

      add_filter('template_include', ['Routes', 'filterTemplate'], 1);
      
      add_filter('wp_title', ['Routes', 'filterTitle'], -1000, 1);
      add_filter('wpseo_title', ['Routes', 'filterTitle'], -1000, 1);
    }

    static function registerRoute($pattern, $args) {
      $key = md5($pattern);
      $uri = 'index.php?custom_route='.$key;
      self::$routes[$key] = $args;
      add_rewrite_rule($pattern, $uri, $args['position'] ?? 'top');
    }

    static function flush() {

    }

    static function filterTemplate($template) {
      $route = get_query_var('custom_route');
      if ($route) {
        $args = self::$routes[$route];
        if ($args['template']) {
          return $args['template'];
        }
      }
      return $template;
    }

    static function filterTitle($title) {
      $route = get_query_var('custom_route');
      if ($route) {
        $args = self::$routes[$route];
        if (is_string($args['title'])) {
          return $args['title'];
        } else if (is_callable($args['title'])) {
          return $args['title']();
        }
      }
      return $title;
    }
  }

  Routes::init();