<?php

class EDTrackers {

  protected static $loaded = false;
  protected static $trackers = [];

  public static function init() {
    if (!isset($_GET['_props'])) {
      add_action('wp_head', [__CLASS__, 'emitHead']);
      add_action('wp_body_open', [__CLASS__, 'emitBody']);
      add_action('wp_footer', [__CLASS__, 'emitFooter']);
    }
  }

  public static function loadTrackers() {
    if (self::$loaded) self::$trackers;

    $config = ED()->getConfig();
    if (isset($config['trackers']) && is_array($config['trackers'])) {
      foreach ($config['trackers'] as $tracker) {
        self::install($tracker);
      }
    }
  }

  static function printTracker($tracker, $location) {
    $provider = $tracker['provider'];

    /**
     * Google Tag Manager
     */
    if ($provider === "gtm") {
      $id = $tracker['id'];
      if ($location === "head") { ?>
        <!-- Google Tag Manager [ED] -->
        <script>
          (function(w, d, s, l, i) {
            w[l] = w[l] || [];
            w[l].push({
              'gtm.start': new Date().getTime(),
              event: 'gtm.js'
            });
            var f = d.getElementsByTagName(s)[0],
              j = d.createElement(s),
              dl = l != 'dataLayer' ? '&l=' + l : '';
            j.async = true;
            j.src =
              'https://www.googletagmanager.com/gtm.js?id=' + i + dl;
            f.parentNode.insertBefore(j, f);
          })(window, document, 'script', 'dataLayer', <?= json_encode($id) ?>);
        </script>
        <!-- End Google Tag Manager [ED] -->
      <?php
      } else if ($location === "body") { ?>
        <!-- Google Tag Manager (noscript) [ED] -->
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?= $id ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
        <!-- End Google Tag Manager (noscript) [ED] -->
      <?php
      }
    } else if ($provider === "ga4") {
      $id = $tracker['id'];
      if ($location === "head") { ?>
        <!-- Google tag (gtag.js) [ED] -->
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?= $id ?>"></script>
        <script>
          window.dataLayer = window.dataLayer || [];

          function gtag() {
            dataLayer.push(arguments);
          }
          gtag('js', new Date());
          gtag('config', '<?= $id ?>');
        </script>
        <!-- End Google tag (gtag.js) [ED] -->
<?php
      }
    }
  }

  static function install($tracker) {
    self::$trackers[] = $tracker;
  }

  static function emitHead() {
    self::loadTrackers();
    foreach (self::$trackers as $tracker) {
      self::printTracker($tracker, "head");
    }
    do_action('ed_print_trackers_head');
  }

  static function emitBody() {
    self::loadTrackers();
    foreach (self::$trackers as $tracker) {
      self::printTracker($tracker, "body");
    }
    do_action('ed_print_trackers_body');
  }

  static function emitFooter() {
    self::loadTrackers();
    foreach (self::$trackers as $tracker) {
      self::printTracker($tracker, "footer");
    }
    do_action('ed_print_trackers_footer');
  }

  static function collectAll() {
    $locations = ['head', 'body', 'footer'];
    self::loadTrackers();

    $result = [];

    foreach ($locations as $location) {
      ob_start();
      foreach (self::$trackers as $tracker) {
        self::printTracker($tracker, $location);
      }
      do_action('ed_print_trackers_' . $location);
      $contents = ob_get_contents();
      ob_end_clean();
      $result[$location] = $contents;
    }

    return $result;
  }
}
