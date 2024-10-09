<?php

class Routes {
  static $routes = [];
  static function init() {
    add_filter('query_vars', function ($query_vars) {
      $query_vars[] = 'custom_route';
      for ($i = 0; $i < 7; $i++) {
        $query_vars[] = 'match_' . $i;
      }
      return $query_vars;
    });

    add_action('parse_query', [__CLASS__, 'handleCustomRoute'], 1);

    add_filter('template_include', ['Routes', 'filterTemplate'], 1);

    add_filter('document_title_parts', [__CLASS__, 'filterTitleParts'], 0, 1);
    add_filter('wp_title', [__CLASS__, 'filterTitle'], -1000, 1);
    add_filter('wpseo_title', [__CLASS__, 'filterTitle'], -1000, 1);
    add_filter('wpseo_frontend_presentation', [__CLASS__, 'filterOpenGraph'], -1000, 1);
  }

  /**
   * Double-checks the custom route, and handles potential 404s
   */
  static function handleCustomRoute($query) {
    if ($query->is_main_query() && self::isCustomRoute()) {
      $route = self::getCustomRoute();
      $query->set_home(false);
      $query->set_front_page(false);
      if (isset($route['is404'])) {
        $params = self::getCustomRouteQueryVars();
        if ($route['is404']($params)) {
          $query->set_404();
          status_header(404);
          $query->is_404 = true;
          set_query_var('custom_route', null);
        }
      }
    }
  }

  static function registerRoute($pattern, $args) {
    $key = md5($pattern);
    $uri = 'index.php?custom_route=' . $key;
    if (@$args['queryVars']) {
      foreach (@$args['queryVars'] as $i => $var) {
        if (preg_match("/^\\$[0-9]+/", $var)) {
          $matchIndex = substr($var, 1);
          $uri .= '&match_' . $matchIndex . '=$matches[' . $matchIndex . ']';
        }
      }
    }
    self::$routes[$key] = $args;
    add_rewrite_rule($pattern, $uri, $args['position'] ?? 'top');
  }

  static function flush() {
    flush_rewrite_rules();
  }

  static function isCustomRoute() {
    return !!get_query_var('custom_route');
  }

  static function getCustomRouteQueryVars() {
    $routeID = get_query_var('custom_route');
    if (!$routeID || !isset(self::$routes[$routeID])) {
      return [];
    }
    $args = @self::$routes[$routeID];
    $vars = [];
    if ($args && @$args['queryVars']) {
      foreach (@$args['queryVars'] as $key => $var) {
        if (preg_match("/^\\$[0-9]+/", $var)) {
          $matchIndex = substr($var, 1);
          $vars[$key] = get_query_var('match_' . $matchIndex);
        } else if (is_callable($var)) {
          $vars[$key] = $var();
        } else {
          $vars[$key] = $var;
        }
      }
    }
    return $vars;
  }

  static function filterTemplate($template) {
    global $wp_query;
    wp_reset_query();
    $route = get_query_var('custom_route');
    if ($route) {
      $args = @self::$routes[$route];
      if (isset($args) && $args['template']) {
        return ED()->themePath . "/" . $args['template'];
      }
    }
    return $template;
  }

  static function getCustomRoute() {
    $routeId = get_query_var('custom_route');
    if ($routeId) {
      if (isset(self::$routes[$routeId])) {
        return self::$routes[$routeId];
      }
    }
    return null;
  }

  static function filterTitle($title) {
    $route = get_query_var('custom_route');
    if ($route) {
      $args = self::$routes[$route];
      if (is_string($args['title'])) {
        return $args['title'];
      } else if (is_callable($args['title'])) {
        return $args['title'](self::getCustomRouteQueryVars());
      }
    }
    return $title;
  }

  static function filterTitleParts($parts) {
    if (self::isCustomRoute()) {
      $parts['title'] = self::filterTitle($parts['title']);
      $parts['site'] = get_bloginfo('name', 'display');
    }
    return $parts;
  }

  static function filterOpenGraph($presentation) {
    $route = get_query_var('custom_route');
    if ($route) {
      $args = self::$routes[$route];
      if (is_string($args['ograph'])) {
        return $args['ograph'];
      } else if (is_callable($args['ograph'])) {
        return $args['ograph'](self::getCustomRouteQueryVars(), $presentation);
      }
    }
    return $presentation;
  }
}

Routes::init();
