<?php

class SlimSEOIntegration {
  static function init() {
    add_action('slim_seo_init', function ($plugin) {
      $plugin->disable('breadcrumbs');
      $plugin->disable('notifications');
      $plugin->disable('feed');
      $plugin->disable('settings_term');
      if (!is_admin()) {
        $plugin->disable('code');
      }
    });

    add_filter('slim_seo_post_content', function ($content, $post) {
      $excerpt = get_the_excerpt($post);
      return $excerpt;
    }, 10, 2);

    add_filter("slim_seo_schema_author_enable", '__return_false');
    add_filter('slim_seo_meta_author', '__return_false');
    add_filter('slim_seo_linkedin_author', '__return_false');

    add_action('ed_print_trackers_head', function () {
      $result = get_option('slim_seo');
      if ($result['header_code']) {
        echo $result['header_code'];
      }
    });

    add_action('ed_print_trackers_body', function () {
      $result = get_option('slim_seo');
      if ($result['body_code']) {
        echo $result['body_code'];
      }
    });

    add_action('ed_print_trackers_footer', function () {
      $result = get_option('slim_seo');
      if ($result['footer_code']) {
        echo $result['footer_code'];
      }
    });
  }
}

SlimSEOIntegration::init();
