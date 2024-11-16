<?php

namespace ED;

class OriginProtection {

  protected static $checkAPIKey = false;
  protected static $checkBasicAuth = false;
  protected static $enabled = false;

  static function isAPIKeyRequired() {
    return !!ED()->getConfig("serverless.originProtection.requireLogin") || !!get_option('ed_force_api_key_required') || self::isPasswordProtectionEnabled();
  }

  static function isPasswordProtectionEnabled() {
    return !!get_option('ed_basic_auth_enabled', false);
  }

  static function shouldUseFullSecurity() {
    if (ED()->isLocalDev()) {
      // When developing locally, allow full access by default, but allow DEBUG_FULL_SECURITY to enforce full security mode
      $value = ED()->readEnvValue("DEBUG_FULL_SECURITY");
      if ($value == 'true' || $value == '1') {
        return true;
      }
      return false;
    }
    return true;
  }

  static function init() {
    if (self::shouldUseFullSecurity()) {
      if (self::isPasswordProtectionEnabled()) {
        self::$checkBasicAuth = true;
      }
      if (self::isAPIKeyRequired()) {
        self::$checkAPIKey = true;
      }
      if (self::$checkAPIKey || self::$checkBasicAuth) {
        self::enable();
      }
    }

    OriginProtectionSettingsPage::init();
  }

  static function enable() {
    self::$enabled = true;

    // Prevent access to the frontend if the user is not logged in
    add_action('template_redirect', [__CLASS__, '_template_redirect']);

    // Prevent access to the GraphQL endpoint
    add_action('graphql_process_http_request', [__CLASS__, '_graphql_process_http_request']);

    // Prevent access to the REST API
    add_filter('rest_authentication_errors', [__CLASS__, '_rest_authentication_errors']);
  }

  static function _template_redirect() {
    if (!self::isAuthorized()) {
      if (self::$checkBasicAuth) {
        self::requestBasicAuth();
      } else {
        header('HTTP/1.0 401 Unauthorized');
      }
      self::showDeniedPage();
      exit;
    }
  }

  static function showDeniedPage() {
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
      <meta charset="UTF-8">
      <meta http-equiv="X-UA-Compatible" content="IE=edge">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Access Denied</title>
      <style>
        body {
          font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
          margin: 0;
          padding: 0;
          background-color: #f1f1f1;
          display: flex;
          justify-content: center;
          align-items: center;
          height: 100vh;
          flex-direction: column;
          color: #333333;
        }

        h1 {
          font-size: 1em;
          margin: 0;
          padding: 0;
        }

        p {
          font-size: 1em;
          margin: 0;
          padding: 0;
        }

        .container {
          background-color: #fff;
          padding: 16px;
          border-radius: 4px;
          /* box-shadow: 0 0 2px rgba(0, 0, 0, 0.1); */
          border: 1px solid rgba(0, 0, 0, 0.15);
          display: flex;
          flex-direction: column;
          gap: 8px;
        }
      </style>
    </head>

    <body>
      <div class="container">
        <h1>Access Denied</h1>
        <p>You are not authorized to access this page.</p>
      </div>
    </body>

    </html>
  <?php
  }

  static function _graphql_process_http_request() {
    if (!self::isAuthorized()) {
      $response = json_encode([
        "errors" => [
          [
            "message" => "Unauthorized",
          ]
        ]
      ]);
      status_header(401, 'Unauthorized');
      header('Content-Type: application/json');
      echo $response;
      exit;
    }
  }

  static function _rest_authentication_errors($result) {
    // If a previous authentication check was applied,
    // pass that result along without modification.
    if (true === $result || is_wp_error($result)) {
      return $result;
    }

    // No authentication has been performed yet.
    // Return an error if user is not logged in.
    if (!self::isAuthorized()) {
      return new \WP_Error(
        'rest_not_logged_in',
        __('You are not currently logged in.'),
        array('status' => 401)
      );
    }

    // Our custom authentication check should have no effect
    // on logged-in requests
    return $result;
  }

  /**
   * When origin protection mode is enabled, this will return true if the visitor is authorized to access the origin.
   * A true value does not mean that they have any specic permissions, only that they are allowed to access the origin.
   */
  static function isAuthorized() {
    if (!self::$enabled) {
      return true;
    }

    if (is_user_logged_in()) return true;

    if (isset($_SERVER['HTTP_X_ED_API_KEY'])) {
      $apiKey = $_SERVER['HTTP_X_ED_API_KEY'];
      if (self::verifyApiKey($apiKey)) return true;
    }

    if (self::$checkBasicAuth) {
      $username = get_option('ed_basic_auth_username', '');
      $password = get_option('ed_basic_auth_password', '');
      if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
        if ($_SERVER['PHP_AUTH_USER'] === $username && $_SERVER['PHP_AUTH_PW'] === $password) {
          return true;
        }
      }
    }

    return false;
  }

  static function requestBasicAuth() {
    header('WWW-Authenticate: Basic realm="Restricted Area"');
    header('HTTP/1.0 401 Unauthorized');
  }

  static function verifyApiKey($apiKey) {
    $availableKeys = get_option('ed_origin_api_key', '');
    if (!is_array($availableKeys)) return false;
    foreach ($availableKeys as $key) {
      if ($key['value'] === $apiKey) return true;
    }
    return false;
  }

  static function generateApiKey() {
    return 'ed_' . bin2hex(random_bytes(16));
  }

  static function addApiKey($apiKey) {
    $values = get_option('ed_origin_api_key');
    if (!is_array($values)) $values = [];
    $values[] = [
      'value' => $apiKey,
      'created_at' => time()
    ];
    update_option('ed_origin_api_key', $values);
  }

  static function deleteApiKey($createdAt) {
    $values = get_option('ed_origin_api_key');
    $values = array_filter($values, function ($value) use ($createdAt) {
      return $value['created_at'] != $createdAt;
    });
    update_option('ed_origin_api_key', $values);
  }
}

class OriginProtectionSettingsPage {
  static function init() {
    add_action('admin_menu', array(__CLASS__, 'add_plugin_page'));
    add_action('admin_init', array(__CLASS__, 'page_init'));
    add_action('admin_init', array(__CLASS__, 'on_save_settings'));
  }

  static function add_plugin_page() {
    add_options_page(
      'Settings Admin',
      'Access Control',
      'manage_options',
      'ed-access-control',
      array(__CLASS__, 'create_admin_page')
    );
  }

  static function create_admin_page() {
  ?>
    <div class="wrap">
      <h2>Access Control</h2>
      <form method="post" action="options.php">
        <button type="submit" style="position: absolute; opacity: 0;"></button>
        <?php
        settings_fields('ed_access_control');
        do_settings_sections('ed-access-control');
        submit_button();
        ?>
      </form>
    </div>
<?php
  }

  static function page_init() {
    register_setting('ed_access_control', '_ed_origin_api_key');
    register_setting('ed_access_control', 'ed_force_api_key_required');
    register_setting('ed_access_control', 'ed_basic_auth_enabled');
    register_setting('ed_access_control', 'ed_basic_auth_username');
    register_setting('ed_access_control', 'ed_basic_auth_password');

    add_settings_section(
      'setting_section_id',
      'Origin Access',
      array(__CLASS__, 'print_section_info'),
      'ed-access-control'
    );

    if (current_user_can('ed_admin')) {
      add_settings_field(
        'api_key',
        'API Key',
        array(__CLASS__, 'create_api_key_field'),
        'ed-access-control',
        'setting_section_id'
      );
    }

    if (current_user_can('ed_admin')) {
      add_settings_field(
        'password_protection',
        'Password Protection',
        array(__CLASS__, 'password_protection'),
        'ed-access-control',
        'setting_section_id'
      );
    }
  }

  static function print_section_info() {
    $originProtected = OriginProtection::isAPIKeyRequired();
    $basicAuth = OriginProtection::isPasswordProtectionEnabled();

    if (!OriginProtection::shouldUseFullSecurity()) {
      echo '<p style="margin-bottom: 8px;">The below settings are not currently enforced, because a local development environment is in use. Set the <code>DEBUG_FULL_SECURITY=true</code> environment variable to test these settings locally.</p>';
    } else {
      if ($originProtected && !$basicAuth) {
        echo 'WordPress origin access is currently restricted to WordPress Administrators and API key authenticated requests.';
      } else if ($originProtected && $basicAuth) {
        echo 'WordPress origin access is currently restricted to WordPress Administrators, API key authenticated requests, and users with the correct basic authentication credentials.';
      } else {
        echo 'Origin protection is currently disabled. Any user can access the WordPress origin.';
      }
    }
  }

  static function create_api_key_field() {
    $apiKey = get_option('ed_origin_api_key', []);
    $enabled = get_option('ed_force_api_key_required', false);
    echo '<p style="margin-bottom: 8px;">API keys allow requests to be made to the CMS origin. They do not grant any special privileges other than allowing access.</p>';
    if (ED()->getConfig("serverless.originProtection.requireLogin")) {
      echo '<label><input type="checkbox" disabled checked> Enable API key protection (Force-enabled via configuration)</label>';
    } else {
      echo '<label><input type="checkbox" name="ed_force_api_key_required" value="1" ' . checked($enabled, true, false) . '> Enable API key protection</label>';
    }
    if (is_array($apiKey) && count($apiKey) > 0) {
      echo '<ul style="display: flex; flex-direction: column; gap: 2px; align-items: flex-start">';
      foreach ($apiKey as $key) {
        $age = time() - $key['created_at'];
        $keyDisplay = $key['value'];
        if ($age > 60) {
          $keyDisplay = substr($key['value'], 0, 16) . '*******************';
        }
        echo '<li class="postbox" style="display: flex; align-items: center; padding: 8px; gap: 8px; margin: 0px;">';
        echo '<code>' . $keyDisplay . '</code>';
        echo '(Created at ' . date('Y-m-d H:i:s', $key['created_at']) . ')';
        echo '<button class="button" type="submit" name="ed_delete_api_key" value="' . $key['created_at'] . '">Revoke</button>';
        echo '<li>';
      }
      echo '</ul>';
    } else {
      echo '<p class="notice" style="margin-block: 18px; padding-block: 8px;">No API Keys have been created. Headless mode will not function correctly.</p>';
    }
    echo '<button class="button" type="submit" name="ed_create_api_key" value="1">Generate New Key</button>';
    if (OriginProtection::isPasswordProtectionEnabled()) {
      echo '<p class="notice notice-error" style="margin-block: 18px; padding-block: 8px;">An API key is required for serverless environments, since password protection is enabled</p>';
    } else if (OriginProtection::isAPIKeyRequired()) {
      echo '<p class="notice notice-error" style="margin-block: 18px; padding-block: 8px;">An API key is required for serverless environments</p>';
    }
  }

  static function on_save_settings() {
    if (current_user_can('ed_admin')) {
      if (isset($_POST['ed_create_api_key'])) {
        $apiKey = OriginProtection::generateApiKey();
        OriginProtection::addApiKey($apiKey);
        wp_redirect(admin_url('options-general.php?page=ed-access-control&settings-updated=true'));
        exit;
      } else if (isset($_POST['ed_delete_api_key'])) {
        OriginProtection::deleteApiKey($_POST['ed_delete_api_key']);
        wp_redirect(admin_url('options-general.php?page=ed-access-control&settings-updated=true'));
        exit;
      }
    }
  }

  static function password_protection() {
    $enabled = get_option('ed_basic_auth_enabled', false);
    $username = get_option('ed_basic_auth_username', '');
    $password = get_option('ed_basic_auth_password', '');
    echo '<p style="margin-bottom: 8px;">This enables simple password protection for the CMS frontend preview. When enabled, headless applications must also use an API key.</p>';
    echo '<label><input type="checkbox" name="ed_basic_auth_enabled" value="1" ' . checked($enabled, true, false) . '> Enable simple password protection</label>';
    echo '<div class="postbox" style="display: flex; flex-direction: column; padding-block: 8px; padding-inline: 10px; gap: 8px; margin: 0px; margin-top: 8px;">';
    echo '<label><strong>Username</strong> <input type="text" name="ed_basic_auth_username" value="' . esc_attr($username) . '"></label>';
    echo '<label><strong>Password</strong> <input type="text" name="ed_basic_auth_password" value="' . esc_attr($password) . '"></label>';
    echo '</div>';
  }
}
