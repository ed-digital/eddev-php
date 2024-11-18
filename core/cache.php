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
