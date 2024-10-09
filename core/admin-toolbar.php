<?php

class EDAdminToolbar {

  static function init() {
    add_action('wp_after_admin_bar_render', [__CLASS__, 'addHiderStyles']);
  }

  static function addHiderStyles() {
    if (is_admin()) return;
?>
    <style>
      html,
      html body,
      * html body {
        margin-top: 0 !important
      }

      html #wpadminbar {
        opacity: 0;
        -webkit-transition: all .3s ease;
        transition: all .3s ease;
      }

      html #wpadminbar:hover {
        opacity: 1;
      }

      html #wpadminbar {
        top: -15px !important;
        -webkit-transition-delay: 200ms;
        transition-delay: 200ms;
      }

      html #wpadminbar:hover {
        top: 0 !important
      }
    </style>
<?php
  }
}

EDAdminToolbar::init();
