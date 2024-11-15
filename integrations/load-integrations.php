<?php

function ed_detect_integrations() {
  if (defined('SLIM_SEO_DIR')) {
    include('integration-slim-seo.php');
  }
}
