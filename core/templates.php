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
          $result = array_merge(
            array_map(function($template) {
              return "views/" . str_replace("views/", "", str_replace(".php", ".tsx", $template));
            }, $templates),
            $templates
          );
          return $result;
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
        if (preg_match('|Template Post Type:(.*)$|mi', $contents, $type)) {
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

        // Cache and generation headers, for non-logged-in users
        if (!early_user_logged_in()) {
          $cacheTime = @ED()->getCacheConfig()['props'] ?? 0;
          if ((int)$cacheTime) {
            header('Cache-Control: public, max-age='.$cacheTime);
          }
          header('X-ED-Cache-Duration: '.(int)$cacheTime);
          header('X-ED-Generated-At: '.date("r"));
          header('X-ED-URI: '.$_SERVER['REQUEST_URI']);
        }
        
        $isJSX = preg_match("/\.(tsx|ts|jsx|js)$/", $template);
        $isPropsRequest = ED()->isPropsRequest();

        $_content = "";

        $cleanedTemplateName = trim(str_replace(ED()->sitePath, "", str_replace(ED()->themePath, "", $template)), "/");

        ErrorCollector::push('template', sprintf("running template '%s'", $template));

        // Test for a redirect, via the Redirection plugin
        if (class_exists('Redirection')) {
          add_action('redirection_last', function($mod, $items, $redirect) {
            if ($redirect[0]) {
              $url = $redirect[0]->get_action_data();
              $code = $redirect[0]->get_action_code();
              echo json_encode([
                "redirect" => $url,
                "code" => $code
              ]);
              exit;
            }
          }, 3, 10);
          $redirection = Redirection::init();
          $redModule = $redirection->get_module();
          $redModule->init();
        }
        
        // Fetch the data
        $data = [
          'view' => str_replace(".tsx", "", $cleanedTemplateName),
          'editLink' => current_user_can('edit_posts') ? get_edit_post_link(0, '') : null
        ];
        $templateBundle = "";
        $data['viewData'] = self::getDataForTemplate($template);
        if (!$isPropsRequest || $_GET['_props'] === 'all') {
          $data['appData'] = self::getDataForApp();
        }
        if ($isJSX) {
          $dadta['viewType'] = 'react';
          $templateBundle = "/dist/frontend/".preg_replace("/[^0-9A-Z]/i", "-", $cleanedTemplateName).".frontend.js";
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

        if (file_exists(ED()->themePath."/dist/frontend/main.css")) {
          $_styles .= "<link rel=\"stylesheet\" type=\"text/css\" media=\"all\" href=\"" . ED()->themeURL."/dist/frontend/main.css\">\n";
        }

        // Preload each template
        $templates = glob(ED()->themePath."/dist/frontend/view*.js");
        foreach ($templates as $template) {
          $isCurrentTemplate = ED()->themePath.$templateBundle === $template;
          $async = $isCurrentTemplate ? '' : 'async';
          $_scripts .= "<script type=\"text/javascript\" $async src=\"" . self::appendFileVersion($template) . "\"></script>\n";
        }
        if ($templateBundle) {
          $cssFile = str_replace(".frontend.js", ".css", $templateBundle);
          if (file_exists(ED()->themePath.$cssFile)) {
            $_styles .= "<link rel=\"stylesheet\" type=\"text/css\" media=\"all\" href=\"" . ED()->themeURL.$cssFile."\">\n";
          }
        }

        $extras = array_merge(glob(ED()->themePath."/dist/frontend/*ContentBlocks*.js"), glob(ED()->themePath."/dist/frontend/vendors*.js"));
        foreach ($extras as $script) {
          if ($script !== ED()->themePath.$templateBundle) {
            $_scripts .= "<script type=\"text/javascript\" src=\"" . self::appendFileVersion($script) . "\"></script>\n";
          }
        }

        $_scripts .= "<script src=\"".self::appendFileVersion(ED()->themeURL."/dist/frontend/main.frontend.js")."\"></script>\n";

        if (@count($data['errorStack']) == 0) {
          unset($data['errorStack']);
        }
        
        if ($isPropsRequest) {
          header('Content-type: text/json');
          $data['meta'] = self::getMeta();
          echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
          exit;
        } else {
          $_content = "<script>window._PAGE_DATA = ".json_encode($data, JSON_PRETTY_PRINT)."</script>";
          include(ED()->themePath."/index.php");
        }
        exit;
      }, 1000, 1);
    }

    static function appendFileVersion($script) {
      $file = str_replace(ED()->themeURL, ED()->themePath, $script);
      // if (file_exists($file)) {
        $script = str_replace(ED()->themePath, ED()->themeURL, $script) . "?v=".@filemtime($file);
      // }
      return $script;
    }

    static function getMeta() {
      // Grab the head contents
      ob_start();
      wp_head();
      $head = ob_get_contents();
      ob_end_clean();

      // Grab the foot contents
      ob_start();
      wp_footer();
      $foot = ob_get_contents();
      ob_end_clean();

      return [
        'head' => @self::parseHeaderHTML($head),
        'footer' => @self::parseHeaderHTML($foot)
      ];
    }

    static function parseHeaderHTML($html) {
      $data = DOMDocument::loadHTML(
        $html,
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
      );

      $output = [];

      $tagNames = ['title', 'meta', 'link', 'script', 'style'];

      // Grab all the tags
      foreach ($tagNames as $tagName) {
        $output[$tagName] = [];
        if (!$data) continue;
        $tags = @$data->getElementsByTagName($tagName);
        foreach ($tags as $el) {
          $attributes = [];
          foreach ($el->attributes as $attr) {
            $attributes[$attr->name] = $attr->value;
          }
          $inner = $el->nodeValue;
          if ($inner !== '') {
            $attributes['__code'] = $inner;
          }
          $output[$tagName][] = $attributes;
        }
      }
      
      return $output;
    }

    static function getQueryParams() {
      $postID = get_queried_object_id();
      if ($_GET['preview'] && $_GET['preview_id']) {
        $revisions = wp_get_post_revisions(
          $postID,
          [
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'check_enabled'  => false,
          ]
        );
        $postID = !empty($revisions) ? array_values($revisions)[0] : $postID;
      }
      $customRouteParams = Routes::getCustomRouteQueryVars();
      return array_merge(
        [
          'postId' => $postID ?? $_POST['post_id'] ?? $_GET['id'],
          'preview' => $_GET['preview'] && $_GET['preview_id']
        ],
        $customRouteParams
      );
    }

    static function getDataForTemplate($template) {
      // Determine query params
      $params = self::getQueryParams();

      // Build up a generic data blob
      $data = null;

      
      // Does a .graphql file exist?
      $templateQueryFile = preg_replace("/\.(tsx|jsx|js|ts|php)$/i", ".graphql", $template);
      
      if (file_exists($templateQueryFile)) {
        ErrorCollector::push("view", sprintf("running view query file '%s'", str_replace(ED()->themePath, "", $templateQueryFile)));
        $query = file_get_contents($templateQueryFile);
        $cacheTime = @ED()->getCacheConfig()['props'] ?? 0;
        $result = cached_graphql([
          "query" => $query . FragmentLoader::getAll(),
          "variables" => $params
        ], $cacheTime);
        if ($result['errors']) {
          foreach ($result['errors'] as $err) {
            ErrorCollector::logError($err['message']);
          }
        }
        ErrorCollector::pop();
        $data = $result;
      }

      return $data;
    }

    static function getDataForApp() {
      $queryFile = ED()->themePath."/views/_app.graphql";

      $data = null;

      if (file_exists($queryFile)) {
        ErrorCollector::push("view", sprintf("running app query file '%s'", str_replace(ED()->themePath, "", $queryFile)));
        $query = file_get_contents($queryFile);
        $cacheTime = @ED()->getCacheConfig()['props'] ?? 0;
        $result = cached_graphql([
          "query" => $query . FragmentLoader::getAll(),
          "variables" => $params
        ], $cacheTime);
        if ($result['errors']) {
          foreach ($result['errors'] as $err) {
            ErrorCollector::logError($err['message']);
          }
        }
        ErrorCollector::pop();
        $data = $result;
      }

      return $data;
    }
  }
  