<?php

defined( 'ABSPATH' ) || exit;

final class TAB_Dynamic_Template {
	private $slug;
	private $html_path;
	private $media_map;
	private $fields = array();
	private $tokens = array();
	private $field_names = array();
	private $field_index = 0;
	private $source_order = array();

	public static function convert( $html, $slug, $html_path, $media_map, $template_relative ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			throw new RuntimeException( __( 'The PHP DOM extension is required to generate dynamic custom fields.', 'theme-agent-blueprint' ) );
		}

		$converter = new self( $slug, $html_path, $media_map );
		return $converter->build( $html, $template_relative );
	}

	public static function prepare_static( $html ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			throw new RuntimeException( __( 'The PHP DOM extension is required to prepare imported HTML.', 'theme-agent-blueprint' ) );
		}

		libxml_use_internal_errors( true );
		$document = new DOMDocument( '1.0', 'UTF-8' );
		$wrapped = '<!doctype html><html><body><div id="tab-static-root">' . $html . '</div></body></html>';
		$document->loadHTML( '<?xml encoding="utf-8" ?>' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$xpath = new DOMXPath( $document );
		$root = $xpath->query( '//*[@id="tab-static-root"]' )->item( 0 );
		if ( ! $root ) {
			throw new RuntimeException( __( 'The HTML body could not be parsed.', 'theme-agent-blueprint' ) );
		}

		self::remove_source_chrome( $xpath, $root );
		foreach ( $xpath->query( './/*[@data-tab-field or @data-tab-type or @data-tab-repeater or @data-tab-ignore or @data-tab-source-order]', $root ) as $node ) {
			if ( $node instanceof DOMElement ) {
				$node->removeAttribute( 'data-tab-field' );
				$node->removeAttribute( 'data-tab-type' );
				$node->removeAttribute( 'data-tab-repeater' );
				$node->removeAttribute( 'data-tab-ignore' );
				$node->removeAttribute( 'data-tab-source-order' );
			}
		}
		$output = '';
		foreach ( iterator_to_array( $root->childNodes ) as $child ) {
			$output .= $document->saveHTML( $child );
		}
		return $output;
	}

	private function __construct( $slug, $html_path, $media_map ) {
		$this->slug = str_replace( '-', '_', sanitize_key( $slug ) );
		$this->html_path = $html_path;
		$this->media_map = $media_map;
	}

	private function build( $html, $template_relative ) {
		libxml_use_internal_errors( true );
		$document = new DOMDocument( '1.0', 'UTF-8' );
		$wrapped = '<!doctype html><html><body><div id="tab-dynamic-root">' . $html . '</div></body></html>';
		$document->loadHTML( '<?xml encoding="utf-8" ?>' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$xpath = new DOMXPath( $document );
		$root = $xpath->query( '//*[@id="tab-dynamic-root"]' )->item( 0 );
		if ( ! $root ) {
			throw new RuntimeException( __( 'The HTML body could not be parsed.', 'theme-agent-blueprint' ) );
		}

		self::remove_source_chrome( $xpath, $root );
		$sections = $xpath->query( './/section[not(ancestor::section)]', $root );
		if ( 0 === $sections->length ) {
			$sections = array( $root );
		} else {
			$sections = iterator_to_array( $sections );
		}

		foreach ( $sections as $section_index => $section ) {
			$this->index_source_order( $section );
			$field_start = count( $this->fields );
			$base = $this->section_base( $section, $section_index + 1 );
			$tab = $this->make_field( $base . '_tab', $this->section_label( $section, $section_index + 1 ), 'tab' );
			$tab['_tab_order'] = -1;
			$this->fields[] = $tab;
			$this->process_repeaters( $document, $xpath, $section, $base );
			$this->process_scalars( $document, $xpath, $section, $base );
			$this->sort_section_fields( $field_start );
		}

		$this->remove_markers( $xpath, $root );
		$output = '';
		foreach ( iterator_to_array( $root->childNodes ) as $child ) {
			$output .= $document->saveHTML( $child );
		}

		$field_group = array(
			'key'                   => 'group_tab_' . substr( md5( $this->slug . $template_relative ), 0, 16 ),
			'title'                 => ucwords( str_replace( '_', ' ', $this->slug ) ) . ' Page Content',
			'fields'                => $this->fields,
			'location'              => array( array( array( 'param' => 'page_template', 'operator' => '==', 'value' => $template_relative ) ) ),
			'menu_order'            => 0,
			'position'              => 'normal',
			'style'                 => 'default',
			'label_placement'       => 'top',
			'instruction_placement' => 'label',
			'hide_on_screen'        => '',
			'active'                => true,
			'description'           => 'Generated from the imported HTML template. Source values remain as frontend fallbacks.',
			'show_in_rest'          => 0,
			'modified'              => time(),
		);
		$this->validate_field_group( $field_group );

		return array(
			'html'        => $output,
			'tokens'      => $this->tokens,
			'field_group' => $field_group,
			'field_count' => count( $this->fields ),
		);
	}

	private static function remove_source_chrome( $xpath, $root ) {
		$query = './/header[not(ancestor::section) and not(ancestor::article)] | .//footer[not(ancestor::section) and not(ancestor::article)]';
		$query .= ' | .//*[@role="banner"] | .//*[@role="contentinfo"]';
		$query .= ' | .//*[contains(concat(" ", normalize-space(@class), " "), " site_header ")]';
		$query .= ' | .//*[contains(concat(" ", normalize-space(@class), " "), " site-header ")]';
		$query .= ' | .//*[contains(concat(" ", normalize-space(@class), " "), " site_footer ")]';
		$query .= ' | .//*[contains(concat(" ", normalize-space(@class), " "), " site-footer ")]';
		$query .= ' | .//*[@id="masthead"] | .//*[@id="colophon"]';
		$nodes = iterator_to_array( $xpath->query( $query, $root ) );
		foreach ( $nodes as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}

	private function process_repeaters( $document, $xpath, $section, $base ) {
		$parents = array_merge( array( $section ), iterator_to_array( $xpath->query( './/*', $section ) ) );
		foreach ( array_reverse( $parents ) as $parent ) {
			if ( ! $parent instanceof DOMElement || $parent->hasAttribute( 'data-tab-dynamic' ) || $parent->hasAttribute( 'data-tab-ignore' ) || $xpath->query( 'ancestor::*[@data-tab-ignore]', $parent )->length ) {
				continue;
			}

			$groups = array();
			foreach ( iterator_to_array( $parent->childNodes ) as $child ) {
				if ( ! $child instanceof DOMElement || $child->hasAttribute( 'data-tab-dynamic' ) ) {
					continue;
				}
				$signature = $this->element_signature( $child );
				$groups[ $signature ][] = $child;
			}

			foreach ( $groups as $signature => $items ) {
				if ( count( $items ) < 2 || ( ! $this->is_repeater_signature( $signature ) && ! $parent->hasAttribute( 'data-tab-repeater' ) ) ) {
					continue;
				}
				if ( array_filter( $items, function ( $item ) use ( $xpath ) { return $this->is_functional_item( $xpath, $item ); } ) ) {
					continue;
				}
				$editable_sets = array();
				foreach ( $items as $item ) {
					$editable_sets[] = $this->editable_nodes( $xpath, $item );
				}
				if ( ! $this->matching_sets( $editable_sets ) || empty( $editable_sets[0] ) ) {
					continue;
				}

				$data_attributes = $this->repeater_data_attributes( $items );
				$collection_name = $parent->hasAttribute( 'data-tab-repeater' ) ? sanitize_key( $parent->getAttribute( 'data-tab-repeater' ) ) : $this->collection_name( $signature, $data_attributes );
				$repeater_name = $this->unique_name( $base . '_' . $collection_name );
				$field = $this->make_field( $repeater_name, $this->label_from_name( $collection_name ), 'repeater' );
				$field['_tab_order'] = $this->source_node_order( $items[0] );
				$field['layout'] = 'block';
				$field['button_label'] = 'Add ' . rtrim( $this->label_from_name( $collection_name ), 's' );
				if ( array_filter( $items, static function ( $item ) { return $item->hasAttribute( 'data-step' ); } ) ) {
					$field['min'] = count( $items );
					$field['max'] = count( $items );
				}
				$sub_fields = array();
				$defaults = array_fill( 0, count( $items ), array() );
				$sub_counts = array();
				$dynamic_attributes = array();

				foreach ( $data_attributes as $attribute_name => $values ) {
					$sub_name = sanitize_key( str_replace( 'data-', '', $attribute_name ) );
					foreach ( $values as $row_index => $value ) {
						$defaults[ $row_index ][ $sub_name ] = $value;
					}
					$identity_field = $this->make_sub_field( $field['key'], $sub_name, 'text' );
					$identity_field['required'] = 1;
					$identity_field['instructions'] = __( 'Required functional key. Use a unique value without changing the frontend data contract.', 'theme-agent-blueprint' );
					$sub_fields[] = $identity_field;
					$items[0]->setAttribute( $attribute_name, $this->token( '<?php echo esc_attr( $tab_row[' . var_export( $sub_name, true ) . '] ?? ' . var_export( $values[0], true ) . ' ); ?>' ) );
					$dynamic_attributes[ $attribute_name ] = true;
				}

				foreach ( $editable_sets[0] as $node_index => $descriptor ) {
					$kind = $descriptor['kind'];
					$requested_name = $descriptor['node']->getAttribute( 'data-tab-field' );
					$semantic_name = $this->semantic_node_name( $descriptor['node'], $kind );
					$sub_name = sanitize_key( $requested_name ? $requested_name : $semantic_name . ( isset( $sub_counts[ $semantic_name ] ) ? '_' . ( $sub_counts[ $semantic_name ] + 1 ) : '' ) );
					$sub_counts[ $semantic_name ] = isset( $sub_counts[ $semantic_name ] ) ? $sub_counts[ $semantic_name ] + 1 : 1;
					foreach ( $editable_sets as $row_index => $set ) {
						$defaults[ $row_index ][ $sub_name ] = $this->node_default( $set[ $node_index ] );
					}
					$sub_fields[] = $this->make_sub_field( $field['key'], $sub_name, $kind );
					$this->dynamicize_node( $document, $descriptor, $sub_name, true );
				}

				$first = $items[0];
				$first->setAttribute( 'data-tab-dynamic', '1' );
				$classes = array_map( static function ( $item ) { return $item->getAttribute( 'class' ); }, $items );
				if ( count( array_unique( $classes ) ) > 1 ) {
					$signature_parts = explode( '|', $signature, 2 );
					$base_class = isset( $signature_parts[1] ) ? $signature_parts[1] : '';
					$first->setAttribute( 'class', $this->token( '<?php echo esc_attr( ' . var_export( $classes, true ) . '[ $tab_row_index ] ?? ' . var_export( $base_class, true ) . ' ); ?>' ) );
				}
				$attribute_names = array();
				foreach ( $items as $item ) {
					foreach ( $item->attributes as $attribute ) {
						if ( ! in_array( $attribute->name, array( 'class', 'data-tab-dynamic' ), true ) ) {
							$attribute_names[ $attribute->name ] = true;
						}
					}
				}
				foreach ( array_keys( $attribute_names ) as $attribute_name ) {
					if ( isset( $dynamic_attributes[ $attribute_name ] ) ) {
						continue;
					}
					$values = array_map( static function ( $item ) use ( $attribute_name ) { return $item->getAttribute( $attribute_name ); }, $items );
					if ( count( array_unique( $values ) ) < 2 ) {
						continue;
					}
					if ( 'data-step' === $attribute_name ) {
						$first->setAttribute( $attribute_name, $this->token( '<?php echo esc_attr( $tab_row_index ); ?>' ) );
					} else {
						$first->setAttribute( $attribute_name, $this->token( '<?php echo esc_attr( ' . var_export( $values, true ) . '[ $tab_row_index ] ?? \'\' ); ?>' ) );
					}
				}
				$open = $this->token( '<?php foreach ( $tab_rows( ' . var_export( $repeater_name, true ) . ', ' . var_export( $defaults, true ) . ' ) as $tab_row_index => $tab_row ) : ?>' );
				$close = $this->token( '<?php endforeach; ?>' );
				$parent->insertBefore( $document->createTextNode( $open ), $first );
				if ( $first->nextSibling ) {
					$parent->insertBefore( $document->createTextNode( $close ), $first->nextSibling );
				} else {
					$parent->appendChild( $document->createTextNode( $close ) );
				}
				foreach ( array_slice( $items, 1 ) as $item ) {
					$parent->removeChild( $item );
				}

				$field['sub_fields'] = $sub_fields;
				$this->fields[] = $field;
				break;
			}
		}
	}

	private function process_scalars( $document, $xpath, $section, $base ) {
		$nodes = $this->editable_nodes( $xpath, $section );
		foreach ( $nodes as $descriptor ) {
			$node = $descriptor['node'];
			if ( $this->has_dynamic_ancestor( $node, $section ) ) {
				continue;
			}
			$kind = $descriptor['kind'];
			$requested_name = $node->getAttribute( 'data-tab-field' );
			$semantic_name = $this->semantic_node_name( $node, $kind );
			$context_name = $this->node_context_name( $node );
			$name = $this->unique_name( $requested_name ? $requested_name : $base . ( $context_name ? '_' . $context_name : '' ) . '_' . $semantic_name );
			$label_seed = $requested_name ? $requested_name : trim( $context_name . '_' . $semantic_name, '_' );
			$field = $this->make_field( $name, $this->label_from_name( $label_seed ), $this->acf_type( $kind ), $this->node_default( $descriptor ) );
			$field['_tab_order'] = $this->source_node_order( $node );
			$this->fields[] = $field;
			$this->dynamicize_node( $document, $descriptor, $name, false );
		}
	}

	private function editable_nodes( $xpath, $root ) {
		$query = './/*[@data-tab-field or @data-tab-type] | .//a[normalize-space(.) != ""] | .//button[normalize-space(.) != ""] | .//img[@src] | .//span[normalize-space(.) != ""]';
		$query .= ' | .//div[contains(concat(" ", normalize-space(@class), " "), " small-title ") or contains(concat(" ", normalize-space(@class), " "), " description ")]';
		$query .= ' | .//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6 or self::p or self::blockquote or self::figcaption or self::li]';
		$descriptors = array();
		$selected_containers = array();
		foreach ( $xpath->query( $query, $root ) as $node ) {
			$parent = $node->parentNode;
			$nested_in_selected = false;
			while ( $parent instanceof DOMElement && $parent !== $root ) {
				if ( isset( $selected_containers[ spl_object_id( $parent ) ] ) ) {
					$nested_in_selected = true;
					break;
				}
				$parent = $parent->parentNode;
			}
			if ( $nested_in_selected ) continue;
			if ( $node->hasAttribute( 'data-tab-dynamic' ) || $node->hasAttribute( 'data-tab-ignore' ) || $xpath->query( 'ancestor::*[@data-tab-dynamic or @data-tab-ignore]', $node )->length ) {
				continue;
			}
			$explicit = $node->hasAttribute( 'data-tab-field' ) || $node->hasAttribute( 'data-tab-type' );
			if ( ! $explicit && $this->has_runtime_binding( $xpath, $node ) ) {
				continue;
			}
			// A heading or description with <span>, <strong>, <em>, etc. is ONE rich field.
			// Converting its child spans separately destroys the intended heading and order.
			$inline_content = $this->has_inline_markup_only( $xpath, $node );
			$nested_editable = $xpath->query( './/a | .//img | .//button | .//input | .//select | .//textarea | .//*[@data-tab-field or @data-tab-type]', $node )->length;
			$nested_data_hooks = $xpath->query( './/*/@*[starts-with(name(), "data-")]', $node )->length;
			$has_children = $xpath->query( './*', $node )->length > 0;
			if ( ! $explicit && 'img' !== strtolower( $node->tagName ) && ( $nested_editable || ( $nested_data_hooks && ! $has_children ) || ( $has_children && ! $inline_content ) ) ) {
				continue;
			}
			$text = trim( preg_replace( '/\s+/', ' ', $node->textContent ) );
			if ( 'img' !== strtolower( $node->tagName ) && '' === $text ) {
				continue;
			}
			$kind = $this->node_kind( $node );
			if ( ! in_array( $kind, array( 'image', 'link', 'file', 'url' ), true ) && $has_children ) {
				$kind = 'rich_text';
			}
			$descriptors[] = array( 'node' => $node, 'kind' => $kind );
			if ( $has_children && $inline_content ) {
				$selected_containers[ spl_object_id( $node ) ] = true;
			}
		}
		return $descriptors;
	}

	private function has_inline_markup_only( $xpath, $node ) {
		$tag = strtolower( $node->tagName );
		if ( ! preg_match( '/^h[1-6]$/', $tag ) && ! in_array( $tag, array( 'p', 'blockquote', 'figcaption', 'div', 'span', 'label' ), true ) ) {
			return false;
		}
		$elements = $xpath->query( './/*', $node );
		if ( ! $elements->length ) return false;
		$allowed = array( 'span', 'strong', 'b', 'em', 'i', 'mark', 'small', 'sup', 'sub', 'br', 'u', 'code' );
		foreach ( $elements as $child ) {
			if ( ! in_array( strtolower( $child->tagName ), $allowed, true ) ) return false;
			if ( $child->hasAttribute( 'data-tab-field' ) || $child->hasAttribute( 'data-tab-type' ) ) return false;
			foreach ( $child->attributes as $attr ) {
				if ( 0 === strpos( strtolower( $attr->name ), 'data-' ) && 'data-tab-source-order' !== strtolower( $attr->name ) ) return false;
			}
		}
		return true;
	}

	private function matching_sets( $sets ) {
		$count = count( $sets[0] );
		foreach ( $sets as $set ) {
			if ( count( $set ) !== $count ) {
				return false;
			}
			foreach ( $set as $index => $descriptor ) {
				if ( $descriptor['kind'] !== $sets[0][ $index ]['kind'] ) {
					return false;
				}
			}
		}
		return true;
	}

	private function dynamicize_node( $document, $descriptor, $name, $is_row ) {
		$node = $descriptor['node'];
		$kind = $descriptor['kind'];
		$default = $this->node_default( $descriptor );
		$value = $is_row ? '$tab_row[' . var_export( $name, true ) . '] ?? null' : '$tab_field( ' . var_export( $name, true ) . ', null )';

		if ( 'image' === $kind ) {
			$fallback = isset( $default['path'] ) ? $default['path'] : '';
			$alt = isset( $default['alt'] ) ? $default['alt'] : '';
			$image = '$tab_image( ' . $value . ', ' . var_export( $fallback, true ) . ', ' . var_export( $alt, true ) . ' )';
			$node->setAttribute( 'src', $this->token( '<?php echo esc_url( ' . $image . '[\'url\'] ); ?>' ) );
			$node->setAttribute( 'alt', $this->token( '<?php echo esc_attr( ' . $image . '[\'alt\'] ); ?>' ) );
			$node->removeAttribute( 'srcset' );
			return;
		}

		if ( 'link' === $kind ) {
			$link = '$tab_link( ' . $value . ', ' . var_export( $default, true ) . ' )';
			$node->setAttribute( 'href', $this->token( '<?php echo esc_url( ' . $link . '[\'url\'] ); ?>' ) );
			$node->setAttribute( 'target', $this->token( '<?php echo esc_attr( ' . $link . '[\'target\'] ); ?>' ) );
			$this->replace_contents( $document, $node, $this->token( '<?php echo esc_html( ' . $link . '[\'title\'] ); ?>' ) );
			return;
		}

		if ( 'file' === $kind ) {
			$file = '$tab_file( ' . $value . ', ' . var_export( $default, true ) . ' )';
			$node->setAttribute( 'href', $this->token( '<?php echo esc_url( ' . $file . '[\'url\'] ); ?>' ) );
			$this->replace_contents( $document, $node, $this->token( '<?php echo esc_html( ' . $file . '[\'title\'] ); ?>' ) );
			return;
		}

		if ( 'url' === $kind ) {
			$attribute = 'a' === strtolower( $node->tagName ) ? 'href' : ( $node->hasAttribute( 'formaction' ) ? 'formaction' : ( $node->hasAttribute( 'data-href' ) ? 'data-href' : 'data-url' ) );
			$node->setAttribute( $attribute, $this->token( '<?php echo esc_url( ' . $value . ' ); ?>' ) );
			return;
		}

		$fallback = var_export( $default, true );
		$expression = $is_row ? '( isset( $tab_row[' . var_export( $name, true ) . '] ) && \'\' !== $tab_row[' . var_export( $name, true ) . '] ? $tab_row[' . var_export( $name, true ) . '] : ' . $fallback . ' )' : '$tab_field( ' . var_export( $name, true ) . ', ' . $fallback . ' )';
		$escape = 'text' === $this->acf_type( $kind ) ? 'esc_html' : 'wp_kses_post';
		$this->replace_contents( $document, $node, $this->token( '<?php echo ' . $escape . '( ' . $expression . ' ); ?>' ) );
	}

	private function node_default( $descriptor ) {
		$node = $descriptor['node'];
		if ( 'image' === $descriptor['kind'] ) {
			$source = $node->getAttribute( 'src' );
			$path = $this->resolve_reference( $source );
			return array(
				'path' => $path && isset( $this->media_map[ $path ] ) ? $this->media_map[ $path ]['relative'] : $source,
				'alt'  => $node->getAttribute( 'alt' ),
			);
		}
		if ( 'link' === $descriptor['kind'] ) {
			return array(
				'url'    => $node->getAttribute( 'href' ),
				'title'  => trim( preg_replace( '/\s+/', ' ', $node->textContent ) ),
				'target' => $node->getAttribute( 'target' ),
			);
		}
		if ( 'file' === $descriptor['kind'] ) {
			return array(
				'url'   => $node->getAttribute( 'href' ),
				'title' => trim( preg_replace( '/\s+/', ' ', $node->textContent ) ),
			);
		}
		if ( 'url' === $descriptor['kind'] ) {
			foreach ( array( 'href', 'formaction', 'data-url', 'data-href' ) as $attribute ) {
				if ( $node->hasAttribute( $attribute ) ) {
					return $node->getAttribute( $attribute );
				}
			}
			return '';
		}
		return trim( $this->inner_html( $node ) );
	}

	private function make_field( $name, $label, $type, $default = null ) {
		$field = array(
			'key'               => 'field_tab_' . substr( md5( $this->slug . '|' . $name . '|' . $this->field_index++ ), 0, 18 ),
			'label'             => $label,
			'name'              => 'tab' === $type ? '' : $name,
			'type'              => $type,
			'instructions'      => '',
			'required'          => 0,
			'conditional_logic' => 0,
			'wrapper'           => array( 'width' => '', 'class' => '', 'id' => '' ),
		);
		if ( 'tab' === $type ) {
			$field['placement'] = 'top';
		} elseif ( 'image' === $type ) {
			$field['return_format'] = 'array';
			$field['preview_size'] = 'thumbnail';
			$field['library'] = 'all';
		} elseif ( 'link' === $type ) {
			$field['return_format'] = 'array';
		} elseif ( 'file' === $type ) {
			$field['return_format'] = 'array';
			$field['library'] = 'all';
		} elseif ( 'url' === $type ) {
			$field['default_value'] = is_scalar( $default ) ? esc_url_raw( $default ) : '';
		} elseif ( 'textarea' === $type ) {
			$field['rows'] = 4;
			$field['new_lines'] = '';
			$field['default_value'] = is_scalar( $default ) ? $default : '';
		} elseif ( 'wysiwyg' === $type ) {
			$field['tabs'] = 'all'; // Both Visual and Text editing modes.
			$field['toolbar'] = 'full';
			$field['media_upload'] = 1;
			$field['default_value'] = is_scalar( $default ) ? $default : '';
		} elseif ( 'text' === $type ) {
			$field['default_value'] = is_scalar( $default ) ? wp_strip_all_tags( $default ) : '';
		}
		return $field;
	}

	private function make_sub_field( $parent_key, $name, $kind ) {
		$field = $this->make_field( $name, $this->label_from_name( $name ), $this->acf_type( $kind ) );
		$field['parent_repeater'] = $parent_key;
		unset( $field['default_value'] );
		return $field;
	}

	private function acf_type( $kind ) {
		if ( in_array( $kind, array( 'image', 'link', 'file', 'url', 'text', 'textarea', 'wysiwyg' ), true ) ) {
			return $kind;
		}
		if ( 'rich_text' === $kind || 'quote' === $kind ) {
			return 'wysiwyg';
		}
		return in_array( $kind, array( 'heading', 'label', 'list_item' ), true ) ? 'text' : 'textarea';
	}

	private function node_kind( $node ) {
		$tag = strtolower( $node->tagName );
		$declared = sanitize_key( $node->getAttribute( 'data-tab-type' ) );
		if ( in_array( $declared, array( 'text', 'textarea', 'wysiwyg', 'image', 'file', 'link', 'url' ), true ) ) {
			return $declared;
		}
		if ( 'img' === $tag ) {
			return 'image';
		}
		if ( 'a' === $tag ) {
			return 'link';
		}
		if ( 'button' === $tag ) {
			return $node->hasAttribute( 'formaction' ) || $node->hasAttribute( 'data-url' ) || $node->hasAttribute( 'data-href' ) ? 'url' : 'label';
		}
		if ( 'span' === $tag ) {
			return 'label';
		}
		if ( preg_match( '/^h[1-6]$/', $tag ) ) {
			return 'heading';
		}
		if ( 'li' === $tag ) {
			return 'list_item';
		}
		if ( in_array( $tag, array( 'blockquote', 'figcaption' ), true ) ) {
			return 'quote';
		}
		return 'description';
	}

	private function is_repeater_signature( $signature ) {
		return (bool) preg_match( '/card|item|testimonial|slide|logo|faq|step|feature|benefit|team|plan|path|course|audience|partner|member|field|choice|option/', $signature );
	}

	private function is_functional_item( $xpath, $item ) {
		if ( $xpath->query( './/input | .//select | .//textarea | .//form', $item )->length ) {
			return true;
		}
		return $this->has_runtime_binding( $xpath, $item, array( 'data-institution', 'data-product' ) );
	}

	private function has_runtime_binding( $xpath, $node, $allowed = array() ) {
		$runtime_prefixes = array( 'data-summary-', 'data-result-', 'data-volume-', 'data-cost-', 'data-saved-', 'data-new-cost-' );
		$runtime_names = array( 'data-go', 'data-action', 'data-panel', 'data-cost-bar' );
		$attributes = array();
		if ( $node instanceof DOMElement ) {
			foreach ( $node->attributes as $attribute ) {
				$attributes[] = $attribute->name;
			}
		}
		foreach ( $xpath->query( './/@*', $node ) as $attribute ) {
			$attributes[] = $attribute->name;
		}
		foreach ( array_unique( $attributes ) as $name ) {
			if ( in_array( $name, $allowed, true ) ) {
				continue;
			}
			if ( in_array( $name, $runtime_names, true ) ) {
				return true;
			}
			foreach ( $runtime_prefixes as $prefix ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private function repeater_data_attributes( $items ) {
		$attributes = array();
		foreach ( array( 'data-institution', 'data-product' ) as $name ) {
			$values = array_map( static function ( $item ) use ( $name ) { return $item->getAttribute( $name ); }, $items );
			if ( array_filter( $values, 'strlen' ) ) {
				$attributes[ $name ] = $values;
			}
		}
		return $attributes;
	}

	private function collection_name( $signature, $data_attributes ) {
		if ( isset( $data_attributes['data-institution'] ) ) {
			return 'institutions';
		}
		if ( isset( $data_attributes['data-product'] ) ) {
			return 'products';
		}
		$name = $this->signature_name( $signature );
		return preg_match( '/s$/', $name ) ? $name : $name . 's';
	}

	private function semantic_node_name( $node, $kind ) {
		$classes = preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ) );
		$class_string = implode( ' ', $classes );
		if ( false !== strpos( $class_string, 'small-title' ) || false !== strpos( $class_string, 'eyebrow' ) ) {
			return 'eyebrow';
		}
		if ( false !== strpos( $class_string, 'privacy-note' ) ) {
			return 'privacy_note';
		}
		if ( false !== strpos( $class_string, 'estimate-disclaimer' ) ) {
			return 'disclaimer';
		}
		foreach ( array_reverse( $classes ) as $class ) {
			if ( preg_match( '/(?:^|[-_])(title|name|label|description|text|number|heading|caption|hint)$/', $class, $match ) ) {
				return 'name' === $match[1] || 'heading' === $match[1] ? 'title' : $match[1];
			}
		}
		$tag = strtolower( $node->tagName );
		if ( 'button' === $tag && 'submit' === strtolower( $node->getAttribute( 'type' ) ) ) {
			return 'submit_label';
		}
		if ( preg_match( '/^h[1-6]$/', $tag ) ) {
			return 'title';
		}
		if ( in_array( $tag, array( 'p', 'blockquote', 'figcaption' ), true ) ) {
			return 'description';
		}
		return $kind;
	}

	private function node_context_name( $node ) {
		$current = $node->parentNode;
		while ( $current instanceof DOMElement ) {
			$classes = ' ' . trim( $current->getAttribute( 'class' ) ) . ' ';
			if ( false !== strpos( $classes, ' inbox-panel ' ) ) {
				return 'results_inbox';
			}
			if ( false !== strpos( $classes, ' breakdown ' ) ) {
				return 'results_breakdown';
			}
			if ( $current->hasAttribute( 'data-panel' ) ) {
				return sanitize_key( str_replace( '-', '_', $current->getAttribute( 'data-panel' ) ) );
			}
			if ( preg_match( '/(?:^|\s)field(?:\s|$)/', $current->getAttribute( 'class' ) ) ) {
				$inputs = $current->getElementsByTagName( 'input' );
				if ( $inputs->length ) {
					$input = $inputs->item( 0 );
					$value = $input->getAttribute( 'name' ) ?: $input->getAttribute( 'id' );
					if ( $value ) {
						return sanitize_key( str_replace( '-', '_', preg_replace( '/^estimate-/', '', $value ) ) );
					}
				}
			}
			$current = $current->parentNode;
		}
		return '';
	}

	private function index_source_order( $section ) {
		// Stable source markers survive XPath wrapper changes and repeater DOM mutations.
		$index = 0;
		$nodes = array_merge( array( $section ), iterator_to_array( $section->getElementsByTagName( '*' ) ) );
		foreach ( $nodes as $node ) {
			$node->setAttribute( 'data-tab-source-order', (string) $index++ );
		}
	}

	private function source_node_order( $node ) {
		return $node instanceof DOMElement && $node->hasAttribute( 'data-tab-source-order' ) ? (int) $node->getAttribute( 'data-tab-source-order' ) : PHP_INT_MAX;
	}

	private function sort_section_fields( $field_start ) {
		$fields = array_splice( $this->fields, $field_start );
		usort( $fields, static function ( $left, $right ) {
			return $left['_tab_order'] <=> $right['_tab_order'];
		} );
		foreach ( $fields as &$field ) {
			unset( $field['_tab_order'] );
		}
		unset( $field );
		$this->fields = array_merge( $this->fields, $fields );
	}

	private function validate_field_group( $field_group ) {
		$keys = array();
		$names = array();
		foreach ( $field_group['fields'] as $field ) {
			$this->validate_field( $field, $keys, $names, true );
		}
	}

	private function validate_field( $field, &$keys, &$names, $top_level ) {
		$key = isset( $field['key'] ) ? $field['key'] : '';
		if ( ! $key || isset( $keys[ $key ] ) ) {
			throw new RuntimeException( __( 'The generated field schema contains a duplicate or missing field key.', 'theme-agent-blueprint' ) );
		}
		$keys[ $key ] = true;
		$name = isset( $field['name'] ) ? $field['name'] : '';
		if ( $top_level && $name ) {
			if ( isset( $names[ $name ] ) ) {
				throw new RuntimeException( __( 'The generated field schema contains duplicate field names.', 'theme-agent-blueprint' ) );
			}
			$names[ $name ] = true;
		}
		if ( 'repeater' === $field['type'] ) {
			$sub_names = array();
			foreach ( $field['sub_fields'] as $sub_field ) {
				$sub_name = isset( $sub_field['name'] ) ? $sub_field['name'] : '';
				if ( ! $sub_name || isset( $sub_names[ $sub_name ] ) ) {
					throw new RuntimeException( __( 'The generated repeater contains duplicate or missing subfield names.', 'theme-agent-blueprint' ) );
				}
				$sub_names[ $sub_name ] = true;
				$this->validate_field( $sub_field, $keys, $names, false );
			}
		}
	}

	private function element_signature( $element ) {
		$classes = preg_split( '/\s+/', strtolower( trim( $element->getAttribute( 'class' ) ) ) );
		$classes = array_filter( $classes, static function ( $class ) {
			return $class && ! preg_match( '/^(?:active|current|selected|open|hidden|is-|has-)/', $class );
		} );
		sort( $classes );
		if ( ! preg_match( '/card|item|testimonial|slide|logo|faq|step|feature|benefit|team|plan|path|course|audience|partner|member|field|choice|option/', implode( ' ', $classes ) ) ) {
			foreach ( $element->getElementsByTagName( '*' ) as $descendant ) {
				$descendant_classes = preg_split( '/\s+/', strtolower( trim( $descendant->getAttribute( 'class' ) ) ) );
				foreach ( $descendant_classes as $class ) {
					if ( preg_match( '/card|item|testimonial|slide|logo|faq|step|feature|benefit|team|plan|path|course|audience|partner|member|field|choice|option/', $class ) ) {
						return strtolower( $element->tagName . '|' . $class );
					}
				}
			}
		}
		return strtolower( $element->tagName . '|' . implode( ' ', $classes ) );
	}

	private function signature_name( $signature ) {
		$parts = explode( '|', $signature, 2 );
		$name = isset( $parts[1] ) && $parts[1] ? $parts[1] : $parts[0] . '_items';
		$name = preg_replace( '/\b(col(?:-\w+)*|row|d-\w+|mb-\d+|mt-\d+|p[xy]?-\d+)\b/', '', $name );
		return trim( preg_replace( '/_+/', '_', sanitize_key( str_replace( array( ' ', '-' ), '_', $name ) ) ), '_' );
	}

	private function section_base( $section, $index ) {
		$candidate = $section instanceof DOMElement ? $section->getAttribute( 'id' ) : '';
		if ( ! $candidate && $section instanceof DOMElement ) {
			$candidate = preg_split( '/\s+/', trim( $section->getAttribute( 'class' ) ) )[0];
		}
		$candidate = $candidate ? $candidate : 'section_' . $index;
		return $this->unique_name( $this->slug . '_' . sanitize_key( str_replace( '-', '_', $candidate ) ) );
	}

	private function section_label( $section, $index ) {
		$base = $this->section_base_label( $section );
		return $base ? $base : 'Section ' . $index;
	}

	private function section_base_label( $section ) {
		if ( ! $section instanceof DOMElement ) {
			return '';
		}
		$value = $section->getAttribute( 'id' ) ?: preg_split( '/\s+/', trim( $section->getAttribute( 'class' ) ) )[0];
		return $this->label_from_name( $value );
	}

	private function unique_name( $name ) {
		$name = trim( preg_replace( '/_+/', '_', sanitize_key( str_replace( '-', '_', $name ) ) ), '_' );
		$name = substr( $name ?: 'field', 0, 96 );
		$base = $name;
		$index = 2;
		while ( isset( $this->field_names[ $name ] ) ) {
			$name = substr( $base, 0, 90 ) . '_' . $index++;
		}
		$this->field_names[ $name ] = true;
		return $name;
	}

	private function label_from_name( $name ) {
		return ucwords( trim( preg_replace( '/\s+/', ' ', str_replace( array( '-', '_' ), ' ', (string) $name ) ) ) );
	}

	private function token( $php ) {
		$token = 'TABDYNAMIC' . count( $this->tokens ) . 'TOKEN';
		$this->tokens[ $token ] = $php;
		return $token;
	}

	private function replace_contents( $document, $node, $content ) {
		while ( $node->firstChild ) {
			$node->removeChild( $node->firstChild );
		}
		$node->appendChild( $document->createTextNode( $content ) );
	}

	private function inner_html( $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		// Source-order markers are internal bookkeeping, never editor default values.
		// This matters for headings such as <h1>Save with <span>AI</span></h1>.
		return preg_replace( '/\sdata-tab-source-order=(?:"[^"]*"|\'[^\']*\')/i', '', $html );
	}

	private function has_dynamic_ancestor( $node, $limit ) {
		$current = $node->parentNode;
		while ( $current && $current !== $limit ) {
			if ( $current instanceof DOMElement && $current->hasAttribute( 'data-tab-dynamic' ) ) {
				return true;
			}
			$current = $current->parentNode;
		}
		return false;
	}

	private function remove_markers( $xpath, $root ) {
		foreach ( $xpath->query( './/*[@data-tab-dynamic or @data-tab-field or @data-tab-type or @data-tab-repeater or @data-tab-ignore or @data-tab-source-order]', $root ) as $node ) {
			$node->removeAttribute( 'data-tab-dynamic' );
			$node->removeAttribute( 'data-tab-field' );
			$node->removeAttribute( 'data-tab-type' );
			$node->removeAttribute( 'data-tab-repeater' );
			$node->removeAttribute( 'data-tab-ignore' );
			$node->removeAttribute( 'data-tab-source-order' );
		}
	}

	private function resolve_reference( $reference ) {
		$reference = rawurldecode( preg_replace( '/[?#].*$/', '', trim( $reference ) ) );
		if ( '' === $reference || preg_match( '#^(?:data:|[a-z]+:|//)#i', $reference ) ) {
			return '';
		}
		$path = 0 === strpos( $reference, '/' ) ? ltrim( $reference, '/' ) : dirname( $this->html_path ) . '/' . $reference;
		$parts = array();
		foreach ( explode( '/', wp_normalize_path( $path ) ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $parts );
			} else {
				$parts[] = $part;
			}
		}
		return implode( '/', $parts );
	}
}