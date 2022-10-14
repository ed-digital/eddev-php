<?php

  class QueryMonitor {
    static $stack = [];

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
      $ctx->errors[] = $message;
    }

    static function pop() {
      $popped = array_pop(self::$stack);
      $popped->finished = microtime(true);
      $popped->duration = $popped->finished - $popped->started;
      unset($popped->start);
      unset($popped->finished);
      $ctx = self::current();
      if ($ctx) {
        $ctx->children[] = $popped;
      }
      return $popped;
    }
  }