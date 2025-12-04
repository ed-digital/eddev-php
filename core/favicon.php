<?php

// ed_dump("Loading favicon.php");
// exit;
class EDFavicon {
  static function setup() {
    add_action('do_faviconico', function () {
      $favicon = self::find([
        "/favicon.ico",
        "/assets/favicon-out/favicon.ico",
      ]);
      if ($favicon) {
        header("Content-type: image/x-icon");
        readfile($favicon);
        exit;
      }
    });

    add_action('wp_head', function () {
      echo implode("\n", self::printFaviconTags());
    });
  }

  static function find($paths) {
    foreach ($paths as $path) {
      $path = preg_replace("/^\//", "", $path);
      if (file_exists(ED()->themePath . "/" . $path)) {
        return str_replace(ED()->sitePath, "", ED()->themePath) . "/" . $path;
      }
    }
    return null;
  }

  static function printFaviconTags() {
    $lines = [];

    $config = ED()->getConfig('favicon');

    // SVG icon
    $svgFavicon = self::find([
      "/assets/favicon-out/favicon.svg",
      "/favicon.svg",
      $config['mode'] === 'svg' ? $config['default'] ?? "/assets/favicon/favicon.svg" : null,
    ]);
    if ($svgFavicon) {
      $lines[] = "<link rel=\"icon\" href=\"" . esc_attr($svgFavicon) . "\" />";
    }

    // Regular PNG icons
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
    if (file_exists(ED()->themePath . "/assets/favicon/120x120.png")) {
      $path = str_replace(ED()->siteURL, "", ED()->themeURL) . "/assets/favicon/";
      foreach ($icons as $rel => $names) {
        foreach ($names as $name) {
          @preg_match("/[0-9]+x[0-9]+/", $name, $match);
          $size = @$match[0];
          $url = $path . $name . ".png";
          $lines[] = "<link rel=\"{$rel}\" href=\"{$url}\" sizes=\"{$size}\">";
        }
      }
    }
    return $lines;
  }
}

EDFavicon::setup();
