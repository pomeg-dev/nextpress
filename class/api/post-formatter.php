<?php
/**
 * Post formatter class
 * Formats WP post objects for consumption by nextjs
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class Post_Formatter {
  /**
   * Main formatter function
   */
  public function format_post( $post, $include_content = false, $include_metadata = true ) {
    $formatted_post = [
      'id' => $post->ID,
      'slug' => $this->get_slug( $post ),
      'type' => $this->get_post_type_props( $post ),
      'status' => $post->post_status,
      'date' => $post->post_date,
      'title' => $post->post_title,
      'excerpt' => ! empty( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_excerpt( '', $post ),
      'image' => $this->get_post_image( $post ),
      'categories' => $this->get_post_categories( $post ),
      'tags' => $this->get_post_tags( $post ),
      'password' => $post->post_password,
    ];

    if ( $include_content ) {
      $template = $this->get_template_content( $post );
      $formatted_post['template'] = $template;
      $formatted_post['content'] = $this->parse_block_data( $post->post_content );
    }

    $formatted_post = $this->include_featured_image( $formatted_post );
    $formatted_post = $this->include_author_name( $formatted_post );
    $formatted_post = $this->include_is_homepage( $formatted_post );
    $formatted_post = $this->include_category_names( $formatted_post );
    $formatted_post = $this->include_tax_terms($formatted_post);
    $formatted_post = $this->include_post_path( $formatted_post );
    $formatted_post = $this->include_breadcrumbs( $formatted_post );
    $formatted_post = $this->return_post_revision_for_preview( $formatted_post );

    if ( $include_metadata ) {
      $formatted_post = apply_filters( 'nextpress_post_object_w_meta', $formatted_post );
    }
    
    return apply_filters( 'nextpress_post_object', $formatted_post );
  }

  public function get_slug( $post ) {
    return [
      'slug' => is_numeric( $post ) ? $post : $post->post_name,
      'full_path' => $this->get_full_path( $post ),
    ];
  }

  private function get_full_path( $post ) {
    $permalink = get_permalink( $post );
    return str_replace( home_url(), '', $permalink );
  }

  private function get_post_type_props( $post ) {
    $post_type = get_post_type_object( $post->post_type );
    return [
      'id' => $post_type->name,
      'name' => $post_type->labels->singular_name,
      'slug' => $post_type->rewrite ? $post_type->rewrite['slug'] : '',
    ];
  }

  private function get_post_image( $post ) {
    $image_id = get_post_thumbnail_id( $post->ID );
    if ( ! $image_id ) {
      return null;
    }

    return [
      'full' => wp_get_attachment_image_url($image_id, 'full'),
      'thumbnail' => wp_get_attachment_image_url($image_id, 'thumbnail'),
    ];
  }

  private function get_post_categories( $post ) {
    $categories = wp_get_post_categories(
      $post->ID,
      [ 'fields' => 'all' ]
    );
    return array_map( function ( $category ) {
      return [
        'id' => $category->term_id,
        'name' => $category->name,
        'slug' => $category->slug,
      ];
    }, $categories );
  }

  private function get_post_tags( $post ) {
    $tags = wp_get_post_tags( $post->ID );
    return array_map( function ( $tag ) {
      return [
        'id' => $tag->term_id,
        'name' => $tag->name,
        'slug' => $tag->slug,
      ];
    }, $tags );
  }

  private function get_template_content( $post ) {
    $post_type = get_post_type( $post );
    $post_categories = wp_get_post_categories( $post->ID, ['fields' => 'ids'] );

    // Try to get post type specific templates
    $templates = get_field( "{$post_type}_content_templates", 'option' );

    if ( $templates ) {
      $default_template = null;

      foreach ( $templates as $template ) {
        if ( empty( $template['category'] ) ) {
          $default_template = $template;
        } elseif ( in_array( $template['category'], $post_categories ) ) {
          return [
            'before_content' => $this->format_flexible_content( $template['before_content'] ),
            'after_content' => $this->format_flexible_content( $template['after_content'] ),
            'sidebar_content' => $this->format_flexible_content( $template['sidebar_content'] ),
          ];
        }
      }

      // If no category-specific template was found, use the default for this post type
      if ( $default_template ) {
        return [
          'before_content' => $this->format_flexible_content( $default_template['before_content'] ),
          'after_content' => $this->format_flexible_content( $default_template['after_content'] ),
          'sidebar_content' => $this->format_flexible_content( $default_template['sidebar_content'] ),
        ];
      }
    }

    // If no template found for the post type, use the global default
    $default_before_content = get_field( 'default_before_content', 'option' );
    $default_after_content = get_field( 'default_after_content', 'option' );

    return [
      'before_content' => $this->format_flexible_content( $default_before_content ),
      'after_content' => $this->format_flexible_content( $default_after_content ),
    ];
  }

  public function format_flexible_content( $flexible_content ) {
    if ( ! is_array( $flexible_content ) ) {
      return [];
    }

    $formatted_content = [];

    foreach ( $flexible_content as $block ) {
      $block_data = isset( $block['attrs']['data'] ) 
        ? $block['attrs']['data'] 
        : [];

      if ( ! $block_data ) {
        $block_data = $block;
        unset( $block_data['acf_fc_layout'] );
      }

      $formatted_block = [
        'id' => uniqid('acf_'), // Generate a unique ID for ACF blocks
        'blockName' => 'acf/' . $block['acf_fc_layout'],
        'slug' => 'acf-' . str_replace( '_', '-', $block['acf_fc_layout'] ),
        'innerHTML' => '',
        'innerContent' => [],
        'type' => [
          'id' => 0,
          'name' => ucfirst( str_replace( '_', ' ', $block['acf_fc_layout'] ) ),
          'slug' => 'acf/' . str_replace( '_', '-', $block['acf_fc_layout'] )
        ],
        'parent' => 0,
        'innerBlocks' => [],
        'data' => apply_filters( 'nextpress_block_data', $block_data, $block ),
      ];

      $formatted_content[] = $formatted_block;
    }

    return $formatted_content;
  }

  public function parse_block_data( $content ) {
    $blocks = parse_blocks( $content );
    return $this->format_blocks( $blocks );
  }

  private function format_blocks( $blocks, $parent = 0 ) {
    $formatted_blocks = [];

    // Remove all blockName = null.
    $blocks = array_filter( $blocks, function ( $block ) {
      return $block['blockName'] !== null;
    });

    foreach ( $blocks as $index => $block ) {
      $block_data = isset( $block['attrs']['data'] ) 
        ? $block['attrs']['data'] 
        : $block['attrs'];

      $block_id = isset( $block['attrs']['anchor'] ) 
        ? $block['attrs']['anchor'] 
        : (
          isset( $block['attrs']['nextpress_id'] ) 
            ? $block['attrs']['nextpress_id']
            : $block['blockName']
        );

      if (
        ! isset( $block['attrs']['nextpress_id'] ) &&
        strpos( $block['blockName'], 'acf/' ) !== false
      ) {
        $block['attrs']['nextpress_id'] = uniqid();
      }

      $formatted_block = [
        'id' => $block_id,
        'blockName' => $block['blockName'],
        'slug' => sanitize_title( $block['blockName'] ),
        'innerHTML' => $block['innerHTML'],
        'innerContent' => $block['innerContent'],
        'type' => [
          'id' => $block_id,
          'name' => ucfirst( str_replace( 'core/', '', $block['blockName'] ) ),
          'slug' => str_replace( 'core/', '', $block['blockName'] ),
        ],
        'parent' => $parent,
        'innerBlocks' => [],
        'data' => apply_filters( 'nextpress_block_data', $block_data, $block ),
      ];

      // Add custom classname.
      if ( isset( $block['attrs']['className'] ) ) {
        $formatted_block['className'] = $block['attrs']['className'];
      }

      // Handle innerBlocks (default wp blocks like group etc)
      if ( ! empty( $block['innerBlocks'] ) ) {
        $formatted_block['innerBlocks'] = $this->format_blocks( $block['innerBlocks'], $formatted_block['id'] );
      }

      // Handle reusable blocks/patterns
      if ( $block['blockName'] == 'core/block' && $block['attrs']['ref'] ) {
        $pattern_blocks = parse_blocks( get_post( $block['attrs']['ref'] )->post_content );
        $formatted_block['innerBlocks'] = $this->format_blocks( $pattern_blocks, $formatted_block['id'] );
      }

      $formatted_blocks[] = $formatted_block;
    }

    return $formatted_blocks;
  }

  private function include_featured_image( $formatted_post ) {
    $formatted_post['featured_image'] = [
      'url' => get_the_post_thumbnail_url($formatted_post['id']),
      'sizes' => [
        'thumbnail' => get_the_post_thumbnail_url( $formatted_post['id'], 'thumbnail' ),
        'medium' => get_the_post_thumbnail_url( $formatted_post['id'], 'medium' ),
        'large' => get_the_post_thumbnail_url( $formatted_post['id'], 'large' ),
        'full' => get_the_post_thumbnail_url( $formatted_post['id'], 'full' ),
      ],
    ];
    return $formatted_post;
  }

  private function include_author_name( $formatted_post ) {
    $formatted_post['author'] = get_the_author_meta( "display_name", $formatted_post['id'] );
    return $formatted_post;
  }

  private function include_is_homepage( $formatted_post ) {
    $formatted_post['is_homepage'] = is_home() || is_front_page();
    return $formatted_post;
  }

  private function include_category_names( $formatted_post ) {
    $formatted_post['category_names'] = wp_get_post_categories( $formatted_post['id'], [ 'fields' => 'names' ] );
    return $formatted_post;
  }

  private function include_tax_terms( $formatted_post ) {
    $tax_args = [
      'public'   => true,
      '_builtin' => false
    ];
    $taxonomies = get_taxonomies( $tax_args );
    $custom_taxonomies = array_filter( $taxonomies, function( $taxonomy ) {
      return ! in_array( $taxonomy, ['category', 'post_tag'] );
    });

    foreach ( $custom_taxonomies as $taxonomy ) {
      $terms = get_the_terms( $formatted_post['id'], $taxonomy );
      if ( $terms && ! is_wp_error( $terms ) ) {
        foreach ( $terms as $term ) {
          $formatted_post['terms'][$taxonomy][] = $term->name;
        }
      }
    }
    return $formatted_post;
  }

  private function include_post_path( $formatted_post ) {
    $base_url = site_url();
    $post = get_post( $formatted_post['id'] );

    if ( in_array( $post->post_status, ['draft', 'pending', 'auto-draft', 'future', 'private'] ) ) {
      $my_post = clone $post;
      $my_post->post_status = 'publish';
      $my_post->post_name = sanitize_title(
        $my_post->post_name ? $my_post->post_name : $my_post->post_title,
        $my_post->ID
      );
      $permalink = get_permalink( $my_post );
    } else {
      $permalink = get_permalink( $post );
    }

    $formatted_post['path'] = str_replace( $base_url, '', $permalink );
    $formatted_post['wordpress_path'] = get_permalink( $post->ID );

    return $formatted_post;
  }

  private function include_breadcrumbs( $formatted_post ) {
    $post = get_post( $formatted_post['id'] );
    $breadcrumbs = '<nav class="breadcrumbs">';
    $breadcrumbs .= '<a href="' . home_url() . '">Home</a>';

    if ( $post->post_type === 'post' ) {
      $page_for_posts = get_option( 'page_for_posts' );

      $breadcrumbs .= ' | <a href="' . get_post_type_archive_link( $post->post_type ) . '">' . get_the_title( $page_for_posts ) . '</a>';

      $breadcrumbs .= ' | <span>' . get_the_title( $post ) . '</span>';
    } elseif ( $post->post_type === 'page' ) {
      if ( $post->post_parent ) {
        $parent_id = $post->post_parent;
        $parent_links = [];
        while ( $parent_id ) {
            $page = get_post( $parent_id );
            $parent_links[] = '<a href="' . get_permalink( $page->ID ) . '">' . get_the_title( $page->ID ) . '</a>';
            $parent_id = $page->post_parent;
        }
        $parent_links = array_reverse( $parent_links );
        $breadcrumbs .= ' | ' . implode( ' | ', $parent_links );
      }
      $breadcrumbs .= ' | <span>' . get_the_title( $post ) . '</span>';
    }
    $breadcrumbs .= '</nav>';

    $formatted_post['breadcrumbs'] = $breadcrumbs;
    return $formatted_post;
  }

  private function return_post_revision_for_preview( $formatted_post ) {
    $post = get_post( $formatted_post['id'] );
    if ( $post->post_type == "revision" ) {
      $parent_post = get_post( $post->post_parent );
      $revisions = wp_get_post_revisions( $parent_post->ID );
      if ( ! empty( $revisions ) ) {
        krsort( $revisions );
        $latest_revision = reset( $revisions );
        return $this->format_post( $latest_revision );
      }
    }
    return $formatted_post;
  }
}