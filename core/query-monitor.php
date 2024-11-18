<?php

class QueryMonitor {
  static $stack = [];

  static $result = [];

  static function push($file, $label) {
    self::$stack[] = (object)[
      "file" =>  str_replace(ED()->themePath, "", $file),
      "label" => $label,
      "started" => microtime(true),
      "finished" => -1,
      "duration" => -1,
      "errors" => []
    ];
  }

  static function current() {
    return @self::$stack[count(self::$stack) - 1];
  }

  static function logError($message) {
    $ctx = self::current();
    if (!$ctx) return;
    $ctx->errors[] = $message;
  }

  static function add($item) {
    $ctx = self::current();
    if ($ctx) {
      $ctx->children[] = $item;
    } else {
      self::$result[] = $item;
    }
  }

  static function pop() {
    $popped = array_pop(self::$stack);
    $popped->finished = microtime(true);
    $popped->duration = floor(($popped->finished - $popped->started) * 1000);
    unset($popped->started);
    unset($popped->finished);
    self::add($popped);
    return $popped;
  }

  static function getResult() {
    return self::$result;
  }
}
