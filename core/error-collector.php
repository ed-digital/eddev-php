<?php

  class ErrorCollector {
    static $stack = [];

    static function push($id, $label) {
      self::$stack[] = (object)[
        "id" =>  $id,
        "label" => $label,
        "errors" => []
      ];
    }

    static function current() {
      return @self::$stack[count(self::$stack) - 1];
    }

    static function logError($message) {
      $ctx = self::current();
      $ctx->errors[] = $message;
    }

    static function pop() {
      $popped = array_pop(self::$stack);
      $ctx = self::current();
      if ($ctx) {
        $ctx->children[] = $popped;
      }
      return $popped;
    }
  }