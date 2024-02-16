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

    static function setup($ignore = false) {
      if (self::$manifest) return;
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
      if (self::$imported[$asset]) return;
      self::$imported[$asset] = true;

      $value = @self::$manifest->$asset;
      if (!$value) {
        return;
      }
      
      if (is_array($value->imports)) {
        foreach ($value->imports as $childAsset) {
          self::importChunk($childAsset, $rel === "main" ? "preload" : $rel);
        }
      }

      self::$assets[] = [
        'file' => ED()->themeURL."/dist/".self::$mode."/".$value->file,
        'type' => str_ends_with($value->file, '.css') ? 'style' : 'script',
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
        return '<script defer type="module" src="' . $asset['file'] . '"></script>';
      }

      return "";
    }
    
  }