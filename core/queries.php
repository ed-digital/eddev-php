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
      $fragments = glob(ED()->themePath."/queries/fragments/*.graphql");
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
        register_rest_route('ed/v1', '/query/(?P<name>[A-Z0-9\/\_\-]+)', [
          'methods' => 'GET',
          'callback' => ['QueryHandler', 'handleQueryRequest']
        ]);

        register_rest_route('ed/v1', '/mutation/(?P<name>[A-Z0-9\/\_\-]+)', [
          'methods' => 'POST',
          'callback' => ['QueryHandler', 'handleMutationRequest']
        ]);
      });
    }

    static function handleQueryRequest($data) {
      $name = $data['name'];
      $params = @json_decode(stripslashes($_GET['params']), true);

      $query = self::loadQuery($name);
      
      if (!$query) return self::error("Unknown query");

      $result = graphql([
        "query" => $query . FragmentLoader::getAll(),
        "variables" => $params
      ]);

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
      if (file_exists($path)) {
        return file_get_contents($path);
      } else {
        return null;
      }
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
