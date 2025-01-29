<?php

namespace ED;

use EDTemplates;

class AdminAssets {

  static $isBlockEditor = false;
  static $enabled = false;

  static function init() {
    add_action('current_screen', function ($screen) {
      self::$isBlockEditor = self::screenIsBlockEditor($screen);
      if (self::$isBlockEditor) {
        self::$enabled = true;
      } else {
        self::$enabled = apply_filters('ed_should_enqueue_admin_scripts', self::$enabled, $screen);
      }
    });

    if (is_admin()) {
      add_action('enqueue_block_assets', function () {
        if (self::$enabled) {
          self::enqueueAdminScripts();
        }
      });

      add_action('enqueue_block_editor_assets', function () {
        if (self::$enabled) {
          self::enqueueAdminScripts();
        }
      });

      add_action('admin_enqueue_scripts', function () {
        if (self::$enabled) {
          self::enqueueAdminScripts();
        }
      });
    }

    if (ED()->isDevProxy()) {
      add_action('admin_head', function () {
        if (self::$enabled) {
          // Add Vite HMR info
          echo "<!---VITE_HEADER--->";
        }
      });

      add_action('admin_footer', function () {
        if (self::$enabled) {
          echo "<!---VITE_FOOTER--->";
        }
      });
    }

    // add_filter('script_loader_tag', function ($tag, $handle, $src) {
    //   if ($handle === "theme_admin_js") {
    //     return '<script type="module" src="' . $src . '"></script>';
    //   }
    //   return $tag;
    // }, 10, 3);

    // Add the output of _app.graphql to the admin page, in the block editor
    add_action('wp_print_scripts', function () {
      if (!self::$isBlockEditor) return;
      $data = EDTemplates::getAppQueryData();
      echo "<script>window.__ED_APP_DATA = " . json_encode($data) . "</script>";
    });

    /**
     * Swap to using React development versions in WordPress admin, which provides better debug messages.
     */
    // Replace `react.min.js` with `react.js` and `react-dom.min.js` with `react-dom.js`
    add_filter("script_loader_src", function ($src, $handle) {
      if (($handle === "react-dom" || $handle === "react")) {
        // Gutenberg 13.0+
        return preg_replace("/\.min\./", ".", $src);
      }
      return $src;
    }, 2, 2);
    // Needed for blocks to render correctly when React development mode is enabled
    add_action('acf/input/admin_footer', function () {
      echo "<script>if (window.acf && window.acf.data) { acf.data.StrictMode = true }</script>";
    });
  }

  static function screenIsBlockEditor(\WP_Screen $screen) {
    if (method_exists($screen, 'is_block_editor') && $screen->is_block_editor()) return true;
    if (isset($screen->is_block_editor) && $screen->is_block_editor === true) return true;
    if (isset($screen->base) && $screen->base === "site-editor") return true;
    return false;
  }

  static function getDeps() {
    $baseDeps = ['acf', 'react', 'react-dom', 'wp-hooks'];
    $blockDeps = ['wp-blocks', 'wp-editor', 'wp-edit-post', 'wp-dom-ready', 'acf-blocks'];
    if (self::$isBlockEditor) {
      return array_merge($baseDeps, $blockDeps);
    }
    return $baseDeps;
  }

  static function enqueueAdminScripts() {
    $deps = self::getDeps();

    if (ED()->isDevProxy()) {
      // If the dev proxy is currently being used, just enqueue the deps
      foreach ($deps as $dep) {
        wp_enqueue_script($dep);
      }

      add_action('wp_print_scripts', function () {
        // Add Vite HMR info
        echo "<script id='vite-test-header'></script><script id='vite-iframe-header'></script>";
        // echo "<template id='eddev-admin-iframe-head'>\n<!---VITE_HEADER--->\n</template>";
      });

      add_action('wp_print_footer_scripts', function () {
        echo "<script id='vite-test-footer'></script><script id='vite-iframe-footer'></script>";
        // echo "<template id='eddev-admin-iframe-footer'>\n<!---VITE_HEADER--->\n</template>";
      });
    } else {
      AssetManifest::setup(false, "cms");
      AssetManifest::importChunk(".eddev/dev-spa/entry.admin.tsx", 'main');
      $adminEntry = AssetManifest::getEntryScript();
      if (!$adminEntry) {
        AssetManifest::importChunk(".eddev/prod-spa/entry.admin.tsx", 'main');
        $adminEntry = AssetManifest::getEntryScript();
      }
      AssetManifest::importChunk("style.css");

      AssetManifest::enqueue($deps);
    }
  }
}
