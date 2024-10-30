<?php

class EDCache {
  private $key;
  private $value;

  static function withKey($key) {
    return new EDCache($key);
  }

  public function __construct($key) {
    $this->key = $key;
    $this->value = get_transient('cache:' . $this->key);
  }

  public function getValue() {
    return $this->value;
  }

  public function hasValue() {
    return $this->value ? true : false;
  }

  public function setValue($value, $expiry) {
    set_transient('cache:' . $this->key, $value, $expiry);
  }

  public function clear() {
    delete_transient('cache:' . $this->key);
  }
}

// Function which allows us to test if the user is logged in, before the init action
function early_user_logged_in() {
  return isset($_COOKIE[LOGGED_IN_COOKIE]) && $_COOKIE[LOGGED_IN_COOKIE] ? true : false;
}

// Caches GraphQL queries
function cached_graphql($args, $cacheTime = 0) {
  // If the user is logged in, or the cache time is 0, return a fresh result
  if ($cacheTime === 0) {
    return graphql($args);
  }

  // Determine the cache key
  $key = md5(@$_SERVER['HTTP_HOST'] . "_" . json_encode($args));
  if (isset($args['name'])) {
    $key = $args['name'] . ":" . $key;
  }

  // Check the cache
  $cache = EDCache::withKey($key);
  if ($cache->hasValue()) {
    return $cache->getValue();
  } else {
    // Fetch the result and store it
    $result = graphql($args);
    $result['_generated'] = date('r');
    $cache->setValue($result, $cacheTime);
    return $result;
  }
}
