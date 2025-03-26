<?php
/**
 * Helper class for building ACF blocks
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class Field_Builder {
  /**
   * ACF field builder function, maps API field types to ACF
   */
  public function build( $fields, $builder ) {
    foreach ( $fields as $field ) {
      $field_type = $field['type'];
      $field_args = [
        'label' => $field['label'] ?? ucfirst( str_replace( '_', ' ', $field['id'] ) ),
        'name' => $field['id'],
        'instructions' => $field['instructions'] ?? '',
        'allowed_types' => $field['allowed_types'] ?? '',
        'layout' => $field['layout'] ?? 'table',
      ];

      // Merge any additional configuration from the API
      $field_args = array_merge(
        $field_args, 
        array_diff_key(
          $field, 
          array_flip( ['id', 'type', 'label', 'instructions'] )
        )
      );

      switch ( $field_type ) {
        case 'text':
          $builder->addText( $field['id'], $field_args );
          break;
        case 'textarea':
          $builder->addTextarea( $field['id'], $field_args );
          break;
        case 'number':
          $builder->addNumber( $field['id'], $field_args );
          break;
        case 'email':
          $builder->addEmail( $field['id'], $field_args );
          break;
        case 'url':
          $builder->addUrl( $field['id'], $field_args );
          break;
        case 'password':
          $builder->addPassword( $field['id'], $field_args );
          break;
        case 'wysiwyg':
          $builder->addWysiwyg( $field['id'], $field_args );
          break;
        case 'image':
          $builder->addImage( $field['id'], $field_args );
          break;
        case 'file':
          $builder->addFile( $field['id'], $field_args );
          break;
        case 'gallery':
          $builder->addGallery( $field['id'], $field_args );
          break;
        case 'select':
          $builder->addSelect( $field['id'], $field_args );
          break;
        case 'checkbox':
          $builder->addCheckbox( $field['id'], $field_args );
          break;
        case 'radio':
          $builder->addRadio( $field['id'], $field_args );
          break;
        case 'true_false':
          $builder->addTrueFalse( $field['id'], $field_args );
          break;
        case 'link':
          $builder->addLink( $field['id'], $field_args );
          break;
        case 'post_object':
          $builder->addPostObject( $field['id'], $field_args );
          break;
        case 'page_link':
          $builder->addPageLink( $field['id'], $field_args );
          break;
        case 'relationship':
          $builder->addRelationship( $field['id'], $field_args );
          break;
        case 'taxonomy':
          $builder->addTaxonomy( $field['id'], $field_args );
          break;
        case 'user':
          $builder->addUser( $field['id'], $field_args );
          break;
        case 'date_picker':
          $builder->addDatePicker( $field['id'], $field_args );
          break;
        case 'time_picker':
          $builder->addTimePicker( $field['id'], $field_args );
          break;
        case 'color_picker':
          $builder->addColorPicker( $field['id'], $field_args );
          break;
        case 'message':
          $builder->addMessage( $field['id'], $field_args );
          break;
        case 'repeater':
          $repeater = $builder->addRepeater( $field['id'], $field_args );
          if ( isset( $field['fields'] ) ) {
            $this->build( $field['fields'], $repeater );
          }
          break;
        case 'group':
          $group = $builder->addGroup( $field['id'], $field_args );
          if ( isset( $field['fields'] ) ) {
            $this->build( $field['fields'], $group );
          }
          $group->endGroup();
          break;
        case 'flexible_content':
          $flex = $builder->addFlexibleContent( $field['id'], $field_args );
          if ( isset( $field['layouts'] ) ) {
            foreach ( $field['layouts'] as $layout ) {
              $flex_layout = $flex->addLayout(
                $layout['name'], 
                [ 'label' => $layout['label'] ]
              );
              if ( isset( $layout['fields'] ) ) {
                $this->build( $layout['fields'], $flex_layout );
              }
            }
          }
          break;
        case 'nav':
          $field_args['choices'] = $this->get_menus();
          $builder->addSelect( $field['id'], $field_args );
          break;
        case 'theme':
          $field_args['choices'] = $this->get_nextpress_themes();
          $field_args['default_value'] = 'default-blocks';
          $builder->addSelect( $field['id'], $field_args );
          break;
        case 'gravity_form':
          $field_args['choices'] = $this->get_gravity_forms();
          $builder->addSelect( $field['id'], $field_args );
          break;
        case 'tab':
          $builder->addTab( $field['id'], $field_args );
          break;
        case 'accordion':
          $builder->addAccordion( $field['id'], $field_args );
          break;
        case 'inner_blocks':
          if ( is_array( $field['choices'] ) ) {
            $field_args['default_value'] = array_values( $field['choices'] );
          }
          $builder->addCheckbox( $field['id'], $field_args );
          break;
        default:
          // For any custom or unhandled field types
          $builder->addField( $field_type, $field['id'], $field_args );
          break;
      }
    }
    return $builder;
  }

  /**
   * Return WP menus as select choices.
   */
  private function get_menus() {
    $menu_choices = [ null => __('Please select menu', 'nextpress') ];
    $menus = wp_get_nav_menus();

    if ( !is_wp_error( $menus ) ) {
      foreach ($menus as $menu) {
        $id = $menu->term_id;
        $menu_choices["{{nav_id-$id}}"] = $menu->name;
      }
    } else {
      $menus = get_nav_menu_locations();
      foreach ($menus as $location => $id) {
        $menu_choices["{{nav_id-$id}}"] = ucfirst(str_replace('_', ' ', $location));
      }
    }

    return $menu_choices;
  }

  /**
   * Return Nextpress themes as select choices
   */
  private function get_nextpress_themes() {
    $themes = get_field( 'blocks_theme', 'option' );
    if ( $themes && is_array( $themes ) ) {
      foreach ( $themes as $theme ) {
        $choices[ $theme ] = $theme;
      }
    }

    return $choices;
  }

  /**
   * Return gravity forms as select choices
   */
  private function get_gravity_forms() {
    if ( ! class_exists( 'GFAPI' ) ) {
      // No Gravity Form API class available. The plugin probably isn't active.
      return $field;
    }

    $forms = GFAPI::get_forms( true );
    $choices[null] = __( 'Please select form', 'nextpress' );
    foreach ( $forms as $form ) {
      $choices['form_id_' . $form['id']] = $form['title'];
    }

    return $choices;
  }
}