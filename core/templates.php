<?php

use ED\AssetManifest;

class EDTemplates {

  static $templates;

  static function init() {
    self::hookViewResponder();
    self::hookViews();
    self::hookPageTemplates();

    add_filter('wp_redirect', [__CLASS__, 'handleRedirect'], 10, 2);
  }

  static function handleRedirect($location, $status) {
    // Strip out ?_props=... from any redirect URLs
    if (preg_match("/_props/", $location)) {
      $location = preg_replace("/\?.*$/", "", $location);
    }
    return $location;
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

      // Is this a JSON request? Or a regular page view?
      $isPropsRequest = isset($_GET['_props']) && !!$_GET['_props'];
      $handleAssets = !$isPropsRequest;
      $includeAppData = (isset($_GET['_props']) && $_GET['_props'] === 'all') || !$isPropsRequest;
      $debugQueries = $isPropsRequest && (ED()->isDev || isset($_GET['_debug']));

      // Are we redirecting?
      $redirect = apply_filters('ed_maybe_redirect', null);
      if ($redirect && isset($redirect['url'])) {
        if ($isPropsRequest) {
          header('Content-type: text/json');
          echo json_encode([
            'redirect' => $redirect['url'],
            'status' => isset($redirect['status']) ? $redirect['status'] : 301
          ]);
          exit;
        } else {
          wp_redirect($redirect['url'], $redirect['status'] ?? 301);
          exit;
        }
      }

      $isJSX = preg_match("/\.(tsx|ts|jsx|js)$/", $template);
      $templateFile = trim(str_replace(ED()->sitePath, "", str_replace(ED()->themePath, "", $template)), "/");

      // Cache and generation headers, for non-logged-in users
      $query = new \ED\GraphQLQuery($templateFile, self::getQueryParams());

      if ($debugQueries) {
        // set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        //   if ($errno === 2) return;
        //   QueryMonitor::logError([
        //     'type' => 'php',
        //     'code' => $errno,
        //     'message' => $errstr,
        //     'file' => str_replace(ED()->sitePath, "", $errfile),
        //     'line' => $errline
        //   ]);
        // });
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

      // Send cache headers
      $query->sendCacheHeaders();

      $data['viewData'] = $query->getResult();
      if ($includeAppData) {
        $appQuery = new ED\GraphQLQuery("views/_app");
        $data['appData'] = $appQuery->getResult();
      }

      if (is_404()) {
        if (!$data['viewData']) $data['viewData'] = ['data' => []];
        /** import { ErrorRouteProps } from 'eddev/routing' */
        $errorInfo = [
          'statusCode' => 404,
          'title' => 'Page Not Found',
          'message' => 'The page you are looking for does not exist.'
        ];
        $data['viewData']['data'] = @array_merge($data['viewData']['data'] ?? [], $errorInfo);
      }

      if ($isJSX) {
        $data['viewType'] = 'react';
        AssetManifest::importChunk($templateFile, "modulepreload");
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

      if (!defined('DISABLE_QUERY_MONITOR')) {
        $data['queryMonitor'] = QueryMonitor::getResult();
      }

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

    // Grab the body contents
    ob_start();
    wp_body_open();
    $body = ob_get_contents();
    ob_end_clean();

    return [
      'head' => self::parseHeaderHTML($head),
      'body' => self::parseHeaderHTML($body),
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
      // if (($tag->tagName === 'link' && @$tag->attributes['rel'] !== 'icon') || @preg_match("/(wp-includes|wp-json|xmlrpc)/", @$tag->attributes["href"])) {
      //   $ignore = true;
      // } else if ($tag->tagName === 'script' || @preg_match("/(wp-includes|wp-json|xmlrpc)/", @$tag->attributes["src"])) {
      //   $ignore = true;
      // } else if ($tag->tagName === 'meta') {
      //   if (@$tag->attributes['name'] === "generator") {
      //     $ignore = true;
      //   }
      // }

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

  static function getAppQueryData() {
    $queryFile = "views/_app.graphql";

    $query = new ED\GraphQLQuery($queryFile);
    return $query->getResult();
  }

  static function getFrontendApp() {
    return [
      'appData' => self::getAppQueryData(),
      'trackers' => EDTrackers::collectAll()
    ];
  }
}
