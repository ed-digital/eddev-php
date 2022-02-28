<?php

  class EDCore {
    static $instance;

    public $config = [
      
    ];

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

      add_action('wp_head', function() {
        $endpoint = $this->getServerlessEndpoint();
        if ($endpoint) {
          echo "\n<script type=\"text/javascript\">window.SERVERLESS_ENDPOINT = ".json_encode($endpoint."/")."</script>\n";
        }
      });
    }

    function getConfig() {
      return json_decode(file_get_contents(ED()->themePath."/ed.config.json"), true);
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
          foreach ($config['serverless']['endpoints'] as $wpHost => $serverlessHost) {
            if ($wpHost === $hostname || $wpHost === "*") {
              return "https://".$serverlessHost;
            }
          }
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
    }

    function enableDevUI() {
      add_action('wp_head', function() {
        if (get_current_user_id() == 1) {
          echo "<script type=\"text/javascript\">window.ENABLE_DEV_UI = true;</script>";
        }
      });
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
          get_current_screen()->is_block_editor() ? ['wp-blocks', 'wp-editor', 'wp-edit-post', 'wp-dom-ready', 'react', 'acf-blocks', 'acf'] : ['acf', 'react', 'react-dom'],
          filemtime(ED()->themePath.$style)
        );
        $style = "/dist/admin/main.css";
        if (file_exists(ED()->themePath.$style)) {
          wp_enqueue_style('theme_admin_css', ED()->themeURL.$style, [], filemtime(ED()->themePath.$style));
        }
      });
    }

    function tagCoreBlocks($tag, $blocks) {
      EDBlocks::tagCoreBlocks($tag, $blocks);
    }

    function templateLock($lock) {
      EDBlocks::templateLock($lock);
    }

    function setConfig($config) {
      $this->config = array_merge($this->config, $config);
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
        "schemaPath" => "schema.json",
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
      $values = [
        'DEBUG_GRAPHQL_URL' => $this->siteURL."/graphql",
        'SITE_URL' => $this->siteURL
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
    }

    function registerFieldType($name, $args) {
      if (class_exists('EDACFField')) {
        return new EDACFField($name, $args);
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
  