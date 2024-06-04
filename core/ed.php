<?php

  class EDCore {
    static $instance;

    private $config;

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
        define('WPGRAPHQL_PLUGIN_URL', $this->themeURL.'/vendor/wp-graphql/wp-graphql/');
      }

      // Disable GraphQL analysis, which slows down the site
      add_filter('graphql_should_analyze_queries', function() {
        return false;
      });

      // Disable limit of number of posts returned by GraphQL
      // This would normally be a security risk, but we don't allow direct GraphQL access.
      add_filter('graphql_connection_max_query_amount', function() {
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

      $includes = glob(__DIR__."/*.php");
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

      add_action('wp_head', function() {
        $endpoint = $this->getServerlessEndpoint();
        if ($endpoint) {
          echo "\n<script type=\"text/javascript\">window.SERVERLESS_ENDPOINT = ".json_encode($endpoint."/")."</script>\n";
        }
      });

      add_action('admin_head', array($this, "_hookListingColumns"));
    }

    function getConfig() {
      if (!$this->config) {
        $this->config = json_decode(file_get_contents(ED()->themePath."/ed.config.json"), true) ?? [];
      }
      return $this->config;
    }

    function getCacheConfig() {
      $config = $this->getConfig();
      $hostname = $_SERVER['HTTP_HOST'];
      if ($config['cache']) {
        if (@$config['cache'][$hostname]) return $config['cache'][$hostname];
        if (@$config['cache']["*"]) return $config['cache']["*"];
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
              $fallback = "https://".$serverlessHost;
            }
            if ($wpHost === $hostname) {
              return "https://".$serverlessHost;
            }
          }
          return $fallback;
        }
      }
      return null;
    }

    function disableUserEnumeration() {
      if(!is_admin()) {
        add_filter('query_vars', function($public_query_vars) {
          foreach(['author', 'author_name'] as $var) {
            $key = array_search($var, $public_query_vars);
            if (false !== $key) {
              unset($public_query_vars[$key]);
            }
          }
          return $public_query_vars;
        });
      }
      add_action('rest_authentication_errors', function( $access ) {
        if (is_user_logged_in()) {
          return $access;
        }
      
        if ((preg_match('/users/i', $_SERVER['REQUEST_URI']) !== 0) ||(isset($_REQUEST['rest_route']) && (preg_match('/users/i', $_REQUEST['rest_route']) !== 0))) {
          return new \WP_Error(
            'rest_cannot_access',
            'Only authenticated users can access the User endpoint REST API.',
            [
              'status' => rest_authorization_required_code()
            ]
          );
        }
      
        return $access;
      });
    }

    function enableDevUI() {
      add_action('wp_head', function() {
        if (get_current_user_id() == 1) {
          echo "<script type=\"text/javascript\">window.ENABLE_DEV_UI = true;</script>";
        }
      });
    }

    function enableDevReact() {
      add_filter("script_loader_src", function($src, $handle) {
        if (($handle === "react-dom" || $handle === "react")) {
          if (preg_match("/plugins\/gutenberg\/vendor\/react/", $src)) {
            // Pre-Gutenberg 12.9.0
            $files = scandir(ED()->sitePath."/wp-content/plugins/gutenberg/vendor");
            foreach ($files as $file) {
              if (strpos($file, $handle.".") === 0 && strpos($file, "min") === false) {
                return ED()->siteURL."/wp-content/plugins/gutenberg/vendor/".$file;
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

      add_filter('plugins_url', function($url, $path, $plugin) {
        if (strpos($url, "wp-graphql/wp-graphql-acf/src") > 0) {
          return preg_replace("/.+wp\-content\/themes\/[^\/]+/", ED()->themeURL, $url);
        }
        return $url;
      }, 100, 3);

      // Allow unused fragments
      add_filter('graphql_validation_rules', function($rules) {
        return array_filter($rules, function($rule) {
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
      }

      add_filter('admin_enqueue_scripts', function() {
        wp_enqueue_script(
          'theme_admin_js',
          $this->themeURL.'/dist/admin/main.admin.js',
          get_current_screen()->is_block_editor() ? ['wp-blocks', 'wp-editor', 'wp-edit-post', 'wp-dom-ready', 'react', 'acf-blocks', 'acf'] : ['acf', 'react', 'react-dom', 'wp-hooks'],
          filemtime(ED()->themePath.@$style)
        );
        $style = "/dist/admin/main.css";
        if (file_exists(ED()->themePath.$style)) {
          wp_enqueue_style('theme_admin_css', ED()->themeURL.$style, [], filemtime(ED()->themePath.$style));
        }
      });

      add_action('enqueue_block_editor_assets', function() {
        add_action('admin_print_scripts', function() {
          $data = EDTemplates::getDataForApp();
          echo "<script>window.__ED_APP_DATA = ".json_encode($data)."</script>";
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
      $files = glob($this->themePath."/".$glob);
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
              "url" => str_replace("https://", "http://", ED()->siteURL)."/graphql"
            ]
          ]
        ],
        "documents" => "queries/fragments/**/*.graphql"
      ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
      $contents = preg_replace('/^(  +?)\\1(?=[^ ])/m', '$1', $contents);
      $file = ED()->themePath."/.graphqlrc.json";
      if (@file_get_contents($file) !== $contents) {
        file_put_contents($file, $contents);
      }
    }

    private function readEnvValue($key) {
      $contents = '';
      $file = $this->themePath."/.env";
      if (file_exists($file)) {
        $contents = @file_get_contents($file);
        if (!$contents) $contents = '';
      }
      $lines = explode("\n", $contents);
      foreach ($lines as $line) {
        if (strpos($line, $key."=") === 0) {
          return str_replace($key."=", "", $line);
        }
      }
      return null;
    }
    
    private function updateEnv() {
      $contents = '';
      $file = $this->themePath."/.env";
      if (file_exists($file)) {
        $contents = @file_get_contents($file);
        if (!$contents) $contents = '';
      }

      // Values to set
      $siteURL = $this->siteURL;
      if (strpos($siteURL, ".local") > 0) {
        // Prefer http when using local! Avoids issues with SSL
        $siteURL = str_replace("https", "http", $siteURL);
      }
      $values = [
        'DEBUG_GRAPHQL_URL' => $siteURL."/graphql",
        'SITE_URL' => $siteURL
      ];

      $lines = explode("\n", $contents);
      foreach ($values as $key => $value) {
        $lines = array_filter($lines, function($line) use ($key) {
          if (strpos($line, $key."=") === 0) {
            return false;
          }
          return true;
        });
        $lines[] = $key."=".$value;
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

    function registerFieldType($name, $args) {
      if (class_exists('EDACFField')) {
        return new EDACFField($name, $args);
      }
    }

    public function _hookListingColumns() {
      $postTypes = get_post_types();
  
      foreach($postTypes as $name => $label) {
        $manager = new EDColumnManager($name, isset($this->postTypeColumns[$name]) ? $this->postTypeColumns[$name] : array());
        $this->postTypeColumnManagers[$name] = $manager;
        add_filter('manage_edit-'.$name.'_columns', array($manager, 'alterColumnLayout'), 16);
        add_action('manage_'.$name.'_posts_custom_column', array($manager, 'printColumn'), 16);
      }
    }

    function trackingHead() {
      $this->tracking("head");
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
          <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
          new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
          j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
          'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
          })(window,document,'script','dataLayer',<?=json_encode($tracking['tagManagerID'])?>);</script>
          <!-- End Google Tag Manager [ED] -->
          <?
        }
        if (@$tracking['ga']) {
          $ga = @$tracking['ga'];
          if ($ga['version'] == '3') {
            ?>
            <!-- Google tag (gtag.js) [ED] -->
            <script async src="https://www.googletagmanager.com/gtag/js?id=<?=$ga['trackingID']?>"></script>
            <script>
              window.dataLayer = window.dataLayer || [];
              function gtag(){dataLayer.push(arguments);}
              gtag('js', new Date());

              gtag('config', '<?=$ga['trackingID']?>');
            </script>
            <?
          } elseif ($ga['version'] == '4') {
            ?>
            <!-- Google tag (gtag.js) [ED] -->
            <script async src="https://www.googletagmanager.com/gtag/js?id=<?=$ga['trackingID']?>"></script>
            <script>
              window.dataLayer = window.dataLayer || [];
              function gtag(){dataLayer.push(arguments);}
              gtag('js', new Date());

              gtag('config', '<?=$ga['trackingID']?>');
            </script>
            <?
          }
        }
      } else if($location === "body") {
        if (@$tracking['tagManagerID']) {
          ?>
          <!-- Google Tag Manager (noscript) [ED] -->
          <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?=$tracking['tagManagerID']?>"
          height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
          <!-- End Google Tag Manager (noscript) [ED] -->
          <?
        }
      }
    }
  }

  function ED() {
    if (EDCore::$instance) {
      return EDCore::$instance;
    } else {
      return new EDCore();
    }
  }
  