<?php

class EDTypeScriptRegistry {
  static $types = [];
  static $postMetaTypes = [];

  static function registerType($typeName, $type) {
    self::$types[$typeName] = $type;
  }

  static function registerPostMeta($key, $isScalar, $typeName) {
    foreach (self::$postMetaTypes as $meta) {
      if ($meta['key'] === $key && $meta['isScalar'] === $isScalar && $meta['typeName'] === $typeName) {
        return;
      }
    }
    self::$postMetaTypes[] = [
      'key' => $key,
      'isScalar' => $isScalar,
      'typeName' => $typeName
    ];
  }

  static function getTypes() {
    return self::$types;
  }

  static function getPostMeta() {
    return self::$postMetaTypes;
  }
}
