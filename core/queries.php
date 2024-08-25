<?php

  class FragmentLoader {

    static $cache;

    static function setup() {
      add_filter('graphiql_external_fragments', function() {
        return [FragmentLoader::getAll()];
      });
    }

    static function getAll() {
      if (self::$cache) return self::$cache;
      $fragments = array_merge(
        glob(ED()->themePath."/queries/fragments/*/*.graphql"),
        glob(ED()->themePath."/queries/fragments/*.graphql")
      );
      $output = [];
      foreach ($fragments as $frag) {
        $output[] = file_get_contents($frag);
      }
      return "\n\n".implode("\n\n", $output);
    }

  }

  FragmentLoader::setup();

  class QueryHandler {

    static function setup() {

      add_action('rest_api_init', function() {
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
      add_action('woocommerce_is_rest_api_request', function($result) {
        if (strpos($_SERVER['REQUEST_URI'], '/wp-json/ed/v1/') !== false) {
          return false;
        }
        return $result;
      });
    }

    static function handleQueryRequest($data) {
      $name = $data['name'];
      $cacheTime = @ED()->getCacheConfig()['queries'] ?? 300;

      $query = self::loadQuery($name);

      $shouldCache = strpos($query, 'nocache') === false;
      if (!$shouldCache) {
        $cacheTime = 0;
      }

      if (!early_user_logged_in()) {
        if ((int)$cacheTime) {
          header('Cache-Control: public, max-age='.$cacheTime);
        } else {
          header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        header('X-ED-Cache-Duration: '.(int)$cacheTime);
        header('X-ED-Generated-At: '.date("r"));
        header('X-ED-URI: '.$_SERVER['REQUEST_URI']);
      }

      $params = @json_decode(stripslashes($_GET['params']), true);
      if (!$query) return self::error("Unknown query");

      if ($cacheTime > 0) {
        $result = cached_graphql([
          "query" => $query . FragmentLoader::getAll(),
          "variables" => $params
        ], $cacheTime);
      } else {
        $result = graphql([
          "query" => $query . FragmentLoader::getAll(),
          "variables" => $params
        ]);
      }

      return $result;
    }

    static function handleMutationRequest($data) {
      $name = $data['name'];
      $params = $data->get_json_params();

      $query = self::loadQuery($name);
      
      if (!$query) return self::error("Unknown query");

      $result = graphql([
        "query" => $query . FragmentLoader::getAll(),
        "variables" => $params
      ]);

      return $result;
    }


    static function loadQuery($name) {
      $path = ED()->themePath."/queries/".$name.".graphql";
      return file_get_contents($path);
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

    static function loadQueryFile($file) {
      if (isset(self::$cache[$file])) {
        return self::$cache[$file];
      }
      if (file_exists($file)) {
        self::$cache[$file] = file_get_contents($file);
        return self::$cache[$file];
      } else {
        return null;
      }
    }
  }