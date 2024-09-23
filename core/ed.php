<?php

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
    $this->themeURL = get_stylesheet_directory_uri();
    $this->themePath = get_stylesheet_directory();
    $this->sitePath = preg_replace("/\/wp-content\/themes\/[^\/]+$/", "", $this->themePath);
    $this->selfPath = dirname(__DIR__);
    $this->siteURL = get_site_url();
    $this->isDev = preg_match("/(localhost|127|\.local|\.dev)/", get_site_url());

    if (!defined('WPGRAPHQL_PLUGIN_URL')) {
      define('WPGRAPHQL_PLUGIN_URL', $this->themeURL . '/vendor/wp-graphql/wp-graphql/');
    }

    // Disable GraphQL analysis, which slows down the site
    add_filter('graphql_should_analyze_queries', function () {
      return false;
    });

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

    $includes = glob(__DIR__ . "/*.php");
    foreach ($includes as $include) {
      include_once($include);
    }

    $this->disableUserEnumeration();
    $this->enableDevUI();
    $this->enableDevReact();

    edDisableComments();
    edDisableEmojis();

    remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
    remove_action('wp_footer', 'wp_enqueue_global_styles', 1);
    remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');

    add_action('wp_head', function () {
      $endpoint = $this->getServerlessEndpoint();
      if ($endpoint) {
        echo "\n<script type=\"text/javascript\">window.SERVERLESS_ENDPOINT = " . json_encode($endpoint . "/") . "</script>\n";
      }
    });

    add_action('admin_init', array($this, "_hookListingColumns"));

    // Return app data when requested.
    if (preg_match("/^\/\_appdata/", $_SERVER['REQUEST_URI'])) {
      add_action('parse_request', function () {
        header('Content-Type: application/json');
        echo json_encode(EDTemplates::getDataForApp());
        exit;
      });
    }
  }

  function isDevProxy() {
    return isset($_SERVER['HTTP_X_ED_DEV_PROXY']);
  }

  function getConfig() {
    if (!$this->config) {
      $this->config = json_decode(file_get_contents(ED()->themePath . "/ed.config.json"), true) ?? [];
    }
    return $this->config;
  }

  function getCacheConfig() {
    $config = $this->getConfig();
    $hostname = $_SERVER['HTTP_HOST'];
    if (isset($config['cache'])) {
      if (isset($config['cache'][$hostname])) return $config['cache'][$hostname];
      if (isset($config['cache']["*"])) return $config['cache']["*"];
    }
    return [];
  }

  function isLocalDev() {
    return preg_match("/(\.local|localhost|127\.0\.0\.1)/", $_SERVER['HTTP_HOST']);
  }

  function addCustomRoute($pattern, $args) {
    Routes::registerRoute($pattern, $args);
  }

  function getServerlessEndpoint() {
    if ($this->isLocalDev()) {
      return $this->readEnvValue("DEBUG_SERVERLESS_ENDPOINT");
    } else {
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
    }
    return null;
  }

  function disableUserEnumeration() {
    if (!is_admin()) {
      add_filter('query_vars', function ($public_query_vars) {
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

  function enableDevUI() {
    add_action('wp_head', function () {
      if (get_current_user_id() == 1) {
        echo "<script type=\"text/javascript\">window.ENABLE_DEV_UI = true;</script>";
      }
    });
  }

  function enableDevReact() {
    add_filter("script_loader_src", function ($src, $handle) {
      if (($handle === "react-dom" || $handle === "react")) {
        if (preg_match("/plugins\/gutenberg\/vendor\/react/", $src)) {
          // Pre-Gutenberg 12.9.0
          $files = scandir(ED()->sitePath . "/wp-content/plugins/gutenberg/vendor");
          foreach ($files as $file) {
            if (strpos($file, $handle . ".") === 0 && strpos($file, "min") === false) {
              return ED()->siteURL . "/wp-content/plugins/gutenberg/vendor/" . $file;
            }
          }
        } else {
          // Gutenberg 13.0+
          return preg_replace("/\.min\./", ".", $src);
        }
      }
      return $src;
    }, 2, 2);
  }

  function init() {
    // Include backend folder
    $this->includeBackendFiles();

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

    add_action('wp_head', [$this, 'trackingHead']);
    add_action('wp_body_open', [$this, 'trackingBody']);
    add_action('wp_footer', [$this, 'trackingFooter']);

    EDTemplates::init();
    EDBlocks::init();

    if ($this->isDev) {
      // Automatically update the .env file with debugging info
      $this->updateDevFiles();
      EDSiteInfo::init();
    }

    if (ED()->isDevProxy()) {
      add_action('admin_head', function () {
        // Add Vite HMR info
        echo "<!---VITE_HEADER--->";
      });

      add_action('admin_footer', function () {
        echo "<!---VITE_FOOTER--->";
      });

      // add_filter('script_loader_tag', function($tag, $handle, $src) {
      //   if ($handle === 'react') {
      //     // return "";
      //     // return "<script type='module' src=\"/node_modules/.vite/deps/chunk-6NLOLHO3.js?v=fbc7fbaa\"></script><script type='module'>window.React = require_react();</script>";
      //   } else if ($handle === 'react-dom') {
      //     // return "";
      //     // return "<script type='module' src=\"/global-react-dom.js\"></script>";
      //   }
      //   // } else {
      //   //   return str_replace("<script ", "<script defer ", $tag);
      //   // }
      //   return $tag;
      // }, 10, 3);
    }

    add_filter('admin_enqueue_scripts', function () {

      // The dependencies to enqueue, depending on whether the block editor is on this page
      $deps = get_current_screen()->is_block_editor()
        ? ['wp-blocks', 'wp-editor', 'wp-edit-post', 'wp-dom-ready', 'react', 'acf-blocks', 'acf']
        : ['acf', 'react', 'react-dom', 'wp-hooks'];

      if (ED()->isDevProxy()) {
        // If the dev proxy is currently being used, just enqueue the deps
        foreach ($deps as $dep) {
          wp_enqueue_script($dep);
        }
      } else {
        AssetManifest::setup(false, "cms");
        AssetManifest::importChunk(".eddev/dev-spa/entry.admin.tsx", 'main');
        $adminEntry = AssetManifest::getEntryScript();
        if (!$adminEntry) {
          AssetManifest::importChunk(".eddev/prod-spa/entry.admin.tsx", 'main');
          $adminEntry = AssetManifest::getEntryScript();
        }
        $adminEntryPath = str_replace(ED()->themeURL, ED()->themePath, $adminEntry);

        if (file_exists($adminEntryPath)) {
          wp_enqueue_script(
            'theme_admin_js',
            $adminEntry,
            get_current_screen()->is_block_editor() ? ['wp-blocks', 'wp-editor', 'wp-edit-post', 'wp-dom-ready', 'react', 'acf-blocks', 'acf'] : ['acf', 'react', 'react-dom', 'wp-hooks'],
            filemtime($adminEntryPath)
          );
        }
      }

      // if (file_exists(ED()->themePath.$style)) {
      //   wp_enqueue_style('theme_admin_css', ED()->themeURL.$style, [], filemtime(ED()->themePath.$style));
      // }
    });

    add_filter('script_loader_tag', function ($tag, $handle, $src) {
      if ($handle === "theme_admin_js") {
        return '<script type="module" src="' . $src . '"></script>';
      }
      return $tag;
    }, 10, 3);

    add_action('enqueue_block_editor_assets', function () {
      add_action('admin_print_scripts', function () {
        $data = EDTemplates::getDataForApp();
        echo "<script>window.__ED_APP_DATA = " . json_encode($data) . "</script>";
      });
    });
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

  function isPropsRequest() {
    return isset($_GET['_props']);
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

  private function readEnvValue($key) {
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

  function registerPostType($name, $args) {
    if (!preg_match("/^[a-z0-9\-]+$/", $name)) {
      throw new Error("Cannot register post type '$name' because it contains invalid characters, or is not all lowercase.");
    }
    register_post_type($name, $args);

    if (@$args['adminColumns']) {
      // Save the columns to each post type
      $this->postTypeColumns[$name] = $args['adminColumns'];
    }
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
    $args['single'] = $single;
    $showInRest = @$args['show_in_rest'] ?? true;
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

  public function _hookListingColumns() {
    $postTypes = get_post_types();

    foreach ($postTypes as $name => $label) {
      $manager = new EDColumnManager($name, isset($this->postTypeColumns[$name]) ? $this->postTypeColumns[$name] : array());
      $this->postTypeColumnManagers[$name] = $manager;
      add_filter('manage_edit-' . $name . '_columns', array($manager, 'alterColumnLayout'), 16);
      add_action('manage_' . $name . '_posts_custom_column', array($manager, 'printColumn'), 16);
    }
  }

  function trackingHead() {
    $this->tracking("head");
  }

  /**
   * Creates a MySQL database table, and ensures it's kept up to date with the latest changes.
   * @param string $id A unique identifier for this migration. It can be the table name, or any other unique string. Changing the string will re-run the migration
   * @param string $createStatement A MySQL 'CREATE TABLE' statement.
   */
  function ensureTable($id, $createStatement) {
    EDDBMigrationManager::ensureDatabaseTable($id, $createStatement);
  }

  function trackingBody() {
    $this->tracking("body");
  }

  function trackingFooter() {
    $this->tracking("footer");
  }

  // Prints out tracking codes from ed.config.json
  function tracking($location) {
    $tracking = @$this->getConfig()['tracking'];

    if ($location === "head") {
      if (@$tracking['tagManagerID']) {
?>
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
          })(window, document, 'script', 'dataLayer', <?= json_encode($tracking['tagManagerID']) ?>);
        </script>
        <!-- End Google Tag Manager [ED] -->
        <?
      }
      if (@$tracking['ga']) {
        $ga = @$tracking['ga'];
        if ($ga['version'] == '3') {
        ?>
          <!-- Google tag (gtag.js) [ED] -->
          <script async src="https://www.googletagmanager.com/gtag/js?id=<?= $ga['trackingID'] ?>"></script>
          <script>
            window.dataLayer = window.dataLayer || [];

            function gtag() {
              dataLayer.push(arguments);
            }
            gtag('js', new Date());

            gtag('config', '<?= $ga['trackingID'] ?>');
          </script>
        <?
        } elseif ($ga['version'] == '4') {
        ?>
          <!-- Google tag (gtag.js) [ED] -->
          <script async src="https://www.googletagmanager.com/gtag/js?id=<?= $ga['trackingID'] ?>"></script>
          <script>
            window.dataLayer = window.dataLayer || [];

            function gtag() {
              dataLayer.push(arguments);
            }
            gtag('js', new Date());

            gtag('config', '<?= $ga['trackingID'] ?>');
          </script>
        <?
        }
      }
    } else if ($location === "body") {
      if (@$tracking['tagManagerID']) {
        ?>
        <!-- Google Tag Manager (noscript) [ED] -->
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?= $tracking['tagManagerID'] ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
        <!-- End Google Tag Manager (noscript) [ED] -->
<?
      }
    }
  }
}

function ED(): EDCore {
  if (EDCore::$instance) {
    return EDCore::$instance;
  } else {
    return new EDCore();
  }
}
