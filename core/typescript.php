<?php

class EDTypeScriptRegistry {
  static $types = [];
  static $typescriptImports = [];
  static $postMetaTypes = [];

  static function registerType($typeName, $type, $importStatement = null) {
    self::$types[$typeName] = $type;
    if ($importStatement) {
      self::$typescriptImports[] = $importStatement;
    }
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

  static function getTypeImports() {
    return self::$typescriptImports;
  }

  static function getPostMeta() {
    return self::$postMetaTypes;
  }
}
