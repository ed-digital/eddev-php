<?php

  class AssetManifest {
    static $manifest;
    static $ignore = false;
    static $manifestLoaded = false;
    static $mode = "frontend";

    // A dictionary of already-imported chunks... so we dont include them more than once
    static $imported = [];

    // A list of assets, in order of import
    static $assets = [];

    static function setup($ignore = false, $mode = "frontend") {
      if (self::$manifest) return;
      self::$mode = $mode;
      if ($ignore) {
        self::$ignore = true;
        return;
      }
      $path = ED()->themePath."/dist/".self::$mode."/.vite/manifest.json";
      if (file_exists($path)) {
        self::$manifest = json_decode(file_get_contents($path));
        self::$manifestLoaded = true;
      }
    }

    static function importChunk($asset, $rel = "main") {
      if (self::$ignore) return;
      if (isset(self::$imported[$asset])) return;
      self::$imported[$asset] = true;

      if (str_ends_with($asset, '.css')) {
        self::$assets[] = [
          'file' => ED()->themeURL."/dist/".self::$mode."/".$asset,
          'type' => 'style',
          'rel' => "preload"
        ];
        return;
      }

      $value = @self::$manifest->$asset;
      if (!$value) {
        return;
      }

      if (@is_array($value->imports)) {
        foreach ($value->imports as $childAsset) {
          self::importChunk($childAsset, $rel === "main" ? "preload" : $rel);
        }
      }
      if (@is_array($value->css)) {
        foreach ($value->css as $childAsset) {
          self::importChunk($childAsset, $rel === "main" ? "preload" : $rel);
        }
      }

      self::$assets[] = [
        'file' => ED()->themeURL."/dist/".self::$mode."/".$value->file,
        'type' => 'script',
        'rel' => $rel
      ];
    }

    static function collectTags() {
      if (self::$ignore) return '';
      $output = [];

      $assets = self::$assets;

      foreach ($assets as $asset) {
        if ($asset['type'] == 'style') {
          $output[] = '<link rel="stylesheet" type="text/css" media="all" href="' . $asset['file'] . '">';
        } else {
          $output[] = '<link rel="preload" crossOrigin="anonymous" href="' . $asset['file'] . '" ' . ($asset['type'] ? 'as="'.$asset['type'].'"' : ''). '/>';
        }
      }

      return "\n" . implode("\n", $output) . "\n";
    }

    static function collectMainTag() {
      if (self::$ignore) return '';
      $assets = self::$assets;

      foreach ($assets as $asset) {
        if ($asset['type'] === 'script') {
          return '<script defer type="module" src="' . $asset['file'] . '"></script>';
        }
      }

      return "";
    }

    static function getEntryScript() {
      if (self::$ignore) return '';
      $assets = self::$assets;

      foreach ($assets as $asset) {
        if ($asset['type'] === 'script') {
          return $asset['file'];
        }
      }
    }
    
  }