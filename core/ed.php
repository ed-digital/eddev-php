<?php

  class EDCore {
    static $instance;

    public $config = [
      'serverless' => false,
      'serverlessUrl' => ''
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

      add_filter('admin_init', function() {
        wp_enqueue_script(
          'theme_admin_js',
          $this->themeURL.'/dist/admin/main.admin.js',
          ['wp-blocks', 'wp-editor', 'wp-edit-post', 'wp-dom-ready', 'react', 'acf-blocks'],
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
      if (file_get_contents($file) !== $contents) {
        file_put_contents($file, $contents);
      }
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
        'DEBUG_GRAPHQL_URL' => $this->siteURL."/graphql"
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
      
      if (file_get_contents($file) !== $contents) {
        file_put_contents($file, $contents);
      }
    }

    function registerPostType($name, $args) {
      if (!preg_match("/^[a-z0-9\-]+$/", $name)) {
        throw new Error("Cannot register post type '$name' because it contains invalid characters, or is not all lowercase.");
      }
      register_post_type($name, $args);
    }
  }

  function ED() {
    if (EDCore::$instance) {
      return EDCore::$instance;
    } else {
      return new EDCore();
    }
  }
  