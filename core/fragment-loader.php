<?php

  class FragmentLoader {

    static $cache;

    static function getAll() {
      if (self::$cache) return self::$cache;
      $fragments = glob(ED()->themePath."/queries/fragments/*.graphql");
      $output = [];
      foreach ($fragments as $frag) {
        $output[] = file_get_contents($frag);
      }
      return "\n\n".implode("\n\n", $output);
    }

  }