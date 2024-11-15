<?php

class ED_SEO {
  static function init() {
    add_filter('post_thumbnail_id', [__CLASS__, '_filter_post_thumbnail'], 10, 2);
    add_filter('get_the_excerpt', [__CLASS__, '_filter_excerpt'], 10, 2);
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

  static function _filter_excerpt($excerpt, $post) {
    if ($post && isset($post->post_type)) {
      $type = get_post_type_object($post->post_type);
      if (!$excerpt && $type && isset($type->seo) && isset($type->seo['excerpt'])) {
        $lookup = $type->seo['excerpt'];
        if (is_string($lookup)) {
          $excerpt = get_post_meta($post->ID, $lookup, true);
        } else if (is_callable($lookup)) {
          $excerpt = call_user_func($lookup, $post);
        }
      }
      if (!$excerpt && $post->post_content) {
        $extractor = new EDContentExtractor([
          'post' => $post,
          'blocks' => @parse_blocks($post->post_content) ?? []
        ]);
        $extractor->process();
        return $extractor->getExcerpt();
      }
    }
    return $excerpt;
  }
}

ED_SEO::init();
