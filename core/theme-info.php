<?php

  /**
   * Returns manifest data generated by the CLI
   */
  class EDThemeInfo {
    static $info = null;
    static function load() {
      if (self::$info) {
        return self::$info;
      } else {
        $themeInfoFile = ED()->themePath."/ed.dist.json";
        if (!file_exists($themeInfoFile)) return [
          'blocks' => [],
          'templates' => []
        ];
        $themeInfoFile = json_decode(file_get_contents($themeInfoFile), true);
        self::$info = $themeInfoFile;
        return $themeInfoFile;
      }
    }
  }