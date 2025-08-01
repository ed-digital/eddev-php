<?php

class QueryHandler {

  static function setup() {

    add_action('rest_api_init', [__CLASS__, '_rest_api_init']);
    add_action('woocommerce_is_rest_api_request', [__CLASS__, '_woocommerce_is_rest_api_request'], 10, 1);
    add_filter('graphql_pre_is_graphql_http_request', [__CLASS__, '_graphql_pre_is_graphql_http_request'], 10, 1);
  }

  static function _rest_api_init() {
    define('DOING_AJAX', true);
    register_rest_route('ed/v1', '/query/(?P<queryName>[A-Z0-9\/\_\-]+)', [
      'methods' => 'GET',
      'callback' => ['QueryHandler', 'handleQueryRequest']
    ]);

    register_rest_route('ed/v1', '/mutation/(?P<mutationName>[A-Z0-9\/\_\-]+)', [
      'methods' => 'POST',
      'callback' => ['QueryHandler', 'handleMutationRequest']
    ]);
  }

  static function _woocommerce_is_rest_api_request($result) {
    if (strpos($_SERVER['REQUEST_URI'], '/wp-json/ed/v1/') !== false) {
      return false;
    }
    return $result;
  }

  static function _graphql_pre_is_graphql_http_request($result) {
    if (strpos($_SERVER['REQUEST_URI'], '/wp-json/ed/v1/') !== false) {
      return true;
    }
    return $result;
  }

  static function handleQueryRequest($data) {

    // Determine the query name, stripping out any path traversal
    $name = self::getQueryName($data['queryName']);
    $params = @json_decode(stripslashes($_GET['params']), true);

    $query = new \ED\GraphQLQuery($name, $params);

    // Handle invalid query names
    if (!$query->exists()) {
      return self::error("Unknown query");
    }

    // Execute the query
    $result = $query->getResult();
    $result['queryMonitor'] = QueryMonitor::getResult();

    // Send any required headers
    $query->sendCacheHeaders();
    self::sendGraphQLHeaders($query);

    // Return the result
    return $result;
  }

  static function handleMutationRequest($data) {
    // Determine the query name, stripping out any path traversal
    $name = self::getQueryName($data['mutationName']);
    $params = $data->get_json_params();

    $query = new \ED\GraphQLQuery($name, $params);
    $query->cacheTime = 0;

    // Handle invalid query names
    if (!$query->exists()) {
      return self::error("Unknown mutation");
    }

    // Execute the query
    $result = $query->getResult();

    // Send any required headers
    self::sendGraphQLHeaders($query);

    // Return the result
    return $result;
  }

  static function getQueryName($name) {
    return "queries/" . preg_replace("/^\//", "", preg_replace("/\.+/", ".", $name));
  }

  static function sendGraphQLHeaders() {
    $headers = apply_filters('graphql_response_headers_to_send', []);
    foreach ($headers as $key => $header) {
      header($key . ": " . $header);
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
