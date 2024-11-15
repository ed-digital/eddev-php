<?php

class ED_SEO {
  static function init() {
    add_filter('post_thumbnail_id', [__CLASS__, '_filter_post_thumbnail'], 10, 2);
  }

  static function _filter_post_thumbnail($thumbnail_id, $post) {
    if ($post && isset($post->post_type)) {
      $type = get_post_type_object($post->post_type);
      if ($type && isset($type->seo) && isset($type->seo['thumbnail_id'])) {
        $lookup = $type->seo['thumbnail_id'];
        if (is_string($lookup)) {
          $thumbnail_id = get_post_meta($post->ID, $lookup, true);
        } else if (is_callable($lookup)) {
          $thumbnail_id = call_user_func($lookup, $post);
        }
      }
    }
    return $thumbnail_id;
  }
}

ED_SEO::init();
