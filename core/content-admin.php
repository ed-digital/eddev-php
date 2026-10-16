<?php

namespace ED;

class ContentAdmin {

  const PARTS_PAGE = 'ed-template-parts';
  const PATTERNS_PAGE = 'edit.php?post_type=wp_block';

  static function setup() {
    add_action('admin_menu', [__CLASS__, 'registerMenus'], 99);
    add_action('enqueue_block_editor_assets', [__CLASS__, 'enqueueEditorNavigation']);
  }

  static function isEnabled() {
    return apply_filters('ed_content_admin_enabled', !wp_is_block_theme());
  }

  static function partsUrl() {
    return admin_url('themes.php?page=' . self::PARTS_PAGE);
  }

  static function registerMenus() {
    if (!self::isEnabled()) return;

    global $submenu;

    remove_submenu_page('themes.php', 'site-editor.php');
    remove_submenu_page('themes.php', 'site-editor.php?p=/pattern');
    remove_submenu_page('themes.php', 'font-library.php');
    remove_submenu_page('themes.php', 'font-library-wp-admin');

    // The Customize menu URL includes a return URL for the current screen.
    foreach ($submenu['themes.php'] ?? [] as $item) {
      if (wp_parse_url($item[2], PHP_URL_PATH) === 'customize.php') {
        remove_submenu_page('themes.php', $item[2]);
      }
    }

    add_submenu_page(
      'themes.php',
      __('Template Parts', 'eddev'),
      __('Template Parts', 'eddev'),
      'edit_theme_options',
      self::PARTS_PAGE,
      [__CLASS__, 'renderParts']
    );

    $patterns = get_post_type_object('wp_block');
    if ($patterns) {
      $patterns->show_in_menu = 'themes.php';
      add_submenu_page(
        'themes.php',
        $patterns->labels->name,
        $patterns->labels->name,
        $patterns->cap->edit_posts,
        self::PATTERNS_PAGE
      );
    }
  }

  static function partEditUrl($id) {
    return add_query_arg([
      'p' => '/wp_template_part/' . $id,
      'canvas' => 'edit',
    ], admin_url('site-editor.php'));
  }

  static function renderParts() {
    if (!current_user_can('edit_theme_options')) {
      wp_die(esc_html__('Sorry, you are not allowed to edit template parts.', 'eddev'), '', ['response' => 403]);
    }

    // Include theme-provided parts as well as saved customizations. Unsaved
    // eddev parts have composite IDs, not WordPress post IDs.
    $parts = get_block_templates([], 'wp_template_part');
    usort($parts, function ($a, $b) {
      return strnatcasecmp($a->title, $b->title);
    });
    $areas = [];
    foreach (get_allowed_block_template_part_areas() as $area) {
      $areas[$area['area']] = $area['label'];
    }
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Template Parts', 'eddev'); ?></h1>
      <hr class="wp-header-end">
      <table class="widefat fixed striped">
        <thead>
          <tr>
            <th scope="col"><?php esc_html_e('Title', 'eddev'); ?></th>
            <th scope="col"><?php esc_html_e('Area', 'eddev'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$parts): ?>
            <tr><td colspan="2"><?php esc_html_e('No template parts found.', 'eddev'); ?></td></tr>
          <?php endif; ?>
          <?php foreach ($parts as $part): ?>
            <tr>
              <td class="column-title column-primary">
                <strong><a class="row-title" href="<?php echo esc_url(self::partEditUrl($part->id)); ?>"><?php echo esc_html($part->title ?: $part->slug); ?></a></strong>
                <div class="row-actions">
                  <a href="<?php echo esc_url(self::partEditUrl($part->id)); ?>" aria-label="<?php echo esc_attr(sprintf(__('Edit %s', 'eddev'), $part->title ?: $part->slug)); ?>"><?php esc_html_e('Edit', 'eddev'); ?></a>
                </div>
              </td>
              <td><?php echo esc_html($areas[$part->area] ?? $part->area); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
  }

  static function enqueueEditorNavigation() {
    if (!self::isEnabled()) return;

    $screen = get_current_screen();
    if (!$screen || $screen->base !== 'site-editor') return;

    $path = isset($_GET['p']) && is_string($_GET['p']) ? wp_unslash($_GET['p']) : '';
    if (!preg_match('#^/(wp_template_part|wp_block)/(.+)$#', $path, $match)) return;

    $isPart = $match[1] === 'wp_template_part';
    $file = __DIR__ . '/content-admin.js';
    $url = content_url(str_replace(wp_normalize_path(WP_CONTENT_DIR), '', wp_normalize_path($file)));
    wp_enqueue_script('ed-content-admin', $url, [
      'wp-edit-post', 'wp-editor', 'wp-plugins', 'wp-element', 'wp-components', 'wp-data',
    ], filemtime($file), true);
    wp_add_inline_script('ed-content-admin', 'window.edContentAdmin = ' . wp_json_encode([
      'postType' => $match[1],
      'postId' => $match[2],
      'url' => $isPart ? self::partsUrl() : admin_url(self::PATTERNS_PAGE),
      'label' => $isPart ? __('Back to Template Parts', 'eddev') : __('Back to Patterns', 'eddev'),
    ]) . ';', 'before');
  }
}

ContentAdmin::setup();
