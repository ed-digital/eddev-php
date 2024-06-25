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
        }, 10, 1);
      }
    }

    private static function loadPageTemplates() {
      if (self::$templates) return;
      $themeInfo = EDThemeInfo::load();

      $templates = [];

      foreach ($themeInfo['templates'] as $template) {
        $templates[$template['postType']][$template['fileName']] = $template['title'];
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

        QueryMonitor::push($cleanedTemplateName, "props");

        if ($isPropsRequest && ED()->isDev) {
          set_error_handler(function($errno, $errstr, $errfile, $errline) {
            QueryMonitor::logError([
              'type' => 'php',
              'code' => $errno,
              'message' => $errstr,
              'file' => str_replace(ED()->sitePath, "",$errfile),
              'line' => $errline
            ]);
          });
        }


        // Test for a redirect, via the Redirection plugin
        if (class_exists('Redirection')) {
          add_action('redirection_matched', function($mod, $items, $redirect) {
            if ($_GET['debug']) {
              dump("redirection_matched", [
                "mod" => $mod,
                "items" => $items,
                "redirect" => $redirect
              ]);
              exit;
            }
            if (@$redirect[0]) {
              $url = $redirect[0]->get_action_data();
              $data = unserialize($url);
              $code = $redirect[0]->get_action_code();
              echo json_encode([
                "redirect" => $data['url_from'],
                "code" => $code
              ]);
              exit;
            }
          }, 3, 10);
          $redirection = Redirection::init();
          $redModule = $redirection->get_module();
          if ($redModule) {
            $redModule->init();
          }
        }

        AssetManifest::setup(@$_GET['_ssr'] == "1");
        AssetManifest::importChunk("virtual:eddev-bootup", "main");
        
        QueryMonitor::push($cleanedTemplateName, 'template');
        
        // Generate the data
        $data = [
          'view' => preg_replace("/(^views\/|\.tsx)/", "", $cleanedTemplateName),
          'editLink' => current_user_can('edit_posts') ? get_edit_post_link(0, '') : null
        ];
        $data['viewData'] = self::getDataForTemplate($template);
        if (!$isPropsRequest || $_GET['_props'] === 'all') {
          AssetManifest::importChunk("views/_app.tsx", "modulepreload");
          $data['appData'] = self::getDataForApp();
        }
        if ($isJSX) {
          $data['viewType'] = 'react';
          AssetManifest::importChunk($cleanedTemplateName, "modulepreload");
        } else {
          ob_start();
          include($template);
          $data['viewType'] = 'html';
          $data['view'] = 'views/_html';
          AssetManifest::importChunk("views/_html.tsx", "modulepreload");
          $data['viewData']['data']['template'] = $template;
          $data['viewData']['data']['htmlContent'] = ob_get_contents();
          ob_end_clean();
        }

        // if (file_exists(ED()->themePath."/dist/frontend/main.css")) {
        //   $_styles .= "<link rel=\"stylesheet\" type=\"text/css\" media=\"all\" href=\"" . ED()->themeURL."/dist/frontend/main.css\">\n";
        // }

        // Preload each template
        // $templates = glob(ED()->themePath."/dist/frontend/view*.js");
        // foreach ($templates as $template) {
        //   $isCurrentTemplate = ED()->themePath.$templateBundle === $template;
        //   $async = $isCurrentTemplate ? '' : 'async';
        //   $_scripts .= "<script type=\"text/javascript\" $async src=\"" . self::appendFileVersion($template) . "\"></script>\n";
        // }
        // if ($templateBundle) {
        //   $cssFile = str_replace(".frontend.js", ".css", $templateBundle);
        //   if (file_exists(ED()->themePath.$cssFile)) {
        //     $_styles .= "<link rel=\"stylesheet\" type=\"text/css\" media=\"all\" href=\"" . ED()->themeURL.$cssFile."\">\n";
        //   }
        // }

        // $extras = array_merge(glob(ED()->themePath."/dist/frontend/*ContentBlocks*.js"), glob(ED()->themePath."/dist/frontend/vendors*.js"));
        // foreach ($extras as $script) {
        //   if ($script !== ED()->themePath.$templateBundle) {
        //     $_scripts .= "<script type=\"text/javascript\" src=\"" . self::appendFileVersion($script) . "\"></script>\n";
        //   }
        // }

        // $_scripts .= "<script src=\"".self::appendFileVersion(ED()->themeURL."/dist/frontend/main.frontend.js")."\"></script>\n";

        // if (@$data['errorStack'] && @count($data['errorStack']) == 0) {
        //   unset($data['errorStack']);
        // }
        $data['queryMonitor'] = QueryMonitor::pop();
        
        if ($isPropsRequest) {
          header('Content-type: text/json');
          $data['meta'] = self::getMeta();
          echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
          exit;
        } else {
          $_preload = AssetManifest::collectTags();
          $_content = "<script>window._PAGE_DATA = ".json_encode($data)."</script>".AssetManifest::collectMainTag();
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
        'head' => self::parseHeaderHTML($head),
        'footer' => self::parseHeaderHTML($foot)
      ];
    }

    static function parseHeaderHTML($html) {
      if (!$html) return [];
      $data = new DOMDocument();
      @$data->loadHTML(
        "<!DOCTYPE html><html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />".$html."</head></html>",
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

          // Ignore wp-includes scripts and styles
          if ($tagName === 'link' || @preg_match("/(wp-includes|wp-json|xmlrpc)/", @$attributes["href"])) {
            continue;
          } else if ($tagName === 'script' || @preg_match("/(wp-includes|wp-json|xmlrpc)/", @$attributes["src"])) {
            continue;
          } else if ($tagName === 'meta') {
            if (@$attributes['name'] === "generator") {
              continue;
            }
          }

          $attributes = apply_filters('eddev/serverless-header-tag', $attributes, $tagName);
          if ($attributes) {
            $output[$tagName][] = $attributes;
          }
        }
      }
      
      return $output;
    }

    static function getQueryParams() {
      $postID = get_queried_object_id();
      if (@$_GET['preview'] && $_GET['preview_id']) {
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
      if (@$_POST['action'] == 'acf/ajax/fetch-block') {
        if (!$postID) {
          $postID = $_POST['post_id'];
        }
      }
      return array_merge(
        [
          'postId' => @$postID ?? $_POST['post_id'] ?? $_GET['id'],
          'preview' => @$_GET['preview'] && $_GET['preview_id']
        ],
        $customRouteParams
      );
    }

    static function getDataForTemplate($template) {
      // Determine query params
      $params = self::getQueryParams();

      // Build up a generic data blob
      $data = ['data' => null];
      
      // Does a .graphql file exist?
      $templateQueryFile = preg_replace("/\.(tsx|jsx|js|ts|php)$/i", ".graphql", $template);
      
      if (file_exists($templateQueryFile)) {
        QueryMonitor::push($templateQueryFile, "view");
        $query = file_get_contents($templateQueryFile);
        $cacheTime = @ED()->getCacheConfig()['props'] ?? 0;
        $result = cached_graphql([
          "query" => $query . FragmentLoader::getAll(),
          "variables" => $params
        ], $cacheTime);
        if (isset($result['errors'])) {
          foreach ($result['errors'] as $err) {
            QueryMonitor::logError($err['message']);
          }
        }
        QueryMonitor::pop();
        $data = $result;
      }

      return $data;
    }

    static function getDataForApp() {
      $queryFile = ED()->themePath."/views/_app.graphql";

      $data = null;

      $params = [];

      if (file_exists($queryFile)) {
        QueryMonitor::push($queryFile, "app");
        $query = file_get_contents($queryFile);
        $cacheTime = @ED()->getCacheConfig()['props'] ?? 0;
        $result = cached_graphql([
          "query" => $query . FragmentLoader::getAll(),
          "variables" => $params
        ], $cacheTime);
        if (isset($result['errors'])) {
          foreach ($result['errors'] as $err) {
            QueryMonitor::logError($err['message']);
          }
        }
        QueryMonitor::pop();
        $data = $result;
      }

      return $data;
    }
  }
  