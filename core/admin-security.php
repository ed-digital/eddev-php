<?php

/**
 * This class is used to restrict access to certain admin features, especially developer-oriented ones.
 */
class EDSuperAdmin {

  static $username = 'ed_admin';
  static $adminMenuPatterns = [
    'graphiql',
    'acf-field-group',
  ];
  static $bannedCaps = [
    'edit_themes',
    'edit_plugins',
    'view_site_health_checks'
  ];

  static function init() {
    add_filter('user_has_cap', [__CLASS__, 'grantSuperAdmin'], 10, 3);
    add_action('admin_init', [__CLASS__, 'addAdminCaps']);
    add_action('admin_menu', [__CLASS__, 'removeDeveloperMenuItems'], 99999);
    add_action('wp_dashboard_setup', [__CLASS__, 'removeDashboardWidgets'], 99999);
    add_action('admin_bar_menu', [__CLASS__, 'removeAdminBarItems'], 99999);
  }

  static function removeDeveloperMenuItems() {
    if (self::isSuperAdmin()) return;
    global $menu;
    global $submenu;

    foreach ($menu as $key => $item) {
      $id = $item[2];
      foreach (self::$adminMenuPatterns as $pattern) {
        if (strpos($id, $pattern) !== false) {
          unset($menu[$key]);
        }
      }
    }

    foreach ($submenu as $key => $item) {
      foreach ($item as $subkey => $subitem) {
        $id = $subitem[2];
        foreach (self::$adminMenuPatterns as $pattern) {
          if (strpos($id, $pattern) !== false) {
            unset($submenu[$key][$subkey]);
          }
        }
      }
    }
  }

  static function removeDashboardWidgets() {
    remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
    remove_meta_box('dashboard_primary', 'dashboard', 'side');
    remove_meta_box('dashboard_activity', 'dashboard', 'normal');
    remove_meta_box('dashboard_right_now', 'dashboard', 'normal');
  }

  static function removeAdminBarItems($bar) {
    if (self::isSuperAdmin()) return;
    foreach ($bar->get_nodes() as $node) {
      $id = $node->id;
      foreach (self::$adminMenuPatterns as $pattern) {
        if (strpos($id, $pattern) !== false) {
          $bar->remove_node($id);
        }
      }
    }
  }

  static function addAdminCaps() {
    // Add the ed_admin role to the adminstrator role
    // It'll be removed at runtime if the user is not the configured super admin
    $role = get_role('administrator');
    $role->add_cap('ed_admin');
  }

  static function isSuperAdmin() {
    if (!current_user_can('administrator')) return false;
    $user = wp_get_current_user();
    if ($user->user_login === self::$username) return true;
    return false;
  }

  static function grantSuperAdmin($allcaps, $caps, $args) {
    unset($allcaps['ed_admin']);
    foreach (self::$bannedCaps as $cap) {
      unset($allcaps[$cap]);
    }
    if (isset($allcaps['manage_options']) && $allcaps['manage_options'] === true) {
      $user = wp_get_current_user();
      if ($user->user_login === self::$username) {
        $allcaps['ed_admin'] = true;
      }
    }
    return $allcaps;
  }

  static function setUser($username) {
    self::$username = $username;
  }

  static function filterAdminMenus($patterns) {
    self::$adminMenuPatterns = $patterns;
  }
}

EDSuperAdmin::init();
