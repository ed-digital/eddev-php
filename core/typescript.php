<?php

  class EDTypeScriptRegistry {
    static $types = [];
    static $postMetaTypes = [];

    static function registerType($typeName, $type) {
      self::$types[$typeName] = $type;
    }

    static function registerPostMeta($key, $typeName) {
      self::$postMetaTypes[] = [
        'key' => $key,
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