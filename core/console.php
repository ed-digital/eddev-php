<?php

class EDConsoleEntry {
  public $trace = [];
  public function __construct(
    public string $type,
    public array $args
  ) {
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 0);
    foreach ($backtrace as $item) {
      if ($item['file'] === __FILE__ || preg_match("/(webonyx\/graphql-php|wp\-graphql\/wp-graphql)/", $item['file'])) continue;
      $this->trace[] = [
        'file' => str_replace(ED()->themePath, '', $item['file']),
        'line' => @$item['line'],
        'function' => @$item['function'],
        'type' => @$item['type']
      ];
    }
  }
}

class Console {

  private static $stack = [];
  private static $top = null;

  static function push($echo = 1, $collect = false) {
    self::$top = (object)[
      "items" => [],
      "echo" => $echo,
      "collect" => $collect
    ];
    self::$stack[] = self::$top;
  }

  static function pop() {
    $current = self::$top;
    array_pop(self::$stack);
    self::$top = end(self::$stack);
    return $current;
  }

  static function debug(...$args) {
    self::emit('debug', $args);
  }

  static function log(...$args) {
    self::emit('log', $args);
  }

  static function warn(...$args) {
    self::emit('warn', $args);
  }

  static function error(...$args) {
    self::emit('error', $args);
  }

  private static function emit($type, $args) {
    $entry = new EDConsoleEntry($type, $args);
    if (self::$top) {
      if (self::$top->echo === 1) {
        echo "<pre>";
        echo "<strong>$type</strong>: ";
        foreach ($args as $item) {
          if (is_array($item) || is_object($item)) {
            print_r($item);
          } else {
            echo json_encode($item);
          }
          echo " ";
        }
        echo "</pre>";
      }
      if (self::$top->collect) {
        self::$top->items[] = $entry;
      }
    }
    do_action('eddev_console_entry', $entry);
  }
}

function dump_as_string(...$args) {
  $str = '';
  foreach ($args as $item) {
    if (is_array($item) || is_object($item)) {
      $str .= print_r($item, true);
    } else {
      $str .= json_encode($item);
    }
    $str .= " ";
  }
  return $str;
}

if (!function_exists('dump')) {
  function dump(...$args) {
    if (error_reporting() === 0) return;

    echo "\n<pre># ";
    echo htmlentities(dump_as_string(...$args));
    echo "</pre>\n\n";
  }
}

function ed_dump(...$args) {
  if (error_reporting() === 0) return;

  echo "\n<pre># ";
  echo htmlentities(dump_as_string(...$args));
  echo "</pre>\n\n";
}
