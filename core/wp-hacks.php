<?php

class EDWPHacks {

  static function apply() {
    self::disable_comments();
    self::disable_emojis();
    self::disable_xml_rpc();
    self::disable_user_enum();
  }

  static function disable_xml_rpc() {
    if (defined('XMLRPC_REQUEST') && constant('XMLRPC_REQUEST') === true) {
      status_header(403);
      exit;
    }

    add_action('init', [__CLASS__, '_disable_all_feeds']);
  }

  static function _disable_all_feeds() {
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wlwmanifest_link');
    remove_action('wp_head', 'feed_links', 2);
    remove_action('wp_head', 'feed_links_extra', 3);
    remove_action('wp_head', 'rest_output_link_wp_head');
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('template_redirect', 'rest_output_link_header');
    remove_action('wp_head', 'wp_generator');
    remove_action('wp_head', 'wp_shortlink_wp_head');

    $feeds = array(
      'do_feed',
      'do_feed_rdf',
      'do_feed_rss',
      'do_feed_rss2',
      'do_feed_atom',
    );

    foreach ($feeds as $feed) {
      remove_action($feed, $feed);
    }
  }

  static function disable_comments() {
    add_action('admin_init', function () {
      // Redirect any user trying to access comments page
      global $pagenow;

      if ($pagenow === 'edit-comments.php' || $pagenow === 'options-discussion.php') {
        wp_redirect(admin_url());
        exit;
      }

      // Remove comments metabox from dashboard
      remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');

      // Disable support for comments and trackbacks in post types
      foreach (get_post_types() as $post_type) {
        if (post_type_supports($post_type, 'comments')) {
          remove_post_type_support($post_type, 'comments');
          remove_post_type_support($post_type, 'trackbacks');
        }
      }
    });

    // Close comments on the front-end
    add_filter('comments_open', '__return_false', 20, 2);
    add_filter('pings_open', '__return_false', 20, 2);

    // Hide existing comments
    add_filter('comments_array', '__return_empty_array', 10, 2);

    // Remove comments page in menu
    add_action('admin_menu', function () {
      remove_menu_page('edit-comments.php');
      remove_submenu_page('options-general.php', 'options-discussion.php');
    });

    // Remove comments links from admin bar
    add_action('init', function () {
      if (is_admin_bar_showing()) {
        remove_action('admin_bar_menu', 'wp_admin_bar_comments_menu', 60);
      }
    });

    // Remove comments icon from admin bar
    add_action('wp_before_admin_bar_render', function () {
      global $wp_admin_bar;
      $wp_admin_bar->remove_menu('comments');
      $wp_admin_bar->remove_menu('customize');
    });

    // Return a comment count of zero to hide existing comment entry link.
    function zero_comment_count($count) {
      return 0;
    }
    add_filter('get_comments_number', 'zero_comment_count');

    // Multisite - Remove manage comments from admin bar
    add_action('admin_bar_menu', 'remove_toolbar_items', PHP_INT_MAX - 1);
    function remove_toolbar_items($bar) {
      $sites = get_blogs_of_user(get_current_user_id());
      foreach ($sites as $site) {
        $bar->remove_node("blog-{$site->userblog_id}-c");
      }
    }
  }

  static function disable_emojis() {
    add_action('init', [__CLASS__, '_disable_emojis']);
  }

  static function _disable_emojis() {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
  }

  static function disable_user_enum() {
    add_filter('query_vars', function ($public_query_vars) {
      if (is_admin()) return $public_query_vars;
      foreach (['author', 'author_name'] as $var) {
        $key = array_search($var, $public_query_vars);
        if (false !== $key) {
          unset($public_query_vars[$key]);
        }
      }
      return $public_query_vars;
    });
  }
}
