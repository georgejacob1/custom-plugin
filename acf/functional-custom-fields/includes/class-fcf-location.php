<?php
/**
 * Location rules: where a field group is shown.
 *
 * Rules are stored as OR-groups of AND-rules:
 * array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ), ... ), ... )
 */

defined( 'ABSPATH' ) || exit;

class FCF_Location {

	/**
	 * Rules that can change while the post is being edited (template, terms...).
	 * They are ignored when saving, so values of a group shown on load are still saved.
	 */
	const DYNAMIC_PARAMS = array( 'post_template', 'page_template', 'post_status', 'post_format', 'post_category', 'post_taxonomy', 'page_type', 'page_parent' );

	/** Max posts / terms listed per type in the value dropdown. */
	const CHOICE_LIMIT = 500;

	/**
	 * Rule parameters, grouped like ACF.
	 *
	 * @return array group label => array( param => label )
	 */
	public static function params() {
		return array(
			__( 'Post', 'functional-custom-fields' ) => array(
				'post_type'     => __( 'Post Type', 'functional-custom-fields' ),
				'post_template' => __( 'Post Template', 'functional-custom-fields' ),
				'post_status'   => __( 'Post Status', 'functional-custom-fields' ),
				'post_format'   => __( 'Post Format', 'functional-custom-fields' ),
				'post_category' => __( 'Post Category', 'functional-custom-fields' ),
				'post_taxonomy' => __( 'Post Taxonomy', 'functional-custom-fields' ),
				'post'          => __( 'Post', 'functional-custom-fields' ),
			),
			__( 'Page', 'functional-custom-fields' ) => array(
				'page_template' => __( 'Page Template', 'functional-custom-fields' ),
				'page_type'     => __( 'Page Type', 'functional-custom-fields' ),
				'page_parent'   => __( 'Page Parent', 'functional-custom-fields' ),
				'page'          => __( 'Page', 'functional-custom-fields' ),
			),
			__( 'Options', 'functional-custom-fields' ) => array(
				'options_page' => __( 'Options Page', 'functional-custom-fields' ),
			),
		);
	}

	/**
	 * @return array param => label
	 */
	public static function param_labels() {
		$labels = array();
		foreach ( self::params() as $params ) {
			$labels += $params;
		}
		return $labels;
	}

	public static function operators() {
		return array(
			'==' => __( 'is equal to', 'functional-custom-fields' ),
			'!=' => __( 'is not equal to', 'functional-custom-fields' ),
		);
	}

	/**
	 * Value choices for every param, in an order-safe shape for JS:
	 * param => array( array( group label or '', array( array( value, label ), ... ) ), ... )
	 *
	 * @return array
	 */
	public static function choices() {
		$out = array();
		foreach ( array_keys( self::param_labels() ) as $param ) {
			$out[ $param ] = array();
			foreach ( self::param_choices( $param ) as $group => $options ) {
				$pairs = array();
				foreach ( $options as $value => $label ) {
					$pairs[] = array( (string) $value, html_entity_decode( (string) $label, ENT_QUOTES, 'UTF-8' ) );
				}
				$out[ $param ][] = array( (string) $group, $pairs );
			}
		}
		return $out;
	}

	/**
	 * Choices of one param.
	 *
	 * @param string $param Param.
	 * @return array group label ('' for none) => array( value => label )
	 */
	public static function param_choices( $param ) {
		switch ( $param ) {
			case 'post_type':
				$types = array();
				foreach ( FCF_Field_Groups::available_post_types() as $type ) {
					$types[ $type->name ] = $type->labels->singular_name;
				}
				return array( '' => $types );

			case 'post_template':
				$templates = array( 'default' => __( 'Default Template', 'functional-custom-fields' ) );
				foreach ( FCF_Field_Groups::available_post_types() as $type ) {
					$templates += wp_get_theme()->get_page_templates( null, $type->name );
				}
				return array( '' => $templates );

			case 'page_template':
				return array( '' => array( 'default' => __( 'Default Template', 'functional-custom-fields' ) ) + wp_get_theme()->get_page_templates( null, 'page' ) );

			case 'post_status':
				$statuses = array();
				foreach ( get_post_stati( array( 'internal' => false ), 'objects' ) as $status ) {
					$statuses[ $status->name ] = $status->label;
				}
				return array( '' => $statuses );

			case 'post_format':
				return array( '' => get_post_format_strings() );

			case 'post_category':
				return array( '' => self::terms( 'category' ) );

			case 'post_taxonomy':
				$out = array();
				foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $tax ) {
					$terms = self::terms( $tax->name );
					if ( $terms ) {
						$out[ $tax->labels->singular_name ] = $terms;
					}
				}
				return $out;

			case 'post':
				$out = array();
				foreach ( FCF_Field_Groups::available_post_types() as $type ) {
					if ( 'page' === $type->name ) {
						continue;
					}
					$posts = self::posts( $type->name );
					if ( $posts ) {
						$out[ $type->labels->name ] = $posts;
					}
				}
				return $out;

			case 'page':
			case 'page_parent':
				return array( '' => self::posts( 'page' ) );

			case 'page_type':
				return array(
					'' => array(
						'front_page' => __( 'Front Page', 'functional-custom-fields' ),
						'posts_page' => __( 'Posts Page', 'functional-custom-fields' ),
						'top_level'  => __( 'Top Level Page (no parent)', 'functional-custom-fields' ),
						'parent'     => __( 'Parent Page (has children)', 'functional-custom-fields' ),
						'child'      => __( 'Child Page (has parent)', 'functional-custom-fields' ),
					),
				);

			case 'options_page':
				$pages = array();
				foreach ( FCF_Options_Pages::pages() as $slug => $page ) {
					$pages[ $slug ] = $page['page_title'];
				}
				return array( '' => $pages );
		}

		return array();
	}

	/**
	 * Terms of a taxonomy as "taxonomy:slug" => name.
	 */
	protected static function terms( $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => self::CHOICE_LIMIT,
			)
		);
		$out   = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$out[ $taxonomy . ':' . $term->slug ] = $term->name;
			}
		}
		return $out;
	}

	/**
	 * Posts of a type as ID => title.
	 */
	protected static function posts( $post_type ) {
		$posts = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'      => self::CHOICE_LIMIT,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		$out   = array();
		foreach ( $posts as $p ) {
			/* translators: %d: post ID */
			$out[ $p->ID ] = '' !== $p->post_title ? $p->post_title : sprintf( __( '(no title) #%d', 'functional-custom-fields' ), $p->ID );
		}
		return $out;
	}

	/**
	 * Clean rules coming from the builder.
	 *
	 * @param mixed $groups Raw rules.
	 * @return array
	 */
	public static function sanitize( $groups ) {
		$params = self::param_labels();
		$out    = array();

		foreach ( is_array( $groups ) ? $groups : array() as $group ) {
			$rules = array();
			foreach ( is_array( $group ) ? $group : array() as $rule ) {
				if ( ! is_array( $rule ) || ! isset( $rule['param'], $params[ $rule['param'] ] ) ) {
					continue;
				}
				$value = isset( $rule['value'] ) ? sanitize_text_field( $rule['value'] ) : '';
				if ( '' === $value ) {
					continue;
				}
				$rules[] = array(
					'param'    => $rule['param'],
					'operator' => isset( $rule['operator'] ) && '!=' === $rule['operator'] ? '!=' : '==',
					'value'    => $value,
				);
			}
			if ( $rules ) {
				$out[] = $rules;
			}
		}

		return $out;
	}

	/**
	 * Rules of a group. Groups saved before location rules existed are converted
	 * from their post types / page template settings.
	 *
	 * @param int $group_id Group post ID.
	 * @return array
	 */
	public static function get( $group_id ) {
		if ( metadata_exists( 'post', $group_id, '_fcf_location' ) ) {
			$location = get_post_meta( $group_id, '_fcf_location', true );
			return is_array( $location ) ? $location : array();
		}

		$types    = array_filter( (array) get_post_meta( $group_id, '_fcf_post_types', true ) );
		$template = (string) get_post_meta( $group_id, '_fcf_page_template', true );
		$groups   = array();
		foreach ( $types as $type ) {
			$rules = array( array( 'param' => 'post_type', 'operator' => '==', 'value' => $type ) );
			if ( '' !== $template ) {
				$rules[] = array( 'param' => 'post_template', 'operator' => '==', 'value' => $template );
			}
			$groups[] = $rules;
		}
		return $groups;
	}

	/**
	 * Whether rules match a post: any group where every rule matches.
	 *
	 * @param array   $groups Rules.
	 * @param WP_Post $post   Post being edited.
	 * @param bool    $loose  Treat rules that may change during editing as matching.
	 * @return bool
	 */
	public static function matches( $groups, $post, $loose = false ) {
		foreach ( $groups as $rules ) {
			$all = true;
			foreach ( $rules as $rule ) {
				if ( ! self::rule_matches( $rule, $post, $loose ) ) {
					$all = false;
					break;
				}
			}
			if ( $all && $rules ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether rules match an options page: any group made only of options page
	 * rules that all match. Post rules never match on an options page.
	 *
	 * @param array  $groups Rules.
	 * @param string $slug   Options page slug.
	 * @return bool
	 */
	public static function matches_options_page( $groups, $slug ) {
		foreach ( $groups as $rules ) {
			$all = (bool) $rules;
			foreach ( $rules as $rule ) {
				$match = 'options_page' === $rule['param'] && ( (string) $rule['value'] === $slug ) === ( '!=' !== $rule['operator'] );
				if ( ! $match ) {
					$all = false;
					break;
				}
			}
			if ( $all ) {
				return true;
			}
		}
		return false;
	}

	protected static function rule_matches( $rule, $post, $loose ) {
		$param = $rule['param'];
		$value = (string) $rule['value'];

		// Options page rules never apply to posts, whatever the operator.
		if ( 'options_page' === $param ) {
			return false;
		}

		if ( $loose && in_array( $param, self::DYNAMIC_PARAMS, true ) ) {
			return true;
		}

		switch ( $param ) {
			case 'post_type':
				$match = $post->post_type === $value;
				break;

			case 'post_template':
				$match = self::template_of( $post ) === $value;
				break;

			case 'page_template':
				$match = 'page' === $post->post_type && self::template_of( $post ) === $value;
				break;

			case 'post_status':
				$match = ( 'auto-draft' === $post->post_status ? 'draft' : $post->post_status ) === $value;
				break;

			case 'post_format':
				$format = get_post_format( $post );
				$match  = ( $format ? $format : 'standard' ) === $value;
				break;

			case 'post_category':
			case 'post_taxonomy':
				$parts = explode( ':', $value, 2 );
				$match = 2 === count( $parts ) && taxonomy_exists( $parts[0] ) && has_term( $parts[1], $parts[0], $post );
				break;

			case 'post':
				$match = (int) $value === (int) $post->ID;
				break;

			case 'page':
				$match = 'page' === $post->post_type && (int) $value === (int) $post->ID;
				break;

			case 'page_parent':
				$match = (int) $value === (int) $post->post_parent;
				break;

			case 'page_type':
				$match = self::page_type_matches( $post, $value );
				break;

			default:
				$match = false;
		}

		return '!=' === $rule['operator'] ? ! $match : $match;
	}

	protected static function template_of( $post ) {
		$template = get_page_template_slug( $post );
		return '' === $template ? 'default' : $template;
	}

	protected static function page_type_matches( $post, $value ) {
		if ( ! is_post_type_hierarchical( $post->post_type ) ) {
			return false;
		}
		switch ( $value ) {
			case 'front_page':
				return (int) get_option( 'page_on_front' ) === (int) $post->ID;
			case 'posts_page':
				return (int) get_option( 'page_for_posts' ) === (int) $post->ID;
			case 'top_level':
				return 0 === (int) $post->post_parent;
			case 'child':
				return 0 < (int) $post->post_parent;
			case 'parent':
				return (bool) get_children(
					array(
						'post_parent' => $post->ID,
						'post_type'   => $post->post_type,
						'numberposts' => 1,
						'fields'      => 'ids',
					)
				);
		}
		return false;
	}

	/**
	 * Readable label of a rule value (for the group list).
	 */
	public static function value_label( $param, $value ) {
		switch ( $param ) {
			case 'post_type':
				$object = get_post_type_object( $value );
				return $object ? $object->labels->singular_name : $value;

			case 'post':
			case 'page':
			case 'page_parent':
				$title = get_the_title( (int) $value );
				return '' !== $title ? $title : '#' . $value;

			case 'post_category':
			case 'post_taxonomy':
				$parts = explode( ':', $value, 2 );
				$term  = 2 === count( $parts ) ? get_term_by( 'slug', $parts[1], $parts[0] ) : null;
				return $term ? $term->name : $value;
		}

		foreach ( self::param_choices( $param ) as $options ) {
			if ( isset( $options[ $value ] ) ) {
				return $options[ $value ];
			}
		}
		return $value;
	}

	/**
	 * One-line summary of a group's rules, e.g. "Airline Travel, Airline".
	 *
	 * @param array $groups Rules.
	 * @return string Plain text.
	 */
	public static function summary( $groups ) {
		$parts = array();
		foreach ( $groups as $rules ) {
			$labels = array();
			foreach ( $rules as $rule ) {
				$label    = self::value_label( $rule['param'], $rule['value'] );
				$labels[] = '!=' === $rule['operator'] ? sprintf( /* translators: %s: rule value */ __( 'not %s', 'functional-custom-fields' ), $label ) : $label;
			}
			$parts[] = implode( ' & ', $labels );
		}
		return implode( ', ', $parts );
	}
}
