<?php

function ed_detect_integrations() {
  if (defined('SLIM_SEO_DIR')) {
    include('integration-slim-seo.php');
  }
  if (defined('GF_PLUGIN_DIR_PATH')) {
    include('integration-gravity-forms.php');
  }
}
