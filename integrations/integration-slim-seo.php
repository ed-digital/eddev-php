<?php

class SlimSEOIntegration {
  static function init() {
    add_action('slim_seo_init', function ($plugin) {
      $plugin->disable('breadcrumbs');
      $plugin->disable('notifications');
      $plugin->disable('feed');
      $plugin->disable('settings_term');
      if (!is_admin()) {
        $plugin->disable('code');
      }
    });

    add_action('graphql_register_types', function () {
      register_graphql_object_type("SlimSEOPostMeta", [
        "fields" => [
          "metaTitle" => [
            "type" => "String",
            "resolve" => function ($meta) {
              return $meta->getMetaTitle();
            }
          ],
          "metaDescription" => [
            "type" => "String",
            "resolve" => function ($meta) {
              return $meta->getMetaDescription();
            }
          ],
          "openGraphImageUrl" => [
            "type" => "String",
            "args" => [
              "fallbackToDefault" => [
                "type" => "Boolean",
                "defaultValue" => false,
                "description" => "Whether to fall back to the default image if no per-post image is set.",
              ],
            ],
            "resolve" => function ($meta, $args) {
              $image = $meta->getOpenGraphImage();

              if ($image && $image['isFallback'] && !$args['fallbackToDefault']) {
                return null;
              }

              if ($image && isset($image['url'])) {
                return $image['url'];
              }
              return null;
            }
          ],
          "openGraphImage" => [
            "type" => "MediaItem",
            "args" => [
              "fallbackToDefault" => [
                "type" => "Boolean",
                "defaultValue" => false,
                "description" => "Whether to fall back to the default image if no per-post image is set.",
              ],
            ],
            "resolve" => function ($meta, $args) {
              $image = $meta->getOpenGraphImage();

              if ($image && $image['isFallback'] && !$args['fallbackToDefault']) {
                return null;
              }

              if (isset($image['image']['id'])) {
                return new WPGraphQL\Model\Post(get_post($image['image']['id']));
              }
              return null;
            }
          ],
          "canonicalUrl" => [
            "type" => "String",
            "resolve" => function ($meta) {
              return $meta->getCanonicalURL();
            }
          ],
          "hiddenFromSearch" => [
            "type" => "Boolean",
            "resolve" => function ($meta) {
              return $meta->getIsHidden();
            }
          ]
        ]
      ]);

      register_graphql_field("ContentNode", "slimSEOMeta", [
        "type" => "SlimSEOPostMeta",
        "resolve" => function ($post) {
          return new SlimSEOPostMeta($post->ID);
        }
      ]);
    });

    add_filter('slim_seo_post_content', function ($content, $post) {
      $excerpt = get_the_excerpt($post);
      return $excerpt;
    }, 10, 2);

    add_action('pre_update_option_ss_redirects', function ($items) {
      foreach ($items as &$item) {
        $item['ignoreParameters'] = 1;
      }
      return $items;
    }, -1);

    add_filter("slim_seo_schema_author_enable", '__return_false');
    add_filter('slim_seo_meta_author', '__return_false');
    add_filter('slim_seo_linkedin_author', '__return_false');

    add_action('wp_head', function () {
      $post = get_queried_object();
      $image = apply_filters('ed_seo_image', null, $post);
      if ($image && @isset($image['url'])) {
        add_filter('slim_seo_open_graph_image', function ($value, $tag) use ($image) {
          return @$image['url'];
        }, 10, 2);
        add_filter('slim_seo_open_graph_image_width', function ($value, $tag) use ($image) {
          return @$image['width'];
        }, 10, 2);
        add_filter('slim_seo_open_graph_image_height', function ($value, $tag) use ($image) {
          return @$image['height'];
        }, 10, 2);
        add_filter('slim_seo_open_graph_image_alt', function ($value, $tag) use ($image) {
          return @$image['alt'];
        }, 10, 2);
      }
    }, -1);

    add_action('ed_print_trackers_head', function () {
      $result = get_option('slim_seo');
      if (isset($result['header_code'])) {
        echo $result['header_code'];
      }
    });

    add_action('ed_print_trackers_body', function () {
      $result = get_option('slim_seo');
      if (isset($result['body_code'])) {
        echo $result['body_code'];
      }
    });

    add_action('ed_print_trackers_footer', function () {
      $result = get_option('slim_seo');
      if (isset($result['footer_code'])) {
        echo $result['footer_code'];
      }
    });
  }
}

SlimSEOIntegration::init();

class SlimSEOPostMeta {
  public $id;

  private $post;
  private $meta;
  private $option;

  public function __construct($id) {
    $this->id     = (int) $id;
    $this->post   = get_post($this->id);
    $this->meta   = $this->post ? (get_post_meta($this->id, 'slim_seo', true) ?: []) : [];
    $this->option = get_option('slim_seo', []) ?: [];
  }

  public function getMetaTitle() {
    if (! $this->post) {
      return '';
    }
    $title = new \SlimSEO\MetaTags\Title();
    return $title->get_rendered_singular_value($this->id);
  }

  public function getMetaDescription() {
    if (! $this->post) {
      return '';
    }
    $description = new \SlimSEO\MetaTags\Description();
    return $description->get_rendered_singular_value($this->id);
  }

  public function getOpenGraphImage() {
    return $this->resolveImage('facebook_image', 'default_facebook_image', 'slim_seo_open_graph_image');
  }

  public function getTwitterImage() {
    return $this->resolveImage('twitter_image', 'default_twitter_image', 'slim_seo_twitter_card_image');
  }

  public function getCanonicalURL() {
    if (! $this->post) {
      return null;
    }

    $url = ! empty($this->meta['canonical'])
      ? $this->meta['canonical']
      : (string) get_permalink($this->post);

    $url = (string) apply_filters('slim_seo_canonical_url', $url, $this->id);
    $url = \SlimSEO\MetaTags\Helper::render($url, $this->id);

    return ($url && filter_var($url, FILTER_VALIDATE_URL)) ? $url : null;
  }

  public function getIsHidden() {
    if (! $this->post) {
      return false;
    }
    // Robots' constructor needs a CanonicalUrl, but get_singular_value() doesn't use it.
    $robots = new \SlimSEO\MetaTags\Robots(new \SlimSEO\MetaTags\CanonicalUrl());
    $value  = $robots->get_singular_value($this->id);
    return (bool) apply_filters('slim_seo_robots_index', ! $value, $this->id) ? false : true;
    // Note: slim_seo_robots_index returns the "should index" value; invert for "is hidden".                                                          
  }

  private function resolveImage($meta_key, $default_option_key, $filter) {
    if (! $this->post) {
      return null;
    }

    $image_obj = new \SlimSEO\MetaTags\Image($meta_key);
    $image = [];
    $isFalback = false;

    // 1. Per-post override.
    if (isset($this->meta[$meta_key]) && $this->meta[$meta_key] !== '') {
      $image = $image_obj->get_data_from_url(
        $this->renderIfTemplate($this->meta[$meta_key])
      );
    }

    // 2. Per-post-type setting.                    
    if (empty($image) && ! empty($this->option[$this->post->post_type][$meta_key])) {
      $image = $image_obj->get_data_from_url(
        $this->renderIfTemplate($this->option[$this->post->post_type][$meta_key])
      );
    }

    // 3. Featured image / first image in content.
    if (empty($image)) {
      $candidates = \SlimSEO\Helpers\Images::get_post_images($this->post);
      if (!empty($candidates)) {
        $first = reset($candidates);
        $image = is_numeric($first)
          ? $this->imageDataFromAttachment((int) $first, $image_obj)
          : $image_obj->get_data_from_url($first);
      }
    }

    // 4. Site-wide default.
    if (empty($image) && ! empty($this->option[$default_option_key])) {
      $image = $image_obj->get_data_from_url(
        $this->renderIfTemplate($this->option[$default_option_key])
      );
      $isFalback = true;
    }

    $url = $image['src'] ?? null;
    $url = apply_filters($filter, $url);

    return [
      "url" => $url,
      "image" => $image,
      "isFallback" => $isFalback,
    ];
  }

  private function imageDataFromAttachment($id, \SlimSEO\MetaTags\Image $image_obj) {
    $src = wp_get_attachment_image_src($id, 'full');
    if (! $src) {
      return [];
    }
    return $image_obj->get_data_from_url($src[0]);
  }

  private function renderIfTemplate($value) {
    if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
      return \SlimSEO\MetaTags\Helper::render((string) $value, $this->id);
    }
    return $value;
  }
}
