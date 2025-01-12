<?php

use ED\TemplateParts;

class EDCore {
  static $instance;

  private $config;

  public $themeURL = "";
  public $themePath = "";
  public $siteURL = "";
  public $sitePath = "";
  public $selfPath = "";
  public $isDev = false;

  public $views = [];

  static function boot() {
    EDCore::$instance = new EDCore();
  }

  private function __construct() {
    EDCore::$instance = $this;
    $this->themeURL = get_stylesheet_directory_uri();
    $this->themePath = get_stylesheet_directory();
    $this->sitePath = preg_replace("/\/wp-content\/themes\/[^\/]+$/", "", $this->themePath);
    $this->selfPath = dirname(__DIR__);
    $this->siteURL = get_site_url();

    if ((bool)$this->readEnvValue("DEBUG_FULL_SECURITY") === false) {
      $this->isDev = preg_match("/(localhost|127|\.local|\.dev)/", get_site_url());
    }

    if (!defined('WPGRAPHQL_PLUGIN_URL')) {
      define('WPGRAPHQL_PLUGIN_URL', $this->themeURL . '/vendor/wp-graphql/wp-graphql/');
    }

    // Disable GraphQL analysis, which slows down the site
    add_filter('graphql_should_analyze_queries', '__return_false');

    // Disable limit of number of posts returned by GraphQL
    // This would normally be a security risk, but we don't allow direct GraphQL access.
    add_filter('graphql_connection_max_query_amount', function () {
      return 9999;
    }, 10, 0);

    if ($this->isDev) {
      define('GRAPHQL_DEBUG', 1);
    }

    // if (preg_match("/[\/\?]graphql/", $_SERVER['REQUEST_URI'])) {
    //   if (wp_get_current_user() == null || wp_get_current_user()->ID == 0) {
    //     exit;
    //   }
    // }

    // Load core files
    $includes = glob(__DIR__ . "/*.php");
    foreach ($includes as $include) {
      include_once($include);
    }

    $this->enableDevUI();

    EDWPHacks::apply();

    remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
    remove_action('wp_footer', 'wp_enqueue_global_styles', 1);
    remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');

    add_action('wp_head', [$this, 'injectServerlessEndpoint'], 10000);
    add_action('admin_head', [$this, 'injectServerlessEndpoint'], 10000);

    $configFile = $this->themePath . "/backend/config.php";
    if (file_exists($configFile)) {
      include_once($configFile);
    }

    ED\OriginProtection::init();
    ED\AdminAssets::init();

    include_once(__DIR__ . "/../integrations/load-integrations.php");
    ed_detect_integrations();
  }

  function injectServerlessEndpoint() {
    $endpoint = $this->getServerlessEndpoint();
    if ($endpoint) {
      echo "\n<script type=\"text/javascript\">window.SERVERLESS_ENDPOINT = " . json_encode($endpoint . "/") . "</script>\n";
    }
  }

  function themePath($file) {
    return $this->themePath . "/" . preg_replace("/^\//", "", $file);
  }

  function isDevProxy() {
    return isset($_SERVER['HTTP_X_ED_DEV_PROXY']);
  }

  function getConfig($key = null) {
    if (!$this->config) {
      $this->config = json_decode(file_get_contents($this->themePath . "/ed.config.json"), true) ?? [];
    }
    if ($key) {
      $parts = explode(".", $key);
      $config = $this->config;
      foreach ($parts as $part) {
        if (isset($config[$part])) {
          $config = $config[$part];
        } else {
          return null;
        }
      }
      return $config;
    }
    return $this->config;
  }

  function matchHost($pattern) {
    $hostname = preg_replace("/:[0-9]+/", "", $_SERVER['HTTP_HOST']);
    if (function_exists('fnmatch')) {
      return fnmatch($pattern, $hostname);
    } else {
      return $pattern === $hostname;
    }
  }

  function getCacheConfig($key = null) {
    $config = $this->getConfig();
    $value = null;
    if (isset($config['cache'])) {
      foreach ($config['cache'] as $host => $cacheConfig) {
        if ($this->matchHost($host)) {
          $value = $cacheConfig;
          break;
        }
      }
    }
    if ($key) {
      $parts = explode(".", $key);
      foreach ($parts as $part) {
        if (isset($value[$part])) {
          $value = $value[$part];
        } else {
          return null;
        }
      }
      return $value;
    }
    return null;
  }

  /**
   * If origin protection is enabled, this function will check if the request is allowed.
   * - WordPress users who are logged in
   * - Using an API key
   * - Has a valid nonce.
   */
  function isAuthorized() {
    return \ED\OriginProtection::isAuthorized();
  }

  function isLocalDev() {
    return preg_match("/(\.local|localhost|127\.0\.0\.1)/", $_SERVER['HTTP_HOST']);
  }

  function addCustomRoute($pattern, $args) {
    Routes::registerRoute($pattern, $args);
  }

  function getServerlessEndpoint() {
    $value = "";
    if ($this->isLocalDev()) {
      $value = $this->readEnvValue("DEBUG_SERVERLESS_ENDPOINT");
    }
    if ($value) {
      return $value;
    }
    $hostname = $_SERVER['HTTP_HOST'];
    $config = $this->getConfig();
    if (@$config['serverless']['enabled'] && is_array($config['serverless']['endpoints'])) {
      $fallback = "";
      foreach ($config['serverless']['endpoints'] as $wpHost => $serverlessHost) {
        if ($wpHost === "*") {
          $fallback = "https://" . $serverlessHost;
        }
        if ($wpHost === $hostname) {
          return "https://" . $serverlessHost;
        }
      }
      return $fallback;
    }
    return null;
  }

  function enableDevUI() {
    add_action('wp_head', function () {
      if (get_current_user_id() == 1) {
        echo "<script type=\"text/javascript\">window.ENABLE_DEV_UI = true;</script>";
      }
    });
  }

  function init() {
    // Include backend folder
    $this->includeBackendFiles();

    // Return app data when requested.
    if (preg_match("/^\/\_appdata/", $_SERVER['REQUEST_URI'])) {
      add_action('parse_request', function () {
        header('Content-Type: application/json');
        $query = new \ED\GraphQLQuery("views/_app", []);
        $query->setDecorator("withTrackers", function ($data) {
          return [
            "appData" => $data,
            "trackers" => EDTrackers::collectAll()
          ];
        });
        $result = $query->getResult();
        $query->sendCacheHeaders();
        echo json_encode($result);
        exit;
      });
    }

    add_filter('plugins_url', function ($url, $path, $plugin) {
      if (strpos($url, "wp-graphql/wp-graphql-acf/src") > 0) {
        return preg_replace("/.+wp\-content\/themes\/[^\/]+/", ED()->themeURL, $url);
      }
      return $url;
    }, 100, 3);

    // Allow unused fragments
    add_filter('graphql_validation_rules', function ($rules) {
      return array_filter($rules, function ($rule) {
        return ($rule instanceof GraphQL\Validator\Rules\NoUnusedFragments) == false;
      });
    }, 1, 1);

    EDTemplates::init();
    EDBlocks::init();
    EDTrackers::init();

    if ($this->isDev) {
      // Automatically update the .env file with debugging info
      $this->updateDevFiles();
      EDSiteInfo::init();
    }

    add_action('rest_api_init', function () {
      register_rest_route('ed/v1', '/handshake', [
        'methods' => 'GET',
        'callback' => '__return_true'
      ]);
    });

    add_action('enqueue_graphiql_extension', function () {
      /** Reconfigure WPGraphQL IDE to include preloaded fragments, and a compatible GraphQL endpoint */
      wp_localize_script(
        'wp-graphiql',
        'wpGraphiQLSettings',
        [
          'nonce'             => wp_create_nonce('wp_rest'),
          'graphqlEndpoint'   => graphql_get_endpoint_url(),
          'avatarUrl'         => 0 !== get_current_user_id() ? get_avatar_url(get_current_user_id()) : null,
          'externalFragments' => apply_filters('graphiql_external_fragments', \ED\FragmentLoader::getOptimized()),
        ]
      );
    });

    // if (ED()->isDevProxy() && preg_match("/post(-new)?\.php/", $_SERVER['REQUEST_URI'])) {

    //   // add_filter('script_loader_tag', function($tag, $handle, $src) {
    //   //   if ($handle === 'react') {
    //   //     // return "";
    //   //     // return "<script type='module' src=\"/node_modules/.vite/deps/chunk-6NLOLHO3.js?v=fbc7fbaa\"></script><script type='module'>window.React = require_react();</script>";
    //   //   } else if ($handle === 'react-dom') {
    //   //     // return "";
    //   //     // return "<script type='module' src=\"/global-react-dom.js\"></script>";
    //   //   }
    //   //   // } else {
    //   //   //   return str_replace("<script ", "<script defer ", $tag);
    //   //   // }
    //   //   return $tag;
    //   // }, 10, 3);
    // }
  }

  function tagCoreBlocks($tag, $blocks) {
    EDBlocks::tagCoreBlocks($tag, $blocks);
  }

  function groupCoreBlocks($blockName, $blocks) {
    EDBlocks::groupCoreBlocks($blockName, $blocks);
  }

  function templateLock($lock) {
    EDBlocks::templateLock($lock);
  }

  function configureView($name, $config) {
    $this->views[$name] = $config;
  }

  private function includeBackendFiles() {
    $this->includeAll("backend/*.php");
  }

  function includeAll($glob) {
    $files = glob($this->themePath . "/" . $glob);
    foreach ($files as $file) {
      include_once($file);
    }
  }

  private function updateDevFiles() {
    $this->updateGraphQLConfigFile();
    $this->updateEnv();
  }

  private function updateGraphQLConfigFile() {
    $contents = json_encode([
      "schema" => "schema.json",
      "extensions" => [
        "endpoints" => [
          "default" => [
            "url" => str_replace("https://", "http://", ED()->siteURL) . "/graphql"
          ]
        ]
      ],
      "documents" => "queries/fragments/**/*.graphql"
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $contents = preg_replace('/^(  +?)\\1(?=[^ ])/m', '$1', $contents);
    $file = ED()->themePath . "/.graphqlrc.json";
    if (@file_get_contents($file) !== $contents) {
      file_put_contents($file, $contents);
    }
  }

  function readEnvValue($key) {
    $contents = '';
    $file = $this->themePath . "/.env";
    if (file_exists($file)) {
      $contents = @file_get_contents($file);
      if (!$contents) $contents = '';
    }
    $lines = explode("\n", $contents);
    foreach ($lines as $line) {
      if (strpos($line, $key . "=") === 0) {
        return str_replace($key . "=", "", $line);
      }
    }
    return null;
  }

  private function updateEnv() {
    $contents = '';
    $file = $this->themePath . "/.env";
    if (file_exists($file)) {
      $contents = @file_get_contents($file);
      if (!$contents) $contents = '';
    }

    // Values to set
    $siteURL = $this->siteURL;
    if (strpos($siteURL, ".local") > 0) {
      // Prefer http when using local! Avoids issues with SSL
      $siteURL = str_replace("http:", "https:", $siteURL);
    }
    $values = [
      'DEBUG_GRAPHQL_URL' => $siteURL . "/graphql",
      'SITE_URL' => $siteURL
    ];

    $lines = explode("\n", $contents);
    foreach ($values as $key => $value) {
      $lines = array_filter($lines, function ($line) use ($key) {
        if (strpos($line, $key . "=") === 0) {
          return false;
        }
        return true;
      });
      $lines[] = $key . "=" . $value;
    }

    $contents = implode("\n", $lines);

    if (@file_get_contents($file) !== $contents) {
      file_put_contents($file, $contents);
    }
  }

  function registerTemplatePart($args) {
    return TemplateParts::registerTemplatePart($args);
  }

  function registerPostType($name, $args) {
    if (!preg_match("/^[a-z0-9\-]+$/", $name)) {
      throw new Error("Cannot register post type '$name' because it contains invalid characters, or is not all lowercase.");
    }
    register_post_type($name, $args);

    // if (@$args['admin_columns']) {
    //   include_once(__DIR__ . "/admin-tables.php");
    //   // Save the columns to each post type
    //   EDAdminTables::registerColumns($name, $args['admin_columns']);
    // }
  }

  /**
   * Accepts the same arguments as register_post_meta, but also accepts a 'typescript' key, which will be used to generate TypeScript types.
   * 
   * @see https://developer.wordpress.org/reference/functions/register_post_meta/
   */
  function registerPostMeta(string | array $post_type, string $meta_key, array $args) {
    $post_types = is_array($post_type) ? $post_type : [$post_type];
    foreach ($post_types as $t) {
      $this->registerMeta('post', $meta_key, array_merge($args, [
        'object_subtype' => $t
      ]));
    }
  }

  function registerMeta(string $object_type, string $meta_key, array $args, string|array $deprecated = null): bool {
    // Normalize some of the args
    $single = @$args['single'] === false ? false : true;
    $showInRest = @$args['show_in_rest'] === false ? false : true;
    $args['single'] = $single;
    $args['show_in_rest'] = $showInRest;

    /**
     * WP Meta fields with object types require a schema to be defined in the REST API.
     * We don't really care about that, so we replace it with an object that is allowed to have arbitrary properties.
     */
    if (is_bool($showInRest) && $showInRest) {
      if ($args['type'] === 'object') {
        $args['show_in_rest'] = [
          'schema' => [
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => true
          ]
        ];
      }
    }

    // Determine the 'type name' that will be used in GraphQL/TypeScript
    $nameKeys = array_filter([@$args['object_subtype'] ?? $object_type, $meta_key, @$args['type']], function ($key) {
      return $key;
    });

    // Determine the typescript type
    $typescriptType = 'any';
    $typeName = null;
    $customScalar = false;
    if (isset($args['typescript'])) {
      $typescriptType = $args['typescript'];
      $customScalar = true;
    } else if ($args['type'] === 'string') {
      $typescriptType = 'string';
      $typeName = 'String';
    } else if ($args['type'] === 'integer') {
      $typescriptType = 'number';
      $typeName = 'Int';
    } else if ($args['type'] === 'number') {
      $typescriptType = 'number';
      $typeName = 'Float';
    } else if ($args['type'] === 'boolean') {
      $typescriptType = 'boolean';
      $typeName = 'Boolean';
    } else if ($args['type'] === 'array') {
      $typescriptType = 'any[]';
      $customScalar = true;
    } else if ($args['type'] === 'object') {
      $typescriptType = 'any';
      $customScalar = true;
    }
    if ($args['single'] === false) {
      $typescriptType .= '[]';
      if ($typeName) {
        $typeName = ['list_of' => $typeName];
      }
    }

    if (!$typeName) {
      $typeName = graphql_format_type_name(implode("-", $nameKeys));
      $customScalar = true;
    }

    // Register the TypeScript type
    if ($customScalar) {
      EDTypeScriptRegistry::registerType($typeName, $typescriptType);
      register_graphql_scalar($typeName, [
        'serialize' => function ($value) {
          return $value;
        },
        'parseValue' => function ($value) {
          return $value;
        },
        'parseLiteral' => function ($ast) {
          return $ast->value;
        }
      ]);
      // Register this meta value so that the eddev compiler can generate types for usePostMetaEditor
      EDTypeScriptRegistry::registerPostMeta($meta_key, true, $typeName);
    } else {
      EDTypeScriptRegistry::registerPostMeta($meta_key, false, $typescriptType);
    }

    if ($object_type === 'post' && isset($args['object_subtype'])) {
      $postType = get_post_type_object($args['object_subtype']);
      if (!$postType) {
        throw new Error("Cannot register meta for post type '" . $args['object_subtype'] . "' because it has not yet been registered. Make sure you register the post type before registering meta fields.");
      }
      add_post_type_support($args['object_subtype'], "custom-fields");
      $objectTypeName = graphql_format_type_name(@$postType->graphql_single_name ?? $args['object_subtype']);
      register_graphql_field($objectTypeName, $meta_key, [
        'type' => $single ? $typeName : ['list_of' => $typeName],
        'description' => $args['description'] ?? '',
        'resolve' => function ($post) use ($object_type, $meta_key, $single) {
          return get_metadata($object_type, $post->ID, $meta_key, $single);
        }
      ]);
    }
    // dump($object_type, $meta_key, $args, $deprecated);
    // exit;
    return register_meta($object_type, $meta_key, $args, $deprecated);
  }

  function registerFieldType($name, $args) {
    if (class_exists('EDACFField')) {
      return new EDACFField($name, $args);
    }
  }

  function registerEnumFieldType($name, $args) {
    $install = function () use ($name, $args) {
      $class = __getACFEnumClass($args['type']);
      new $class($name, $args);
    };
    add_action("acf/include_field_types", $install);
    if (acf_did('init')) {
      $install();
    }
  }

  public function register_typed_scalar($typeName, $typescriptType) {
    EDTypeScriptRegistry::registerType($typeName, $typescriptType);
    register_graphql_scalar($typeName, [
      'serialize' => function ($value) {
        return $value;
      },
      'parseValue' => function ($value) {
        return $value;
      },
      'parseLiteral' => function ($ast) {
        return $ast->value;
      }
    ]);
  }

  /**
   * Creates a MySQL database table, and ensures it's kept up to date with the latest changes.
   * @param string $id A unique identifier for this migration. It can be the table name, or any other unique string. Changing the string will re-run the migration
   * @param string $createStatement A MySQL 'CREATE TABLE' statement.
   */
  function ensureTable($id, $createStatement) {
    EDDBMigrationManager::ensureDatabaseTable($id, $createStatement);
  }
}

/**
 * Returns the EDCore instance
 * @return EDCore
 */
function ED() {
  if (EDCore::$instance) {
    return EDCore::$instance;
  } else {
    return new EDCore();
  }
}
