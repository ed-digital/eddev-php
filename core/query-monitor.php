<?php

use GraphQL\Type\Definition\ResolveInfo;

class QueryMonitor {
  static $stack = [];
  static $result = [];

  static ResolveInfo|null $currentResolveInfo = null;

  static function setup() {
    // Potentially deprecate along with the Console class
    add_action('eddev_console_entry', function (EDConsoleEntry $entry) {
      $item = self::current();
      if (!$item) return;
      $item->log[] = [
        "type" => $entry->type,
        "message" => $entry->args,
        ...self::captureFieldInfo(),
        "trace" => $entry->trace
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
          ...self::captureFieldInfo(),
          "message" => $errstr,
          "file" => str_replace(ED()->themePath, '.', $errfile),
          "line" => $errline,
          "kind" => "error_handler",
          "trace" => self::getBacktrace()
        ];
      }, E_WARNING | E_ERROR | E_NOTICE);
    });

    add_action('graphql_after_execute', function () {
      restore_error_handler();
    });

    add_filter('graphql_before_resolve_field', function ($source, $args, $context, $info, $field_resolver, $type_name, $field_key, $field) {
      self::$currentResolveInfo = $info;
      // if ($type_name . "." . $field_key === "CaseStudy_Info.aspect") {
      //   // ed_dump("Resolving field:", $type_name . "." . $field_key);
      //   // var_dump($info->path);
      //   $info = [
      //     'path'           => $info->path,
      //     'parentType'     => $info->parentType->name,
      //     'fieldName'      => $info->fieldName,
      //     'returnType'     => $info->returnType->name ? $info->returnType->name : $info->returnType,
      //   ];
      // }
    }, 10, 8);

    // Capture any errors that occur during a GraphQL execution
    add_action('graphql_return_response', function ($filtered_response) {
      $item = self::current();
      if (!$item) return;
      if (isset($filtered_response['errors']) && is_array($filtered_response['errors'])) {
        foreach ($filtered_response['errors'] as $err) {
          $item->log[] = [
            "type" => "error",
            "kind" => "graphql_return_response",
            ...self::captureFieldInfo(),
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
            if (isset($logItem['trace'])) {
              $logItem['trace'] = self::cleanBacktrace($logItem['trace']);
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

  private static function cleanBacktrace($trace) {
    $result = [];
    foreach ($trace as $item) {
      if (!preg_match("/(webonyx\/graphql-php|wp\-graphql\/wp-graphql)/", $item)) {
        $item = str_replace(ED()->themePath, ".", $item);
        $result[] = $item;
      }
    }
    return $result;
  }

  static function getBacktrace() {
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

  static function captureFieldInfo() {
    $info = self::$currentResolveInfo;
    if (!$info) {
      return [];
    } else {
      return [
        'path' => $info->path,
        'parentType' => $info->parentType->name,
        'fieldName' => $info->fieldName,
        // 'returnType' => $info->returnType->name ? $info->returnType->name : $info->returnType,
      ];
    }
  }

  static function logNativeError($err) {
    $ctx = self::current();
    if (!$ctx) return;
    $ctx->log[] = [
      "type" => "error",
      ...self::captureFieldInfo(),
      ...$err
    ];
  }

  static function logDebug($type, $msg) {
    $ctx = self::current();
    if (!$ctx) return;
    $ctx->log[] = [
      "type" => "debug",
      ...self::captureFieldInfo(),
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
