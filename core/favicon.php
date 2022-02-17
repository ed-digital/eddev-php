<?php

  class EDFavicon {
    static function setup() {
      add_action('do_faviconico', function() {
        $path = ED()->themePath."/favicon.ico";
        if (file_exists($path)) {
          header("Content-type: image/x-icon");
          readfile($path);
          exit;
        }
      });

      add_action('wp_head', function() {
        echo implode("\n", self::printFaviconTags($favs));
      });
    }

    static function printFaviconTags() {
      $icons = [
        'icon' => [
          '120x120',
          '128x128',
          '196x196',
          'favicon-16x16',
          'favicon-32x32',
          'favicon-96x96'
        ],
        'apple-touch-icon' => [
          'apple-touch-icon-120x120',
          'apple-touch-icon-120x120',
          'apple-touch-icon-152x152',
          'apple-touch-icon-167x167',
          'apple-touch-icon-180x180'
        ],
        'shortcut icon' => [
          '196x196'
        ]
      ];

      $path = ED()->themeURL."/assets/favicon/";
      $lines = [];
      foreach ($icons as $rel => $names) {
        foreach ($names as $name) {
          @preg_match("/[0-9]+x[0-9]+/", $name, $match);
          $size = @$match[0];
          $url = $path.$name;
          $lines[] = "<link rel=\"{$rel}\" href=\"{$url}\" sizes=\"{$size}\">";
        }
      }
      return $lines;
    }
  }

  EDFavicon::setup();