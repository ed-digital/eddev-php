<?php

  class EDCache {
    private $key;
    private $value;

    static function withKey($key) {
      return new EDCache($key);
    }

    public function __construct($key) {
      $this->key = $key;
      $this->value = get_transient('_cache_'.$this->key);
    }

    public function getValue() {
      return $this->value;
    }

    public function hasValue() {
      return $this->value ? true : false;
    }

    public function setValue($value, $expiry) {
      set_transient("_cache_".$this->key, $value, $expiry);
    }

    public function clear() {
      delete_transient("_cache_".$this->key);
    }
  }

  // Function which allows us to test if the user is logged in, before the init action
  function early_user_logged_in() {
    return @$_COOKIE[LOGGED_IN_COOKIE] ? true : false;
  }

  // Caches GraphQL queries
  function cached_graphql($args, $cacheTime = 0) {
    return graphql($args);

    $key = md5(@$_SERVER['HTTP_HOST']."_".json_encode($args));
    $cache = EDCache::withKey($key);
    // If the user is logged in, or the cache time is 0, clear the cache and return a fresh result
    if (early_user_logged_in() || $cacheTime === 0) {
      $cache->clear();
      return graphql($args);
    }
    if ($cache->hasValue()) {
      return $cache->getValue();
    } else {
      $result = graphql($args);
      $result['_generated'] = date('r');
      $cache->setValue($result, $cacheTime);
      return $result;
    }
  }