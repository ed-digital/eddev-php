<?php

  class ACFSyncEnforcer {
    static function setup() {
      add_action('current_screen', function() {
        $acf = acf_get_instance('ACF_Admin_Field_Groups');
        if ($_GET['post']) {
          $post = get_post($_GET['post']);
          if ($post->post_type == 'acf-field-group') {
            $acf->setup_sync();
            $acf->check_sync();
            $acf->check_duplicate();
            dump($acf->sync);
            exit;
            foreach ($acf->sync as $sync) {
              if ($sync['ID'] == $_GET['post'])  {
                add_action('admin_notices', function() use($acf) {
                  echo '<div class="notice notice-error"><p>Dan here — this field group has been modified externally by Git, and needs to be synced before it can be edited.<br /><a href="/wp-admin/edit.php?post_type=acf-field-group&post_status=sync">Continue</a></p></div>';
                  echo '<style>#poststuff { display: none; }</style>';
                  // echo $acf->render_admin_table_column_local_status($sync);
                });
              }
            }
          }
        }
      }, 0, 10000);
    }
  }

  ACFSyncEnforcer::setup();