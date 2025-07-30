<?php

namespace ED;

class GraphQLQuery {

  public $type = "";
  public $name = "";
  public $queryText = "";
  public $cacheTime = 0;
  public $variables = [];
  public $decorator = null;
  public $decoratorCacheKey = '';

  private $monitorEntry = null;
  private $cache = null;

  public function __construct($name, $variables = []) {
    $this->name = self::normalizeQueryName($name);
    $this->type = self::getQueryType($this->name);
    $this->variables = $variables;

    $this->queryText = QueryLoader::load($this->name);
    $this->cacheTime = self::getCacheTime($this->name, $this->queryText);
  }

  public function setDecorator($cacheKey, $decorator) {
    $this->decoratorCacheKey = $cacheKey;
    $this->decorator = $decorator;
  }

  public function prepare() {
    $key = $this->getCacheKey();
    if ($this->cacheTime > 0 && ED()->getCacheConfig(("wordpress.transients"))) {
      if (!$this->cache) {
        $this->cache = new \EDCache($key);
      }
      if ($this->cache->hasValue()) {
        return false;
      }
    }
    return true;
  }

  public function exists() {
    return $this->queryText ? true : false;
  }

  public function getResult() {
    $mustExecute = $this->prepare();

    // If data caching is appropriate, check the cache
    if ($mustExecute) {
      // Execute
      $result = $this->executeQuery();
      $result['__originGenerated'] = date('r');

      // Save to cache
      if ($this->cache) {
        $this->cache->setValue([
          'value' => $result,
          'log' => $this->monitorEntry
        ], $this->cacheTime);
      }
    } else if ($this->cache && $this->cache->hasValue()) {
      $cacheEntry = $this->cache->getValue();
      $cacheEntry['value']['_from_cache'] = true;

      // If the cache entry has a QueryMonitor log entry, add it to the global log as-is
      if (isset($cacheEntry['log']) && is_object($cacheEntry['log'])) {
        \QueryMonitor::add($cacheEntry['log']);
      }

      $result = $cacheEntry['value'];
    }

    return $result;
  }

  public function getCacheKey() {
    $key = "bv:" . $this->name . ":" . $this->decoratorCacheKey . ':' . md5(@$_SERVER['HTTP_HOST'] . $this->queryText . json_encode($this->variables));
    return $key;
  }

  private function executeQuery() {
    if (!$this->queryText) {
      $result = null;
    } else {
      \QueryMonitor::push($this->name, $this->type);
      $result = graphql([
        'query' => $this->queryText,
        'variables' => $this->variables
      ]);
      $this->monitorEntry = \QueryMonitor::pop();
    }

    if ($this->decorator) {
      $result = call_user_func($this->decorator, $result);
    }

    return $result;
  }

  public function shouldBypassCache() {
    $result = false;
    if (ED()->isDev) $result = true;
    if (\ED\early_user_logged_in() || current_user_can("edit_posts")) $result = true;
    return $result;
  }

  public function getCacheTime() {
    // Allow bypassing of the cache
    $bypass = $this->shouldBypassCache();
    if ($bypass) {
      return 0;
    }

    // Determine the cache time based on the query type
    $cacheTime = 0;
    if ($this->type === "query") {
      $cacheTime = ED()->getCacheConfig("queryHooksTTL");
    } else if ($this->type === "page") {
      $cacheTime = ED()->getCacheConfig("pageDataTTL");
    } else if ($this->type === "app") {
      $cacheTime = ED()->getCacheConfig("appDataTTL");
    }

    // Allow overriding the cache time in the query itself
    if (is_string($this->queryText)) {
      if (preg_match("/#\s+ttl[=:\s]+([0-9]+)/", $this->queryText, $matches)) {
        $cacheTime = (int)trim($matches[1]);
      } else if (strpos($this->queryText, 'nocache') > 0) {
        $cacheTime = 0;
      }
    }

    return $cacheTime;
  }

  private static function normalizeQueryName($name) {
    $name = str_replace(ED()->themePath, '', $name);
    $name = preg_replace('/\.[a-z]+$/', '', $name) . ".graphql";
    $name = preg_replace('/^\//', '', $name);
    return $name;
  }

  private static function getQueryType($name) {
    if (strpos($name, "queries/") === 0) {
      return "query";
    } else if (strpos($name, "views/") === 0) {
      if (strpos($name, "_app")) {
        return "app";
      }
      return "page";
    } else if (strpos($name, "blocks/") === 0) {
      return "block";
    }
    return null;
  }

  public function sendCacheHeaders() {
    $cacheTime = $this->cacheTime;
    if (!ED()->getCacheConfig("wordpress.cacheHeaders")) {
      $cacheTime = 0;
    }

    if ($cacheTime > 0) {
      header('Cache-Control: public, max-age=' . $cacheTime);
    } else {
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    header('X-ED-Cache-Duration: ' . (int)$cacheTime);
    header('X-ED-Generated-At: ' . date("r"));
  }
}

class QueryLoader {
  static $cache = [];

  static private function loadByName($name) {
    $optimizedFile = ED()->themePath(".eddev/queries/" . str_replace(".graphql", ".txt", $name));
    $optimizedFileLegacy = ED()->themePath(".eddev/queries/" . $name);
    $defaultFile = ED()->themePath($name);
    if (file_exists($optimizedFile)) {
      return file_get_contents($optimizedFile);
    } else if (file_exists($optimizedFileLegacy)) {
      return file_get_contents($optimizedFileLegacy);
    } else if (file_exists($defaultFile)) {
      // Load the source file and append the fragments
      return file_get_contents($defaultFile) . "\n\n" . FragmentLoader::getAll();
    }
    return null;
  }

  static function load($file) {
    $file = str_replace(ED()->themePath, '', $file);
    if (isset(self::$cache[$file])) {
      return self::$cache[$file];
    }
    $value = self::loadByName($file);
    if (is_string($value)) {
      self::$cache[$file] = $value;
      return self::$cache[$file];
    } else {
      return null;
    }
  }
}

class FragmentLoader {

  static $cache;

  static function getAll() {
    if (self::$cache) return self::$cache;
    $fragments = array_merge(
      glob(ED()->themePath . "/queries/fragments/*/*.graphql"),
      glob(ED()->themePath . "/queries/fragments/*.graphql")
    );
    $output = [];
    foreach ($fragments as $frag) {
      $output[] = file_get_contents($frag);
    }
    return "\n\n" . implode("\n\n", $output);
  }

  static function getOptimized() {
    $file = ED()->themePath(".eddev/queries/fragments.json");
    if (file_exists($file)) {
      return json_decode(file_get_contents($file), true);
    } else {
      return [];
    }
  }
}
