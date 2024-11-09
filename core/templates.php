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
      add_filter("{$type}_template_hierarchy", function ($templates) use ($type) {
        $result = array_merge(
          array_map(function ($template) {
            return "views/" . str_replace("views/", "", str_replace(".php", ".tsx", $template));
          }, $templates),
          $templates
        );
        if ($type === "404") {
          $result[] = "views/_error.tsx";
        }
        return $result;
      }, 10, 1);
    }
  }

  private static function loadPageTemplates() {
    if (self::$templates) return;
    $themeInfo = EDThemeInfo::load();
    $templates = [];
    foreach ($themeInfo['templates'] as $template) {
      foreach ($template['postType'] as $postType) {
        $templates[$postType][$template['fileName']] = $template['title'];
      }
    }
    self::$templates = $templates;
  }

  private static function hookPageTemplates() {
    add_filter('theme_templates', function ($templates, $theme, $post, $post_type) {
      self::loadPageTemplates();

      return array_merge($templates, self::$templates[$post_type] ?? []);
    }, 10, 4);
  }

  private static function hookViewResponder() {
    add_filter('template_include', function ($template) {

      // Cache and generation headers, for non-logged-in users
      $cacheTime = @ED()->getCacheConfig()['props'] ?? 0;
      $bypass = QueryHandler::shouldBypassCache();

      if (!$bypass) {
        if ((int)$cacheTime) {
          header('Cache-Control: public, max-age=' . $cacheTime);
        }
        header('X-ED-Cache-Duration: ' . (int)$cacheTime);
        header('X-ED-Generated-At: ' . date("r"));
        header('X-ED-URI: ' . $_SERVER['REQUEST_URI']);
      }

      $isJSX = preg_match("/\.(tsx|ts|jsx|js)$/", $template);
      $templateFile = trim(str_replace(ED()->sitePath, "", str_replace(ED()->themePath, "", $template)), "/");

      // Is this a JSON request? Or a regular page view?
      $isPropsRequest = isset($_GET['_props']) && !!$_GET['_props'];
      $handleAssets = !$isPropsRequest;
      $includeAppData = (isset($_GET['_props']) && $_GET['_props'] === 'all') || !$isPropsRequest;
      $debugQueries = $isPropsRequest && (ED()->isDev || isset($_GET['_debug']));

      QueryMonitor::push($templateFile, 'template');

      if ($debugQueries) {
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
          if ($errno === 2) return;
          QueryMonitor::logError([
            'type' => 'php',
            'code' => $errno,
            'message' => $errstr,
            'file' => str_replace(ED()->sitePath, "", $errfile),
            'line' => $errline
          ]);
        });
      }

      // Load the asset manifest
      AssetManifest::setup(!$handleAssets);
      AssetManifest::importChunk("virtual:eddev-bootup", "main");
      AssetManifest::importChunk("views/_app.tsx", "modulepreload");

      // Generate the data
      $data = [
        'view' => preg_replace("/(^views\/|\.tsx)/", "", $templateFile),
        'editLink' => current_user_can('edit_posts') ? get_edit_post_link(0, '') : null
      ];
      $data['viewData'] = self::getDataForTemplate($template);
      if ($includeAppData) {
        $data['appData'] = self::getAppQueryData();
      }
      if ($isJSX) {
        $data['viewType'] = 'react';
        // if ($templateFile === "views/_error.tsx") {
        //   AssetManifest::importChunk("views/page.tsx", "modulepreload");
        // } else {
        AssetManifest::importChunk($templateFile, "modulepreload");
        // }
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

      $data['queryMonitor'] = QueryMonitor::pop();

      if ($isPropsRequest) {
        header('Content-type: text/json');
        $data['meta'] = self::getMeta();
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
      } else {
        $_preload = AssetManifest::collectTags();
        $_content = "<script>window._PAGE_DATA = " . json_encode($data) . "</script>" . AssetManifest::collectMainTag();
        include(ED()->themePath . "/index.php");
      }
      exit;
    }, 1000, 1);
  }

  static function appendFileVersion($script) {
    $file = str_replace(ED()->themeURL, ED()->themePath, $script);
    $script = str_replace(ED()->themePath, ED()->themeURL, $script) . "?v=" . @filemtime($file);
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

  static function extractTags($html) {
    if (!$html) return [];
    $doc = new DOMDocument();
    @$doc->loadHTML(
      "<!DOCTYPE html><html><head><meta data-ignore=\"true\" http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />" . $html . "</head></html>",
      LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );

    $output = [];
    $tagNames = ['title', 'meta', 'link', 'script', 'style'];

    foreach ($doc->documentElement->firstChild->childNodes as $el) {
      if ($el->nodeType === XML_ELEMENT_NODE) {
        $tagName = $el->tagName;
        if (!in_array($tagName, $tagNames)) {
          continue;
        }
        $attributes = [];
        foreach ($el->attributes as $attr) {
          $attributes[$attr->name] = $attr->value;
        }
        $inner = $el->nodeValue;

        if (isset($attributes['data-ignore'])) {
          continue;
        }

        $tag = (object)[
          'tagName' => $tagName,
          'attributes' => $attributes,
          'inner' => $inner
        ];
        $output[] = $tag;
      }
    }

    return $output;
  }

  static function parseHeaderHTML($html) {
    $tags = self::extractTags($html);

    $output = [];

    // Loop over all children inside the head tag
    foreach ($tags as $tag) {
      $ignore = false;

      if (@$tag->attributes['data-ignore']) {
        $ignore = true;
      }

      // Ignore wp-includes scripts and styles
      if (($tag->tagName === 'link' && @$tag->attributes['rel'] !== 'icon') || @preg_match("/(wp-includes|wp-json|xmlrpc)/", @$tag->attributes["href"])) {
        $ignore = true;
      } else if ($tag->tagName === 'script' || @preg_match("/(wp-includes|wp-json|xmlrpc)/", @$tag->attributes["src"])) {
        $ignore = true;
      } else if ($tag->tagName === 'meta') {
        if (@$tag->attributes['name'] === "generator") {
          $ignore = true;
        }
      }

      // Give user code the chance to ignore/unignore tags
      $tag->ignore = $ignore;
      do_action('eddev/serverless-header-tag', $tag);

      // If the tag is not ignored, add it to the output
      if (!$tag->ignore) {
        $output[] = $tag;
      }
    }

    $output = array_filter($output, function ($tag) {
      if (!$tag->inner) {
        unset($tag->inner);
      }
      return !$tag->ignore;
    });

    return $output;
  }

  static function getQueryParams() {
    $postID = get_queried_object_id();
    if (isset($_GET['preview']) && isset($_GET['preview_id'])) {
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
    if (isset($_POST['action']) && $_POST['action'] == 'acf/ajax/fetch-block') {
      if (!$postID) {
        $postID = $_POST['post_id'];
      }
    }
    if ($postID == 0 && isset($_GET['post']) && isset($_GET['action']) == 'edit') {
      $postID = $_GET['post'];
    }
    return array_merge(
      [
        'postId' => $postID ?? $_POST['post_id'] ?? $_GET['id'],
        'preview' => isset($_GET['preview']) && isset($_GET['preview_id'])
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
    $queryFile = preg_replace("/\.(tsx|jsx|js|ts|php)$/i", ".graphql", $template);

    if (file_exists($queryFile)) {
      $name = trim(str_replace(ED()->themePath, "", $queryFile), "/");
      QueryMonitor::push($name, "view");
      $query = QueryLoader::load($name);
      $cacheTime = QueryHandler::getCacheTime($name, $query);
      $result = cached_graphql([
        "name" => $name,
        "query" => $query,
        "variables" => $params
      ], $cacheTime);

      // Log any errors
      if (isset($result['errors'])) {
        foreach ($result['errors'] as $err) {
          QueryMonitor::logError($err['message']);
        }
      }
      $item = QueryMonitor::pop();
      if (isset($result['_from_cache'])) {
        $item->fromCache = true;
      }

      $data = $result;
    }

    return $data;
  }

  static function getAppQueryData() {
    $queryFile = "views/_app.graphql";

    $data = null;

    $params = [];
    $query = QueryLoader::load($queryFile);

    if (is_string($query)) {
      $cacheTime = QueryHandler::getCacheTime($queryFile, $query);
      QueryMonitor::push($queryFile, "app");
      $result = cached_graphql([
        "name" => $queryFile,
        "query" => $query,
        "variables" => $params,
        "label" => "app"
      ], $cacheTime);

      // Log any errors
      if (isset($result['errors'])) {
        foreach ($result['errors'] as $err) {
          QueryMonitor::logError($err['message']);
        }
      }

      $item = QueryMonitor::pop();
      if (isset($result['_from_cache'])) {
        $item->fromCache = true;
      }
      $data = $result;
    }

    return $data;
  }

  static function getFrontendApp() {
    return [
      'appData' => self::getAppQueryData(),
      'trackers' => EDTrackers::collectAll(),
    ];
  }
}
