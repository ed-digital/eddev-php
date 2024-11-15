<?php

class SlimSEOIntegration {
  static function init() {
    add_action('slim_seo_init', function ($plugin) {
      $plugin->disable('breadcrumbs');
      $plugin->disable('schema');
      $plugin->disable('notifications');
      $plugin->disable('feed');
      $plugin->disable('settings_term');
    });
  }
}
SlimSEOIntegration::init();
