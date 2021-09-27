<?php

  function dump_as_string (...$args) {
    $str = '';
    foreach($args as $item) {
      if(is_array($item) || is_object($item)) {
        $str .= print_r($item, true);
      } else {
        $str .= json_encode($item);
      }
      $str .= " ";
    }
    return $str;
  }

  function dump(...$args) {
    if(error_reporting() === 0) return;

    echo "<pre># ";
    echo htmlentities(dump_as_string(...$args));
    echo "</pre>";
  }
