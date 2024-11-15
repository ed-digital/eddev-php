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

    add_filter('slim_seo_post_content', function ($content, $post) {
      $extractor = new EDContentExtractor([
        'post' => $post,
        'blocks' => parse_blocks($content)
      ]);
      $extractor->process();
      return $extractor->getExcerpt();
    }, 10, 2);
  }
}
SlimSEOIntegration::init();
