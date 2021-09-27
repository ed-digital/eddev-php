<?php

  class EDTemplates {

    static $templates;

    static function init() {
      self::hookViewResponder();
      self::hookViews();
      self::hookPageTemplates();
    }

    private static function hookViews() {

      $viewTypes = [
        "404",
        "archive",
        "attachment",
        "author",
        "category",
        "date",
        "embed",
        "frontpage",
        "home",
        "index",
        "page",
        "paged",
        "privacypolicy",
        "search",
        "single",
        "singular",
        "tag",
        "taxonomy"
      ];

      // Locate templates as view/TEMPLATE.tsx files, rather than TEMPLATE.php files
      foreach ($viewTypes as $type) {
        add_filter("{$type}_template_hierarchy", function($templates) {
          return array_merge(
            array_map(function($template) {
              return "views/" . str_replace(".php", ".tsx", $template);
            }, $templates),
            $templates
          );
        });
      }
    }

    private static function loadPageTemplates() {
      if (self::$templates) return;
      $files = glob(ED()->themePath."/views/{,*/}*.tsx", GLOB_BRACE);
      $templates = [];
      
      foreach($files as $full_path) {
        $file = str_replace(ED()->themePath."/views/", "views/", $full_path);
        $contents = file_get_contents($full_path);
        if (!preg_match('|Template Name:(.*)$|mi', $contents, $header) ) {
          continue;
        }

        $types = array('page');
        if (preg_match('|Template Post Type:(.*)$|mi', file_get_contents($full_path), $type)) {
          $types = explode( ',', _cleanup_header_comment($type[1]));
        }

        foreach ($types as $type) {
          $type = sanitize_key( $type );
          if (!isset($templates[ $type ])) {
            $templates[$type] = array();
          }

          $templates[$type][$file] = _cleanup_header_comment( $header[1] );
        }
      }
      self::$templates = $templates;
    }

    private static function hookPageTemplates() {
      add_filter('theme_templates', function($templates, $theme, $post, $post_type) {
        self::loadPageTemplates();
        return array_merge($templates, self::$templates[$post_type] ?? []);
      }, 10, 4);
    }

    private static function hookViewResponder() {
      add_filter('template_include', function($template) {
        $isJSX = preg_match("/\.(tsx|ts|jsx|js)$/", $template);
        if (ED()->config['serverless']) {
          dump("SERVERLESS NOT READY", $template);
        } else {
          $_content = "";

          $cleanedTemplateName = trim(str_replace(ED()->sitePath, "", str_replace(ED()->themePath, "", $template)), "/");
          
          // Fetch the data
          $data = [
            'view' => $cleanedTemplateName
          ];
          $templateBundle = "";
          $data['viewData'] = self::getDataForTemplate($template);
          $data['appData'] = self::getDataForApp();
          if ($isJSX) {
            $dadta['viewType'] = 'react';
            $templateBundle = "/dist/".preg_replace("/[^0-9A-Z]/i", "-", $cleanedTemplateName).".frontend.js";
          } else {
            ob_start();
            include($template);
            $data['viewType'] = 'html';
            $data['view'] = 'views/_html.tsx';
            $data['viewData']['data']['template'] = $template;
            $data['viewData']['data']['htmlContent'] = ob_get_contents();
            ob_end_clean();
          }

          $_scripts = "";
          $_styles = "";

          $_scripts .= "<script src=\"".ED()->themeURL."/dist/main.frontend.js\"></script>\n";
          $_styles .= "<link rel=\"stylesheet\" type=\"text/css\" media=\"all\" href=\"" . ED()->themeURL."/dist/main.css\">\n";

          if ($templateBundle) {
            if (file_exists(ED()->themePath.$templateBundle)) {
              $_scripts .= "<script src=\"" . ED()->themeURL . $templateBundle . "\"></script>\n";
            }
            $cssFile = str_replace(".frontend.js", ".css", $templateBundle);
            if (file_exists(ED()->themePath.$cssFile)) {
              $_styles .= "<link rel=\"stylesheet\" type=\"text/css\" media=\"all\" href=\"" . ED()->themeURL.$cssFile."\">\n";
            }
          }
          
          if (ED()->isPropsRequest()) {
            header('Content-type: text/json');
            echo json_encode($data, JSON_PRETTY_PRINT);
            exit;
          } else {
            $_content = "<script>window._PAGE_DATA = ".json_encode($data, JSON_PRETTY_PRINT)."</script>";
            include(ED()->themePath."/index.php");
          }
        }
        exit;
      }, 1000, 1);
    }

    static function getQueryParams() {
      global $post;
      return [
        'postId' => @get_queried_object()->ID ?? $post->ID ?? $_POST['post_id'] ?? $_GET['id']
      ];
    }

    static function getDataForTemplate($template) {
      // Determine query params
      $params = self::getQueryParams();

      // Build up a generic data blob
      $data = null;
      
      // Does a .graphql file exist?
      $templateQueryFile = preg_replace("/\.(tsx|jsx|js|ts|php)$/i", ".graphql", $template);
      if (file_exists($templateQueryFile)) {
        $query = file_get_contents($templateQueryFile);
        $result = graphql([
          "query" => $query,
          "variables" => $params
        ]);
        $data = $result;
      }

      return $data;
    }

    static function getDataForApp() {
      $queryFile = ED()->themePath."/views/_app.graphql";

      $data = null;

      if (file_exists($queryFile)) {
        $query = file_get_contents($queryFile);
        $result = graphql([
          "query" => $query,
          "variables" => $params
        ]);
        $data = $result;
      }

      return $data;
    }
  }