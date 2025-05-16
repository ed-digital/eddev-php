<?php

class QueryMonitor {
  static $stack = [];
  static $result = [];

  static function setup() {
    // Potentially deprecate along with the Console class
    add_action('eddev_console_entry', function (EDConsoleEntry $entry) {
      $item = self::current();
      if (!$item) return;
      $item->log[] = [
        "type" => $entry->type,
        "data" => $entry->args,
        "stack" => $entry->trace
      ];
    }, 10, 1);

    add_action('graphql_before_execute', function () {
      set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        if (!(error_reporting() & $errno)) {
          return;
        }
        $type = "Unknown";
        switch ($errno) {
          case E_WARNING:
            $type = "Warning";
            break;
          case E_NOTICE:
            $type = "Notice";
            break;
          case E_ERROR:
            $type = "Error";
            break;
        }
        $item = self::current();
        if (!$item) return false;
        $item->log[] = [
          "type" => "php_" . strtolower($type),
          "message" => $errstr,
          "file" => str_replace(ED()->themePath, '.', $errfile),
          "line" => $errline,
          "stack" => self::getBacktrace()
        ];
      }, E_WARNING | E_ERROR | E_NOTICE);
    });

    add_action('graphql_after_execute', function () {
      restore_error_handler();
    });

    // Capture any errors that occur during a GraphQL execution
    add_action('graphql_return_response', function ($filtered_response) {
      $item = self::current();
      if (!$item) return;
      if (isset($filtered_response['errors']) && is_array($filtered_response['errors'])) {
        foreach ($filtered_response['errors'] as $err) {
          $item->log[] = [
            "type" => "error",
            ...$err
          ];
        }
      }
    }, 10, 1);

    // After each GraphQL request, add the debug log to the current query monitor context, then remove all log items
    // We do this to allow nested GraphQL queries, without duplicating log entries
    add_filter('graphql_debug_log', function ($log) {
      // Add log entries
      if (!empty($log)) {
        $item = self::current();
        if ($item) {
          foreach ($log as $logItem) {
            if (isset($logItem['stack'])) {
              $logItem['stack'] = self::cleanBacktrace($logItem['stack']);
            }
            $item->log[] = $logItem;
          }
        }
      }
      // Unregister the current log entries
      remove_all_actions('graphql_get_debug_log');
      // Return the current log, unmodified
      return $log;
    }, 10000, 1);
  }

  private static function cleanBacktrace($stack) {
    $result = [];
    foreach ($stack as $item) {
      if (!preg_match("/(webonyx\/graphql-php|wp\-graphql\/wp-graphql)/", $item)) {
        $item = str_replace(ED()->themePath, ".", $item);
        $result[] = $item;
      }
    }
    return $result;
  }

  private static function getBacktrace() {
    $trace = debug_backtrace(); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
    $trace = !empty($trace)
      ?
      array_values(
        array_map(
          static function ($trace) {
            $line = isset($trace['line']) ? absint($trace['line']) : 0;
            return sprintf('%s:%d', $trace['file'], $line);
          },
          array_filter( // Filter out steps without files
            $trace,
            static function ($item) {
              return !empty($item['file']) && $item['file'] !== __FILE__;
            }
          )
        )
      )
      :
      [];
    $trace = self::cleanBacktrace($trace);
    return $trace;
  }

  static function push($file, $label) {
    self::$stack[] = (object)[
      "file" =>  str_replace(ED()->themePath, "", $file),
      "label" => $label,
      "started" => microtime(true),
      "finished" => -1,
      "duration" => -1,
      "log" => []
    ];
  }

  static function current() {
    return @self::$stack[count(self::$stack) - 1];
  }

  static function logNativeError($err) {
    $ctx = self::current();
    if (!$ctx) return;
    $ctx->log[] = [
      "type" => "error",
      $err
    ];
  }

  static function logDebug($type, $msg) {
    $ctx = self::current();
    if (!$ctx) return;
    $ctx->log[] = [
      "type" => "debug",
      "message" => $msg
    ];
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
    $popped->duration = $popped->finished - $popped->started;
    unset($popped->started);
    unset($popped->finished);
    self::add($popped);
    return $popped;
  }

  static function getResult() {
    return self::$result;
  }
}

QueryMonitor::setup();
