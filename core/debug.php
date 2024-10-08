<?php

$lastTime = microtime(true);

function logTime($message) {
  global $lastTime;
  $duration = round(microtime(true) - $GLOBALS['lastTime'], 3);
  print($message . ": " . $duration . "ms\n");
  $lastTime = microtime(true);
}
