<?php

namespace Statamic;

class Exporter
{
    const PREFIX = 'statamic-json';

    protected $filename;
    protected $file;
    protected $collections = array();
    protected $pages       = array();
    protected $settings    = array();
    protected $taxonomies  = array();

    public function __construct()
    {
        $this->filename = 'statamic_' . date('Ymd_His') . '.json';
        $this->file     = ABSPATH . '/' . static::PREFIX . '/' . $this->filename;
    }

    public function content($types)
    {
        array_walk($types, function ($type) {
            call_user_func([$this, 'set' . ucfirst($type)]);
        });

        return $this;
    }

    public function customPostTypes($postTypes)
    {
        array_walk($postTypes, function ($type) {
			if ($type === 'product') {
				$this->setProducts();
			} else {
				$this->setPosts($type);
			}
        });

        return $this;
    }
	
	private function getAttachedTaxonomies($ID, $type, $slug, $post_name) {
		$attachedTaxonomies = get_object_taxonomies( $type );
		
		foreach($attachedTaxonomies as $taxonomy) {
        $terms = get_the_terms( $ID, $taxonomy );

        if ( $terms && ! is_wp_error( $terms ) ) {
            $this->collections[$slug]["/{$slug}/" . $post_name]['data']['taxonomies'][$taxonomy] = [];
            foreach ( $terms as $term ) {
              array_push($this->collections[$slug]["/{$slug}/" . $post_name]['data']['taxonomies'][$taxonomy], $term->slug);
            }
        }
    }
	}

    private function setPosts($type = 'post')
    {
        $postType = get_post_type_object($type);
        $slug     = $postType->name;

        if ($postType->rewrite) {
            $slug = $postType->rewrite['slug'];
        }

        $posts = get_posts(array(
            'post_type'      => $postType->name,
            'post_status'    => 'publish',
            'posts_per_page' => -1
        ));

        foreach ($posts as $post) {
          $author = null;
			    $content = apply_filters( 'the_content', $post->post_content );

          // var_dump($content); exit;

          if ($post->post_author) {
              $author = get_userdata($post->post_author)->user_login;
          }

          $this->collections[$slug]["/{$slug}/" . $post->post_name] = array(
              'order' => date("Y-m-d", strtotime($post->post_date)),
              'data'  => array(
                  'title'   => $post->post_title,
                  'content' => trim(strip_shortcodes(strip_tags($content, "<br><p><img><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><em><strong><table><thead><tbody><th><td><tr><a><button><canvas><cite><i><small><strong><sub><sup>"))),
                  'author'  => $author,
                  'featured_image_url' => get_the_post_thumbnail_url($post->ID, 'full')
              ),
          );

          $this->getAttachedTaxonomies($post->ID, $type, $slug, $post->post_name);
			
          if (get_field('gallery_slider', $post->ID)) {
            $this->collections[$slug]["/{$slug}/" . $post->post_name]['data']['project_gallery'] = array_map(function($item) {
              return ["id" => $item['id'], "url" => $item['url'], "alt" => $item['alt'], "caption" => $item['caption']];
            }, get_field('gallery_slider', $post->ID));
          }

          foreach ($this->metadata('post', $post) as $key => $meta) {
              $this->collections[$slug]["/{$slug}/" . $post->post_name]['data'][$key] = reset($meta);
          }
        }
    }
	
	private function setProducts($type = 'product')
    {
        $postType = get_post_type_object($type);
        $slug     = $postType->name;

        if ($postType->rewrite) {
            $slug = $postType->rewrite['slug'];
        }

        $posts = get_posts(array(
            'post_type'      => $postType->name,
            'post_status'    => 'publish',
            'posts_per_page' => -1
        ));

        foreach ($posts as $post) {
            $author = null;
			
			$content = str_replace("\r\n","", $post->post_content);
			
// 			$unwanted_tags = [
//     			'ul',
//     			'h3',
// 			];
			
// 			foreach ( $unwanted_tags as $tag ) {
// 				$content = preg_replace( "/(<$tag>(.*?)<\/$tag>)/is", '', $content );
// 			}
			
			$content = apply_filters( 'the_content', $post->post_content);
			
            if ($post->post_author) {
                $author = get_userdata($post->post_author)->user_login;
            }
			
// 			print_r(explode(",", $product_gallery_IDs[0])); exit();

            $this->collections[$slug]["/{$slug}/" . $post->post_name] = array(
                'order' => date("Y-m-d", strtotime($post->post_date)),
                'data'  => array(
                    'title'   => $post->post_title,
					'product_code' => get_post_meta($post->ID, '_sku', true),
                    'content' => trim(strip_shortcodes(strip_tags($content, "<br><p><img><h1><h2><h3><ul><ol><li><h4><h5><h6><blockquote><em><strong><table><thead><tbody><th><td><tr><a><button><canvas><cite><i><small><strong><sub><sup>"))),
                    'author'  => $author,
                    'featured_image_url' => get_the_post_thumbnail_url($post->ID, 'full'),
                    'product_360_view' => get_field('3d', $post->ID),
					'specifications' => get_field('spec_page_link', $post->ID),
					'plan_view' => array_map(function($item) {
						if (!$item) return;
						return ["image_url" => $item['image']['url'], "title" => $item['title']];
					}, get_field('plan_view', $post->ID ) ?? []) ?? null,
					'product_downloads' => array_map(function($item) {
						if (!$item) return;
						return ["download_url" => $item['pdf_link']];
					}, get_field('download', $post->ID ) ?? []) ?? null,
                    'product_gallery' => array_map(function($item) {
                      return ["url" => wp_get_attachment_image_url($item, $post->ID)];
                    }, explode(",", get_post_meta($post->ID, '_product_image_gallery')[0])) ?? null,
                ),
            );

            $this->getAttachedTaxonomies($post->ID, $type, $slug, $post->post_name);
        }
    }

    private function setPages()
    {
        $pages = get_posts(array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => -1
        ));

        foreach ($pages as $page) {
            $this->pages['/' . $page->post_name] = array(
                'order' => $page->menu_order,
                'data'  => array(
                    'title'        => $page->post_title,
                    'content'      => $page->post_content,
                    'featured_image_url' => get_the_post_thumbnail_url($page->ID, 'full')
                ),
            );

            foreach ($this->metadata('post', $page) as $key => $meta) {
                $this->pages['/' . $page->post_name]['data'][$key] = reset($meta);
            }
        }
    }

    private function setTaxonomies()
    {
		// Changed to pull ALL taxonomies, not just categories
        $taxonomies = get_taxonomies();
        $tags       = get_tags(array('hide_empty' => false));
		
		foreach($taxonomies as $taxonomy) {
			$terms = get_terms(['taxonomy' => $taxonomy]);
      
			$this->taxonomies[$taxonomy] = $this->mapWithKeys($terms, function ($term) use($taxonomy) {
          if ($taxonomy === 'product_cat') {
            $thumb_id = get_woocommerce_term_meta( $term->term_id, 'thumbnail_id', true );
            $term_img = wp_get_attachment_url(  $thumb_id );
			
          }
            	return array($term->slug => array('title' => $term->name, 'featured_image_url' => isset($term_img) ? $term_img : get_the_post_thumbnail_url($term->term_id, 'full')));
        	});
		}

        $this->taxonomies['tags'] = $this->mapWithKeys($tags, function ($tag) {
            return array($tag->slug => array('title' => $tag->name));
        });
    }

    private function setSettings()
    {
        $this->settings['site_url']  = get_option( 'siteurl' );
        $this->settings['site_name'] = get_bloginfo('name');
        $this->settings['timezone']  = ini_get('date.timezone');
    }

    private function json()
    {
        $content = array();

        if (! empty($this->collections)) {
            $content['collections'] = $this->collections;
        }

        if (! empty($this->pages)) {
            $content['pages'] = $this->pages;
        }

        if (! empty($this->taxonomies)) {
            $content['taxonomies'] = $this->taxonomies;
        }

        if (! empty($this->settings)) {
            $content['settings'] = $this->settings;
        }

        return json_encode($content, JSON_PRETTY_PRINT);
    }

    public function export()
    {
        $this->createExportDirectory();

        $handle = fopen($this->file, 'w') or die('fail');

        fwrite($handle, $this->json());

        return $this;
    }

    public function download()
    {
        header("Content-Type: application/octet-stream");
        header("Content-Transfer-Encoding: Binary");
        header("Content-disposition: attachment; filename={$this->filename}");

        ob_clean();

        flush();

        readfile($this->file);
    }

    private function createExportDirectory()
    {
        if (file_exists(ABSPATH . static::PREFIX)) {
            return;
        }

        mkdir(ABSPATH . static::PREFIX, 0777, true);
    }

    private function metadata($type, $post)
    {
        if (! $metadata = get_metadata($type, $post->ID)) {
            return array();
        }

        return $metadata;
    }

    private function mapWithKeys($array, $callable)
    {
        return array_reduce(
            $array,
            function ($collection, $item) use ($callable) {
                $result = $callable($item);

                $collection[key($result)] = reset($result);

                return $collection;
            },
            array()
        );
    }
}
