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
