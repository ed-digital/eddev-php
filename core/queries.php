<?php

use WPGraphQL\Utils\QueryLog;

class FragmentLoader {

  static $cache;

  static function setup() {
    add_filter('graphiql_external_fragments', function ($fragments) {
      return array_merge($fragments, FragmentLoader::getOptimized());
    });
  }

  static function getAll() {
    if (self::$cache) return self::$cache;
    $fragments = array_merge(
      glob(ED()->themePath . "/queries/fragments/*/*.graphql"),
      glob(ED()->themePath . "/queries/fragments/*.graphql")
    );
    $output = [];
    foreach ($fragments as $frag) {
      $output[] = file_get_contents($frag);
    }
    return "\n\n" . implode("\n\n", $output);
  }

  static function getOptimized() {
    $file = ED()->themePath(".eddev/queries/fragments.json");
    if (file_exists($file)) {
      return json_decode(file_get_contents($file), true);
    } else {
      return [];
    }
  }
}

FragmentLoader::setup();

class QueryHandler {

  static function setup() {

    add_action('rest_api_init', function () {
      define('DOING_AJAX', true);
      register_rest_route('ed/v1', '/query/(?P<name>[A-Z0-9\/\_\-]+)', [
        'methods' => 'GET',
        'callback' => ['QueryHandler', 'handleQueryRequest']
      ]);

      register_rest_route('ed/v1', '/mutation/(?P<name>[A-Z0-9\/\_\-]+)', [
        'methods' => 'POST',
        'callback' => ['QueryHandler', 'handleMutationRequest']
      ]);
    });
    add_action('woocommerce_is_rest_api_request', function ($result) {
      if (strpos($_SERVER['REQUEST_URI'], '/wp-json/ed/v1/') !== false) {
        return false;
      }
      return $result;
    });
    add_filter('graphql_pre_is_graphql_http_request', function ($result) {
      if (strpos($_SERVER['REQUEST_URI'], '/wp-json/ed/v1/') !== false) {
        return true;
      }
      return $result;
    }, 10, 2);
  }

  static function shouldBypassCache($queryName = null) {
    $result = false;
    if (ED()->isDev) $result = true;
    if (early_user_logged_in() || current_user_can("edit_posts")) $result = true;
    return apply_filters('graphql_bypass_cache', $result, $queryName);
  }

  static function getCacheTime($name, $query) {
    // Allow bypassing of the cache
    $bypass = QueryHandler::shouldBypassCache($name);
    if ($bypass) {
      return 0;
    }

    $config = ED()->getCacheConfig();
    $type = "";
    $cacheTime = 0;
    if (strpos($name, "queries/") === 0) {
      $type = "queries";
    } else if (strpos($name, "views/") === 0) {
      $type = "props";
    }
    if ($config && isset($config[$type])) {
      $cacheTime = $config[$type];
    }
    if (preg_match("/#\s+cache[=:\s]+([0-9]+)/", $query, $matches)) {
      $cacheTime = (int)trim($matches[1]);
    } else if (strpos($query, 'nocache') !== false) {
      $cacheTime = 0;
    }
    return apply_filters('graphql_cache_time', $cacheTime, $name);
  }

  static function handleQueryRequest($data) {
    $name = $data['name'];

    $name = self::getQueryFileName($name);
    $query = QueryLoader::load($name);
    $cacheTime = QueryHandler::getCacheTime($name, $query);

    if ((int)$cacheTime) {
      header('Cache-Control: public, max-age=' . $cacheTime);
    } else {
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    header('X-ED-Cache-Duration: ' . (int)$cacheTime);
    header('X-ED-Generated-At: ' . date("r"));

    $params = @json_decode(stripslashes($_GET['params']), true);
    if (!$query) return self::error("Unknown query");

    if ($cacheTime > 0) {
      $result = cached_graphql([
        "name" => $name,
        "query" => $query,
        "variables" => $params
      ], $cacheTime);
    } else {
      $result = graphql([
        "query" => $query,
        "variables" => $params,
      ]);
    }

    $headers = apply_filters('graphql_response_headers_to_send', []);
    foreach ($headers as $key => $header) {
      header($key . ": " . $header);
    }

    return $result;
  }

  static function handleMutationRequest($data) {
    $name = $data['name'];
    $params = $data->get_json_params();

    $query = QueryLoader::load(self::getQueryFileName($name));

    early_user_logged_in();

    if (!$query) return self::error("Unknown query");

    $result = graphql([
      "query" => $query,
      "variables" => $params
    ]);

    $headers = apply_filters('graphql_response_headers_to_send', []);
    foreach ($headers as $key => $header) {
      header($key . ": " . $header);
    }

    return $result;
  }

  static function getQueryFileName($name) {
    return "queries/" . $name . ".graphql";
  }

  static function error($message) {
    return [
      'errors' => [
        ['message' => $message]
      ]
    ];
  }
}

QueryHandler::setup();

class QueryLoader {
  static $cache = [];

  static private function loadByName($name) {
    $optimizedFile = ED()->themePath(".eddev/queries/$name");
    $defaultFile = ED()->themePath($name);
    if (file_exists($optimizedFile)) {
      return file_get_contents($optimizedFile);
    } else if (file_exists($defaultFile)) {
      // Load the source file and append the fragments
      return file_get_contents($defaultFile) . "\n\n" . FragmentLoader::getAll();
    }
    return null;
  }

  static function load($file) {
    $file = str_replace(ED()->themePath, '', $file);
    if (isset(self::$cache[$file])) {
      return self::$cache[$file];
    }
    $value = self::loadByName($file);
    if (is_string($value)) {
      self::$cache[$file] = $value;
      return self::$cache[$file];
    } else {
      return null;
    }
  }
}
