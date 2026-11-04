<?php

defined( 'ABSPATH' ) || exit;

final class TAB_Template_Importer {
	const MAX_ARCHIVE_SIZE = 26214400;
	const MAX_FILES        = 500;
	const MAX_EXPANDED_SIZE = 52428800;

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import templates.', 'theme-agent-blueprint' ) );
		}

		check_admin_referer( 'tab_import_template', 'tab_import_nonce' );

		try {
			$result = self::import();
			self::redirect( 'success', $result );
		} catch ( Throwable $exception ) {
			self::redirect( 'error', $exception->getMessage() );
		}
	}

	public static function render_notice() {
		if ( empty( $_GET['tab_import'] ) ) {
			return;
		}

		$key = sanitize_key( wp_unslash( $_GET['tab_import'] ) );
		$data = get_transient( 'tab_import_' . get_current_user_id() . '_' . $key );
		if ( ! is_array( $data ) || empty( $data['message'] ) ) {
			return;
		}

		delete_transient( 'tab_import_' . get_current_user_id() . '_' . $key );
		$type = 'success' === $data['type'] ? 'success' : 'error';
		add_settings_error( TAB_Theme_Agent_Blueprint_V111::SLUG, 'template_import', $data['message'], $type );
	}

	private static function import() {
		if ( empty( $_FILES['template_zip'] ) || ! is_array( $_FILES['template_zip'] ) ) {
			throw new RuntimeException( __( 'Choose a ZIP file to import.', 'theme-agent-blueprint' ) );
		}

		$upload = $_FILES['template_zip'];
		if ( UPLOAD_ERR_OK !== (int) $upload['error'] || ! is_uploaded_file( $upload['tmp_name'] ) ) {
			throw new RuntimeException( __( 'The ZIP upload did not complete successfully.', 'theme-agent-blueprint' ) );
		}
		if ( (int) $upload['size'] > self::MAX_ARCHIVE_SIZE || 'zip' !== strtolower( pathinfo( $upload['name'], PATHINFO_EXTENSION ) ) ) {
			throw new RuntimeException( __( 'Upload a ZIP file no larger than 25 MB.', 'theme-agent-blueprint' ) );
		}

		$template_name = isset( $_POST['template_name'] ) ? sanitize_text_field( wp_unslash( $_POST['template_name'] ) ) : '';
		$html_file     = isset( $_POST['html_file'] ) ? self::normalize_archive_path( wp_unslash( $_POST['html_file'] ) ) : '';
		$content_mode  = isset( $_POST['content_mode'] ) ? sanitize_key( wp_unslash( $_POST['content_mode'] ) ) : 'static';
		if ( ! in_array( $content_mode, array( 'static', 'dynamic' ), true ) ) {
			$content_mode = 'static';
		}
		if ( '' === $template_name ) {
			throw new RuntimeException( __( 'Enter a WordPress template name.', 'theme-agent-blueprint' ) );
		}

		$slug = sanitize_title( $template_name );
		if ( '' === $slug ) {
			throw new RuntimeException( __( 'The template name must contain letters or numbers.', 'theme-agent-blueprint' ) );
		}

		$entries = self::read_archive( $upload['tmp_name'] );
		$html_path = self::find_html_file( $entries, $html_file );
		$settings = wp_parse_args( get_option( TAB_Theme_Agent_Blueprint_V111::OPTION, array() ), array(
			'css_files'    => '',
			'js_files'     => '',
			'images_dir'   => 'assets/images/',
			'fonts_dir'    => 'assets/fonts/',
			'sections_dir' => 'template-parts/',
			'fields_dir'   => 'acf-json/',
		) );

		$theme_root = wp_normalize_path( get_stylesheet_directory() );
		$assets = self::extract_html_assets( $entries[ $html_path ], $html_path, $entries, array() );
		// Inspect only the page content that will actually be placed in the template.
		// Never use the source header, footer, or commented-out markup to select assets.
		$retained_body = TAB_Dynamic_Template::prepare_static( $assets['html'] );
		$usage = self::collect_body_usage_context( $retained_body );
		$required_media = self::referenced_media( $retained_body, $html_path, $assets['styles'], $usage, $assets['scripts'], $entries );
		$media = self::prepare_media( $entries, $settings, $theme_root, $required_media );
		$css = self::build_css( $assets['styles'], $media['map'], $usage, $slug );
		$skipped_scripts = 0;
		$js  = self::build_js( $assets['scripts'], $slug, $usage, $skipped_scripts );

		$template_dir = self::theme_path( $settings['sections_dir'], $theme_root, true );
		$template_path = $template_dir . '/template-' . $slug . '.php';
		if ( file_exists( $template_path ) ) {
			throw new RuntimeException( __( 'A template with this name already exists. Choose a different name.', 'theme-agent-blueprint' ) );
		}
		$template_relative = trim( self::theme_relative_path( $settings['sections_dir'] ), '/' ) . '/template-' . $slug . '.php';
		$dynamic = null;
		if ( 'dynamic' === $content_mode ) {
			$dynamic = TAB_Dynamic_Template::convert( $assets['html'], $slug, $html_path, $media['map'], $template_relative );
			$body = strtr( self::rewrite_html_media( $dynamic['html'], $html_path, $media['map'] ), $dynamic['tokens'] );
		} else {
			$body = self::rewrite_html_media( $retained_body, $html_path, $media['map'] );
		}

		$writes = $media['writes'];
		$blocks = array();
		if ( '' !== trim( $css ) ) {
			$css_path = self::asset_target( $settings['css_files'], array( 'assets/css/custom.css', 'style.css' ), $theme_root, 'css' );
			$css_append = self::append_asset( $css_path, $css, 'CSS', $slug );
			if ( $css_append['appended'] ) {
				$writes[ $css_path ] = $css_append['content'];
				$blocks[ $css_path ] = 'CSS';
			}
		}
		if ( '' !== trim( $js ) ) {
			$js_path = self::asset_target( $settings['js_files'], array( 'assets/js/custom.js' ), $theme_root, 'js' );
			$js_append = self::append_asset( $js_path, $js, 'JS', $slug );
			if ( $js_append['appended'] ) {
				$writes[ $js_path ] = $js_append['content'];
				$blocks[ $js_path ] = 'JS';
			}
		}

		$template = "<?php\n/**\n * Template Name: " . str_replace( '*/', '', $template_name ) . "\n */\n\ndefined( 'ABSPATH' ) || exit;\n\n";
		if ( $dynamic ) {
			$template .= self::template_helpers();
		}
		$template .= "\nget_header();\n?>\n";
		$template .= '<main id="tab-template-' . esc_attr( $slug ) . '" class="tab-imported-template">' . "\n" . trim( $body ) . "\n</main>\n";
		$template .= "<?php\nget_footer();\n";
		$writes[ $template_path ] = $template;
		if ( $dynamic ) {
			$fields_dir = self::theme_path( $settings['fields_dir'], $theme_root, true );
			$fields_path = $fields_dir . '/group_tab_' . $slug . '.json';
			if ( file_exists( $fields_path ) ) {
				throw new RuntimeException( __( 'A custom-field group for this template name already exists. Choose a different template name.', 'theme-agent-blueprint' ) );
			}
			$field_json = wp_json_encode( $dynamic['field_group'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $field_json ) {
				throw new RuntimeException( __( 'The generated custom-field schema could not be encoded.', 'theme-agent-blueprint' ) );
			}
			$writes[ $fields_path ] = $field_json . "\n";
		}

		$asset_paths = array_values( array_map( static function ( $asset ) { return wp_normalize_path( $asset['absolute'] ); }, $media['map'] ) );
		$history_record = TAB_Template_History::prepare_import( $template_name, $slug, $theme_root, $writes, $blocks, $asset_paths );
		try {
			self::commit( $writes, $theme_root );
			TAB_Template_History::finalize_import( $history_record );
		} catch ( Throwable $exception ) {
			TAB_Template_History::discard_import( $history_record );
			throw $exception;
		}

		$warning = self::import_warnings( $assets, $skipped_scripts );
		if ( ! $dynamic ) {
			return sprintf(
				/* translators: %s: template path */
				__( 'Static template imported to %s. Content remains in the generated template, and assets were merged into the configured shared locations.', 'theme-agent-blueprint' ),
				str_replace( $theme_root . '/', '', $template_path )
			) . $warning;
		}

		return sprintf(
			/* translators: 1: template path, 2: custom-field count */
			__( 'Dynamic template imported to %1$s with %2$d custom-field definitions. Source values remain as fallbacks, and assets were merged into the configured shared locations.', 'theme-agent-blueprint' ),
			str_replace( $theme_root . '/', '', $template_path ),
			(int) $dynamic['field_count']
		) . $warning;
	}


	private static function import_warnings( $assets, $skipped_scripts ) {
		$messages = array();
		if ( ! empty( $assets['dependencies'] ) ) {
			$messages[] = sprintf(
				/* translators: %s: CSS and JS dependency filenames */
				__( 'External/vendor assets were not copied: %s. Verify the active theme enqueues any required libraries and fonts.', 'theme-agent-blueprint' ),
				implode( ', ', array_slice( array_unique( $assets['dependencies'] ), 0, 12 ) )
			);
		}
		if ( $skipped_scripts ) {
			$messages[] = sprintf(
				/* translators: %d: number of scripts that could not be scoped safely */
				_n( '%d JavaScript block was excluded because it could not be safely linked to this template.', '%d JavaScript blocks were excluded because they could not be safely linked to this template.', $skipped_scripts, 'theme-agent-blueprint' ),
				$skipped_scripts
			);
		}
		return $messages ? ' ' . implode( ' ', $messages ) : '';
	}

	private static function template_helpers() {
		return <<<'PHP'
$tab_field = static function ( $name, $default = '' ) {
	if ( ! function_exists( 'get_field' ) ) {
		return $default;
	}
	$value = get_field( $name );
	return ( null === $value || false === $value || '' === $value ) ? $default : $value;
};
$tab_rows = static function ( $name, $default = array() ) use ( $tab_field ) {
	$value = $tab_field( $name, $default );
	return is_array( $value ) && ! empty( $value ) ? $value : $default;
};
$tab_link = static function ( $value, $default = array() ) {
	$default = wp_parse_args( is_array( $default ) ? $default : array(), array( 'url' => '#', 'title' => '', 'target' => '' ) );
	if ( ! is_array( $value ) ) {
		return $default;
	}
	return array(
		'url'    => ! empty( $value['url'] ) ? $value['url'] : $default['url'],
		'title'  => ! empty( $value['title'] ) ? $value['title'] : $default['title'],
		'target' => ! empty( $value['target'] ) ? $value['target'] : $default['target'],
	);
};
$tab_file = static function ( $value, $default = array() ) {
	$default = wp_parse_args( is_array( $default ) ? $default : array(), array( 'url' => '#', 'title' => '' ) );
	if ( is_numeric( $value ) ) {
		$value = get_post( (int) $value );
		return $value ? array( 'url' => wp_get_attachment_url( $value->ID ), 'title' => get_the_title( $value ) ) : $default;
	}
	if ( is_string( $value ) ) {
		return array( 'url' => $value, 'title' => $default['title'] );
	}
	if ( ! is_array( $value ) ) {
		return $default;
	}
	return array(
		'url'   => ! empty( $value['url'] ) ? $value['url'] : $default['url'],
		'title' => ! empty( $value['title'] ) ? $value['title'] : $default['title'],
	);
};
$tab_image = static function ( $value, $fallback = '', $fallback_alt = '' ) {
	$image = array( 'url' => '', 'alt' => $fallback_alt );
	if ( is_array( $value ) ) {
		$image['url'] = ! empty( $value['url'] ) ? $value['url'] : '';
		$image['alt'] = ! empty( $value['alt'] ) ? $value['alt'] : $fallback_alt;
		if ( ! $image['url'] && ! empty( $value['ID'] ) ) {
			$image['url'] = wp_get_attachment_image_url( (int) $value['ID'], 'full' );
		}
	} elseif ( is_numeric( $value ) ) {
		$image['url'] = wp_get_attachment_image_url( (int) $value, 'full' );
	} elseif ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
		$image['url'] = $value;
	}
	if ( ! $image['url'] && $fallback ) {
		$image['url'] = filter_var( $fallback, FILTER_VALIDATE_URL ) ? $fallback : get_theme_file_uri( ltrim( $fallback, '/' ) );
	}
	return $image;
};
PHP;
	}

	private static function read_archive( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return self::read_archive_with_pclzip( $path );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new RuntimeException( __( 'The uploaded ZIP file could not be opened.', 'theme-agent-blueprint' ) );
		}
		if ( $zip->numFiles > self::MAX_FILES ) {
			$zip->close();
			throw new RuntimeException( __( 'The ZIP contains too many files.', 'theme-agent-blueprint' ) );
		}

		$allowed = array( 'html', 'htm', 'css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot' );
		$entries = array();
		$total = 0;
		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$stat = $zip->statIndex( $index );
			$name = self::normalize_archive_path( $stat['name'] );
			if ( '' === $name || '/' === substr( $stat['name'], -1 ) ) {
				continue;
			}
			if ( 0 === strpos( $name, '../' ) || false !== strpos( $name, '/..' ) || preg_match( '/^[a-zA-Z]:/', $name ) ) {
				$zip->close();
				throw new RuntimeException( __( 'The ZIP contains an unsafe file path.', 'theme-agent-blueprint' ) );
			}
			$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $extension, $allowed, true ) ) {
				continue;
			}
			$total += (int) $stat['size'];
			if ( $total > self::MAX_EXPANDED_SIZE ) {
				$zip->close();
				throw new RuntimeException( __( 'The expanded ZIP contents exceed 50 MB.', 'theme-agent-blueprint' ) );
			}
			$content = $zip->getFromIndex( $index );
			if ( false === $content ) {
				$zip->close();
				throw new RuntimeException( __( 'A file in the ZIP could not be read.', 'theme-agent-blueprint' ) );
			}
			$entries[ $name ] = $content;
		}
		$zip->close();
		return $entries;
	}

	private static function read_archive_with_pclzip( $path ) {
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$zip = new PclZip( $path );
		$list = $zip->listContent();
		if ( 0 === $list || false === $list ) {
			throw new RuntimeException( __( 'The uploaded ZIP file could not be opened.', 'theme-agent-blueprint' ) );
		}
		if ( count( $list ) > self::MAX_FILES ) {
			throw new RuntimeException( __( 'The ZIP contains too many files.', 'theme-agent-blueprint' ) );
		}

		$allowed = array( 'html', 'htm', 'css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot' );
		$entries = array();
		$total = 0;
		foreach ( $list as $stat ) {
			$name = self::normalize_archive_path( $stat['filename'] );
			if ( ! empty( $stat['folder'] ) || '' === $name ) {
				continue;
			}
			if ( 0 === strpos( $name, '../' ) || false !== strpos( $name, '/..' ) || preg_match( '/^[a-zA-Z]:/', $name ) ) {
				throw new RuntimeException( __( 'The ZIP contains an unsafe file path.', 'theme-agent-blueprint' ) );
			}
			$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $extension, $allowed, true ) ) {
				continue;
			}
			$total += (int) $stat['size'];
			if ( $total > self::MAX_EXPANDED_SIZE ) {
				throw new RuntimeException( __( 'The expanded ZIP contents exceed 50 MB.', 'theme-agent-blueprint' ) );
			}
			$extracted = $zip->extract( PCLZIP_OPT_BY_NAME, $stat['filename'], PCLZIP_OPT_EXTRACT_AS_STRING );
			if ( 0 === $extracted || false === $extracted || ! isset( $extracted[0]['content'] ) ) {
				throw new RuntimeException( __( 'A file in the ZIP could not be read.', 'theme-agent-blueprint' ) );
			}
			$entries[ $name ] = $extracted[0]['content'];
		}
		return $entries;
	}

	private static function find_html_file( $entries, $requested ) {
		$html_files = array();
		foreach ( array_keys( $entries ) as $path ) {
			if ( in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), array( 'html', 'htm' ), true ) ) {
				$html_files[] = $path;
			}
		}
		if ( $requested ) {
			foreach ( $html_files as $path ) {
				if ( 0 === strcasecmp( $path, $requested ) || 0 === strcasecmp( basename( $path ), basename( $requested ) ) ) {
					return $path;
				}
			}
			throw new RuntimeException( __( 'The named HTML file was not found in the ZIP.', 'theme-agent-blueprint' ) );
		}
		if ( 1 !== count( $html_files ) ) {
			throw new RuntimeException( __( 'Enter the HTML filename when the ZIP contains none or more than one HTML file.', 'theme-agent-blueprint' ) );
		}
		return reset( $html_files );
	}

	private static function prepare_media( $entries, $settings, $theme_root, $required = null ) {
		$image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'ico' );
		$font_extensions  = array( 'woff', 'woff2', 'ttf', 'otf', 'eot' );
		$map = array();
		$writes = array();
		$used = array();
		$content_targets = array();
		foreach ( $entries as $source => $content ) {
			if ( is_array( $required ) && ! isset( $required[ $source ] ) ) {
				continue;
			}
			$extension = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );
			if ( in_array( $extension, $image_extensions, true ) ) {
				$directory = self::theme_path( $settings['images_dir'], $theme_root, true );
				$relative_dir = trim( self::theme_relative_path( $settings['images_dir'] ), '/' );
			} elseif ( in_array( $extension, $font_extensions, true ) ) {
				$directory = self::theme_path( $settings['fonts_dir'], $theme_root, true );
				$relative_dir = trim( self::theme_relative_path( $settings['fonts_dir'] ), '/' );
			} else {
				continue;
			}

			if ( 'svg' === $extension && preg_match( '/<\s*(?:script|foreignObject|iframe)\b|\bon[a-z]+\s*=|javascript\s*:|<\?php/i', $content ) ) {
				throw new RuntimeException( __( 'The ZIP contains an unsafe SVG with scripts or active content. Remove or sanitize it before importing.', 'theme-agent-blueprint' ) );
			}
			$content_hash = md5( $content );
			$content_key = $extension . '|' . $content_hash;
			if ( isset( $content_targets[ $content_key ] ) ) {
				$destination = $content_targets[ $content_key ];
				$filename = basename( $destination );
			} else {
				$existing = self::find_existing_media_by_hash( $directory, $content_hash, $extension, $theme_root );
				if ( $existing ) {
					$destination = $existing;
					$filename = basename( $destination );
				} else {
					$filename = sanitize_file_name( basename( $source ) );
					if ( isset( $used[ strtolower( $directory . '/' . $filename ) ] ) ) {
						$filename = pathinfo( $filename, PATHINFO_FILENAME ) . '-' . substr( md5( $source ), 0, 8 ) . '.' . $extension;
					}
					$destination = $directory . '/' . $filename;
					if ( file_exists( $destination ) && md5_file( $destination ) !== $content_hash ) {
						$filename = pathinfo( $filename, PATHINFO_FILENAME ) . '-' . substr( $content_hash, 0, 8 ) . '.' . $extension;
						$destination = $directory . '/' . $filename;
					}
				}
				$content_targets[ $content_key ] = $destination;
			}

			$used[ strtolower( $destination ) ] = true;
			if ( ! file_exists( $destination ) ) {
				$writes[ $destination ] = $content;
			}
			$map[ $source ] = array(
				'absolute' => $destination,
				'relative' => ltrim( substr( wp_normalize_path( $destination ), strlen( rtrim( $theme_root, '/' ) ) ), '/' ),
			);
		}
		return array( 'map' => $map, 'writes' => $writes );
	}

	private static function find_existing_media_by_hash( $directory, $hash, $extension, $theme_root = '' ) {
		static $hash_indexes = array();
		// Reuse media in the configured destination first, then elsewhere in the
		// active theme. Bound file-count and bytes to avoid expensive full scans.
		$directories = array_unique( array_filter( array( $directory, $theme_root ) ) );
		foreach ( $directories as $scan_root ) {
			$key = wp_normalize_path( $scan_root ) . '|' . $extension;
			if ( ! isset( $hash_indexes[ $key ] ) ) {
				$hash_indexes[ $key ] = array();
				if ( is_dir( $scan_root ) ) {
					$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $scan_root, FilesystemIterator::SKIP_DOTS ) );
					$scanned = 0;
					$hashed_bytes = 0;
					foreach ( $iterator as $file ) {
						if ( ++$scanned > 2500 || $hashed_bytes > 134217728 ) {
							break;
						}
						if ( ! $file->isFile() || $file->isLink() || strtolower( $file->getExtension() ) !== $extension || $file->getSize() > self::MAX_ARCHIVE_SIZE ) {
							continue;
						}
						$hashed_bytes += $file->getSize();
						$content_hash = md5_file( $file->getPathname() );
						if ( $content_hash ) {
							$hash_indexes[ $key ][ $content_hash ] = wp_normalize_path( $file->getPathname() );
						}
					}
				}
			}
			if ( isset( $hash_indexes[ $key ][ $hash ] ) ) {
				return $hash_indexes[ $key ][ $hash ];
			}
		}
		return '';
	}

	private static function extract_html_assets( $html, $html_path, $entries, $media_map ) {
		// Drop inactive markup before scanning the source. An HTML comment can contain
		// full obsolete sections, <script> tags and <link> tags that must not import.
		$html = preg_replace( '/<!--.*?-->/s', '', (string) $html );
		$styles = array();
		$scripts = array();
		$preserved = array();
		$dependencies = array();
		$html = preg_replace_callback( '/<link\b[^>]*href=([' . "'\"" . '])([^' . "'\"" . ']+)\1[^>]*>/i', function ( $match ) use ( &$styles, &$dependencies, $html_path, $entries ) {
			$path = self::resolve_archive_reference( $html_path, $match[2] );
			if ( $path && isset( $entries[ $path ] ) && 'css' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
				if ( self::is_vendor_asset( $path, 'css' ) ) {
					$dependencies[] = basename( $path );
				} else {
					$styles[] = array( 'path' => $path, 'content' => $entries[ $path ] );
				}
				return '';
			}
			$dependencies[] = $match[2];
			// Do not insert source document stylesheet links inside a WP template's body.
			return '';
		}, $html );
		$html = preg_replace_callback( '/<style\b[^>]*>(.*?)<\/style>/is', function ( $match ) use ( &$styles, $html_path ) {
			$styles[] = array( 'path' => $html_path, 'content' => $match[1] );
			return '';
		}, $html );
		$html = preg_replace_callback( '/<script\b([^>]*)>(.*?)<\/script>/is', function ( $match ) use ( &$scripts, &$dependencies, $html_path, $entries ) {
			if ( preg_match( '/\btype=([' . "'\"" . '])application\/ld\+json\1/i', $match[1] ) ) {
				return '';
			}
			if ( preg_match( '/\bsrc=([' . "'\"" . '])([^' . "'\"" . ']+)\1/i', $match[1], $source ) ) {
				$path = self::resolve_archive_reference( $html_path, $source[2] );
				if ( $path && isset( $entries[ $path ] ) && 'js' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
					if ( self::is_vendor_asset( $path, 'js' ) ) {
						$dependencies[] = basename( $path );
					} else {
						$scripts[] = $entries[ $path ];
					}
					return '';
				}
				$dependencies[] = $source[2];
				return ''; // External JavaScript must be handled by WordPress' enqueue system.
			}
			if ( '' !== trim( $match[2] ) ) {
				$scripts[] = $match[2];
			}
			return '';
		}, $html );
		if ( preg_match( '/<body\b[^>]*>(.*?)<\/body>/is', $html, $body ) ) {
			$html = $body[1];
		} else {
			$html = preg_replace( '/<!doctype[^>]*>|<\/?(?:html|head)\b[^>]*>/i', '', $html );
		}
		$html = preg_replace( '/<\?(?:php|=)?.*?\?>/is', '', $html );
		// JSON-LD is document-level metadata and must not be injected inside <main>.
		$html = trim( $html );
		return array( 'html' => $html, 'styles' => $styles, 'scripts' => $scripts, 'dependencies' => $dependencies );
	}

	private static function collect_body_usage_context( $html ) {
		$usage = array( 'tags' => array(), 'ids' => array(), 'classes' => array(), 'attrs' => array() );
		if ( ! class_exists( 'DOMDocument' ) ) {
			// Test harness / constrained servers: collect conservative attributes without DOM.
			if ( preg_match_all( '/<([a-z][a-z0-9-]*)\b([^>]*)>/i', $html, $elements, PREG_SET_ORDER ) ) {
				foreach ( $elements as $element ) {
					$usage['tags'][ strtolower( $element[1] ) ] = true;
					if ( preg_match_all( '/([a-z_:][a-z0-9_:.-]*)\s*=\s*["\']([^"\']*)["\']/i', $element[2], $attributes, PREG_SET_ORDER ) ) {
						foreach ( $attributes as $attribute ) {
							$name = strtolower( $attribute[1] );
							$usage['attrs'][ $name ] = true;
							if ( 'id' === $name ) $usage['ids'][ $attribute[2] ] = true;
							if ( 'class' === $name ) foreach ( preg_split( '/\s+/', trim( $attribute[2] ) ) as $class ) if ( $class ) $usage['classes'][ $class ] = true;
						}
					}
				}
			}
			return $usage;
		}
		libxml_use_internal_errors( true );
		$document = new DOMDocument( '1.0', 'UTF-8' );
		$document->loadHTML( '<?xml encoding="utf-8" ?><!doctype html><html><body><div id="tab-import-root">' . $html . '</div></body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		$xpath = new DOMXPath( $document );
		$root = $xpath->query( '//*[@id="tab-import-root"]' )->item( 0 );
		if ( ! $root ) {
			return $usage;
		}
		foreach ( $xpath->query( './/*', $root ) as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			$usage['tags'][ strtolower( $node->tagName ) ] = true;
			if ( $node->hasAttribute( 'id' ) && '' !== trim( $node->getAttribute( 'id' ) ) ) {
				$usage['ids'][ trim( $node->getAttribute( 'id' ) ) ] = true;
			}
			if ( $node->hasAttribute( 'class' ) ) {
				foreach ( preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ) ) as $class ) {
					if ( '' !== $class ) {
						$usage['classes'][ $class ] = true;
					}
				}
			}
			foreach ( $node->attributes as $attribute ) {
				$usage['attrs'][ strtolower( $attribute->name ) ] = true;
			}
		}
		return $usage;
	}

	private static function is_vendor_asset( $path, $type ) {
		$name = strtolower( basename( $path ) );
		// Vendor bundles must be managed as dependencies, not appended to a template's shared files.
		return (bool) preg_match( '/^(?:bootstrap|jquery(?:-|\.)|owl[.-]carousel|font[-.]awesome|all(?:\.min)?\.css|normalize|reset|slick|swiper|popper)(?:[.-]|$)/i', $name );
	}

	private static function referenced_media( $body, $html_path, $styles, $usage, $scripts, $entries ) {
		$needed = array();
		$collect = static function ( $source, $content ) use ( &$needed, $entries ) {
			if ( preg_match_all( '/(?:src|poster|href)\s*=\s*["\']([^"\']+)["\']|url\(\s*["\']?([^\)"\']+)/i', $content, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $found ) {
					$reference = ! empty( $found[1] ) ? $found[1] : ( isset( $found[2] ) ? $found[2] : '' );
					$path = self::resolve_archive_reference( $source, $reference );
					if ( $path && isset( $entries[ $path ] ) ) {
						$needed[ $path ] = true;
					}
				}
			}
			if ( preg_match_all( '/srcset\s*=\s*["\']([^"\']+)["\']/i', $content, $sets ) ) {
				foreach ( $sets[1] as $srcset ) {
					foreach ( explode( ',', $srcset ) as $candidate ) {
						$parts = preg_split( '/\s+/', trim( $candidate ) );
						$path = self::resolve_archive_reference( $source, $parts[0] );
						if ( $path && isset( $entries[ $path ] ) ) {
							$needed[ $path ] = true;
						}
					}
				}
			}
		};
		$collect( $html_path, $body );
		foreach ( $styles as $style ) {
			$filtered = self::filter_css_for_usage( $style['content'], $usage );
			$collect( $style['path'], $filtered );
		}
		// Explicit local media paths in selected scripts (e.g. an image swapped by JS).
		foreach ( $scripts as $script ) {
			if ( ! self::script_targets_usage( $script, $usage ) ) {
				continue;
			}
			foreach ( $entries as $path => $content ) {
				if ( preg_match( '/\.(?:jpe?g|png|webp|avif|svg|gif|ico)$/i', $path ) && strpos( $script, basename( $path ) ) !== false ) {
					$needed[ $path ] = true;
				}
			}
		}
		return $needed;
	}

	private static function build_css( $styles, $media_map, $usage, $slug = '' ) {
		$combined = '';
		foreach ( $styles as $style ) {
			$content = self::filter_css_for_usage( $style['content'], $usage );
			if ( '' === trim( $content ) ) {
				continue;
			}
			$content = preg_replace_callback( '/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i', function ( $match ) use ( $style, $media_map ) {
				$path = self::resolve_archive_reference( $style['path'], $match[2] );
				if ( $path && isset( $media_map[ $path ] ) ) {
					return 'url("' . esc_url_raw( get_theme_file_uri( $media_map[ $path ]['relative'] ) ) . '")';
				}
				return $match[0];
			}, $content );
			$combined .= "\n" . $content . "\n";
		}
		$combined = trim( $combined );
		return $slug && $combined ? self::scope_css( $combined, $slug ) : $combined;
	}

	private static function filter_css_for_usage( $css, $usage ) {
		$css = trim( preg_replace( '#/\*.*?\*/#s', '', (string) $css ) );
		if ( '' === $css ) {
			return '';
		}
		$length = strlen( $css );
		$cursor = 0;
		$output = '';
		$deferred = array();
		while ( $cursor < $length ) {
			$open = strpos( $css, '{', $cursor );
			if ( false === $open ) break;
			$selector = trim( substr( $css, $cursor, $open - $cursor ) );
			$depth = 1;
			$close = $open + 1;
			$quote = '';
			while ( $close < $length && $depth > 0 ) {
				$char = $css[ $close ];
				if ( $quote ) {
					if ( $char === $quote && ( 0 === $close || '\\' !== $css[ $close - 1 ] ) ) $quote = '';
				} elseif ( '"' === $char || "'" === $char ) {
					$quote = $char;
				} elseif ( '{' === $char ) {
					$depth++;
				} elseif ( '}' === $char ) {
					$depth--;
				}
				$close++;
			}
			if ( $depth > 0 ) break;
			$body = trim( substr( $css, $open + 1, $close - $open - 2 ) );
			$cursor = $close;
			if ( '' === $selector || '' === $body ) continue;
			if ( preg_match( '/^@(media|supports|layer|container)\b/i', $selector ) ) {
				$nested = self::filter_css_for_usage( $body, $usage );
				if ( $nested ) $output .= $selector . " {\n" . $nested . "\n}\n";
				continue;
			}
			if ( preg_match( '/^@(font-face|keyframes|[-a-z]+keyframes|property)\b/i', $selector ) ) {
				$deferred[] = array( $selector, $body );
				continue;
			}
			if ( '@' === $selector[0] ) continue; // No global @import or unknown at-rule injection.
			$matches = array();
			foreach ( explode( ',', $selector ) as $segment ) {
				$segment = trim( $segment );
				if ( $segment && self::selector_targets_usage( $segment, $usage ) ) $matches[] = $segment;
			}
			if ( $matches ) $output .= implode( ', ', $matches ) . " {\n" . $body . "\n}\n";
		}
		foreach ( $deferred as $rule ) {
			if ( preg_match( '/^@(?:[-a-z]+)?keyframes\s+([\w-]+)/i', $rule[0], $match ) ) {
				if ( ! preg_match( '/\banimation(?:-name)?\s*:[^;}]*(?<![\w-])' . preg_quote( $match[1], '/' ) . '(?![\w-])/i', $output ) ) continue;
			} elseif ( preg_match( '/^@font-face\b/i', $rule[0] ) ) {
				if ( ! preg_match( '/font-family\s*:\s*(["\']?)([^;"\']+)\1/i', $rule[1], $match ) ) continue;
				if ( ! preg_match( '/font-family\s*:[^;}]*' . preg_quote( trim( $match[2] ), '/' ) . '/i', $output ) ) continue;
			} else continue;
			$output .= $rule[0] . " {\n" . $rule[1] . "\n}\n";
		}
		return trim( $output );
	}

	private static function selector_targets_usage( $selector, $usage ) {
		// Avoid importing document-wide reset / global styles just because body exists.
		if ( preg_match( '/^(?:\s*(?::root|html|body|\*)\s*)+$/i', trim( $selector ) ) ) {
			return false;
		}
		// Every explicit ID/class in a selector must exist in the retained template.
		// A single common class in an unrelated component is not enough.
		$selector = preg_replace( '/::?[a-zA-Z-]+(?:\([^()]*\))?/', '', trim( $selector ) );
		if ( preg_match_all( '/#([a-zA-Z0-9_-]+)/', $selector, $ids ) ) {
			foreach ( $ids[1] as $id ) if ( ! isset( $usage['ids'][ $id ] ) ) return false;
		}
		if ( preg_match_all( '/\.([a-zA-Z0-9_-]+)/', $selector, $classes ) ) {
			foreach ( $classes[1] as $class ) if ( ! isset( $usage['classes'][ $class ] ) ) return false;
		}
		if ( preg_match_all( '/\[\s*([a-zA-Z0-9_-]+)/', $selector, $attributes ) ) {
			foreach ( $attributes[1] as $attribute ) if ( ! isset( $usage['attrs'][ strtolower( $attribute ) ] ) ) return false;
		}
		$tag_selector = preg_replace( '/[#.][a-zA-Z0-9_-]+|\[[^\]]+\]/', ' ', $selector );
		if ( preg_match_all( '/\b([a-z][a-z0-9-]*)\b/i', $tag_selector, $tags ) ) {
			foreach ( $tags[1] as $tag ) {
				if ( in_array( strtolower( $tag ), array( 'html', 'body', 'root' ), true ) ) continue;
				if ( ! isset( $usage['tags'][ strtolower( $tag ) ] ) ) return false;
			}
		}
		return true;
	}

	private static function scope_css( $css, $slug ) {
		$root = '#tab-template-' . sanitize_title( $slug );
		$output = '';
		$cursor = 0;
		$length = strlen( $css );
		while ( $cursor < $length ) {
			$open = strpos( $css, '{', $cursor );
			if ( false === $open ) break;
			$selector = trim( substr( $css, $cursor, $open - $cursor ) );
			$depth = 1;
			$close = $open + 1;
			$quote = '';
			while ( $close < $length && $depth ) {
				$char = $css[ $close ];
				if ( $quote ) {
					if ( $char === $quote && '\\' !== $css[ $close - 1 ] ) $quote = '';
				} elseif ( '"' === $char || "'" === $char ) {
					$quote = $char;
				} elseif ( '{' === $char ) $depth++;
				elseif ( '}' === $char ) $depth--;
				$close++;
			}
			if ( $depth ) break;
			$body = trim( substr( $css, $open + 1, $close - $open - 2 ) );
			$cursor = $close;
			if ( '' === $selector || '' === $body ) continue;
			if ( preg_match( '/^@(media|supports|layer|container)\b/i', $selector ) ) {
				$nested = self::scope_css( $body, $slug );
				if ( $nested ) $output .= $selector . " {\n" . $nested . "\n}\n";
			} elseif ( '@' === $selector[0] ) {
				// Preserve animation and font-face internals, never scope keyframe steps.
				$output .= $selector . " {\n" . $body . "\n}\n";
			} else {
				$parts = array();
				foreach ( explode( ',', $selector ) as $part ) {
					$part = trim( $part );
					$part = preg_replace( '/^(?:html\s+)?body(?=\s|\.|#|\[|:|$)/i', $root, $part );
					$part = preg_replace( '/^:root\b/', $root, $part );
					if ( '' !== $part ) $parts[] = 0 === strpos( $part, $root ) ? $part : $root . ' ' . $part;
				}
				if ( $parts ) $output .= implode( ', ', $parts ) . " {\n" . $body . "\n}\n";
			}
		}
		return trim( $output );
	}

	private static function build_js( $scripts, $slug, $usage, &$skipped = 0 ) {
		$selected = array();
		$skipped = 0;
		foreach ( $scripts as $script ) {
			$script = trim( (string) $script );
			if ( $script && self::script_targets_usage( $script, $usage ) ) {
				if ( ! in_array( $script, $selected, true ) ) $selected[] = $script;
			} elseif ( $script ) {
				$skipped++;
			}
		}
		if ( ! $selected ) return '';
		$source = implode( "\n\n", $selected );
		return "(function () {\n\t'use strict';\n\tfunction tabImportedTemplateInit() {\n\t\tif (!document.getElementById('tab-template-" . esc_js( $slug ) . "')) return;\n" . $source . "\n\t}\n\tif (document.readyState === 'loading') {\n\t\tdocument.addEventListener('DOMContentLoaded', tabImportedTemplateInit);\n\t} else {\n\t\ttabImportedTemplateInit();\n\t}\n}());";
	}

	private static function script_targets_usage( $script, $usage ) {
		$selectors = array();
		if ( preg_match_all( '/(?:querySelector(?:All)?|closest|matches)\(\s*(["\'])(.*?)\1\s*\)/s', $script, $matches ) ) {
			$selectors = array_merge( $selectors, $matches[2] );
		}
		if ( preg_match_all( '/getElementById\(\s*["\']([\w-]+)["\']\s*\)/', $script, $ids ) ) {
			foreach ( $ids[1] as $id ) $selectors[] = '#' . $id;
		}
		if ( preg_match_all( '/getElementsByClassName\(\s*["\']([\w-]+)["\']\s*\)/', $script, $classes ) ) {
			foreach ( $classes[1] as $class ) $selectors[] = '.' . $class;
		}
		if ( preg_match_all( '/(?:jQuery|\$)\(\s*(["\'])(.*?)\1\s*\)/s', $script, $jq_matches ) ) {
			$selectors = array_merge( $selectors, $jq_matches[2] );
		}
		if ( ! $selectors ) return false;
		$matched = false;
		foreach ( $selectors as $selector ) {
			$segments = array_filter( array_map( 'trim', explode( ',', $selector ) ) );
			foreach ( $segments as $segment ) {
				// Unknown selectors indicate the script mixes unrelated page logic.
				if ( ! self::selector_targets_usage( $segment, $usage ) ) return false;
				$matched = true;
			}
		}
		return $matched;
	}

	private static function rewrite_html_media( $html, $html_path, $media_map ) {
		$html = preg_replace_callback( '/\b(src|poster)=([' . "'\"" . '])([^' . "'\"" . ']+)\2/i', function ( $match ) use ( $html_path, $media_map ) {
			$path = self::resolve_archive_reference( $html_path, $match[3] );
			if ( $path && isset( $media_map[ $path ] ) ) {
				return $match[1] . '="<?php echo esc_url( get_theme_file_uri( ' . var_export( $media_map[ $path ]['relative'], true ) . ' ) ); ?>"';
			}
			return $match[0];
		}, $html );
		$html = preg_replace_callback( '/\bsrcset=([' . "'\"" . '])([^' . "'\"" . ']+)\1/i', function ( $match ) use ( $html_path, $media_map ) {
			$candidates = array();
			foreach ( explode( ',', $match[2] ) as $candidate ) {
				$parts = preg_split( '/\s+/', trim( $candidate ), 2 );
				$path = self::resolve_archive_reference( $html_path, $parts[0] );
				if ( $path && isset( $media_map[ $path ] ) ) {
					$parts[0] = '<?php echo esc_url( get_theme_file_uri( ' . var_export( $media_map[ $path ]['relative'], true ) . ' ) ); ?>';
				}
				$candidates[] = implode( ' ', $parts );
			}
			return 'srcset=' . $match[1] . implode( ', ', $candidates ) . $match[1];
		}, $html );
		return preg_replace_callback( '/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i', function ( $match ) use ( $html_path, $media_map ) {
			$path = self::resolve_archive_reference( $html_path, $match[2] );
			if ( $path && isset( $media_map[ $path ] ) ) {
				return 'url(<?php echo esc_url( get_theme_file_uri( ' . var_export( $media_map[ $path ]['relative'], true ) . ' ) ); ?>)';
			}
			return $match[0];
		}, $html );
	}

	private static function append_asset( $path, $content, $type, $slug ) {
		$current = file_get_contents( $path );
		if ( false === $current ) {
			throw new RuntimeException( __( 'A configured asset file could not be read.', 'theme-agent-blueprint' ) );
		}
		$start = 'CSS' === $type ? '/* START: template-' . $slug . ' */' : '// START: template-' . $slug;
		$end   = 'CSS' === $type ? '/* END: template-' . $slug . ' */' : '// END: template-' . $slug;
		if ( false !== strpos( $current, $start ) ) {
			throw new RuntimeException( sprintf( __( '%s already contains this template block.', 'theme-agent-blueprint' ), $type ) );
		}

		$payload = trim( $content );
		if ( '' === $payload || self::asset_payload_exists( $current, $payload, $type ) ) {
			return array( 'content' => $current, 'appended' => false );
		}

		return array(
			'content'  => rtrim( $current ) . "\n\n" . $start . "\n" . $payload . "\n" . $end . "\n",
			'appended' => true,
		);
	}

	private static function asset_payload_exists( $current, $payload, $type ) {
		if ( false !== strpos( $current, $payload ) ) {
			return true;
		}
		$current_norm = self::normalize_asset_payload( $current, $type );
		$payload_norm = self::normalize_asset_payload( $payload, $type );
		return '' !== $payload_norm && false !== strpos( $current_norm, $payload_norm );
	}

	private static function normalize_asset_payload( $content, $type ) {
		$content = (string) $content;
		if ( 'CSS' === $type ) {
			$content = preg_replace( '#/\*.*?\*/#s', '', $content );
		}
		$content = preg_replace( '/\s+/', '', $content );
		return strtolower( trim( $content ) );
	}

	private static function asset_target( $configured, $fallbacks, $theme_root, $extension ) {
		$candidates = preg_split( '/\r\n|\r|\n/', (string) $configured );
		$candidates = array_merge( array_filter( array_map( 'trim', $candidates ) ), $fallbacks );
		foreach ( $candidates as $candidate ) {
			$path = self::theme_path( $candidate, $theme_root, false );
			if ( $path && file_exists( $path ) && $extension === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
				return $path;
			}
		}
		throw new RuntimeException( sprintf( __( 'No existing shared %s file was found. Save a valid theme-relative path in the project profile.', 'theme-agent-blueprint' ), strtoupper( $extension ) ) );
	}

	private static function theme_path( $relative, $theme_root, $directory ) {
		$relative = self::theme_relative_path( $relative );
		if ( '' === $relative && ! $directory ) {
			return '';
		}
		$path = wp_normalize_path( $theme_root . '/' . $relative );
		if ( $path !== $theme_root && 0 !== strpos( $path, $theme_root . '/' ) ) {
			throw new RuntimeException( __( 'A configured theme path is outside the active theme.', 'theme-agent-blueprint' ) );
		}
		return rtrim( $path, '/' );
	}

	private static function theme_relative_path( $path ) {
		$path = wp_normalize_path( trim( (string) $path ) );
		$theme_prefix = 'wp-content/themes/' . get_stylesheet() . '/';
		if ( 0 === strpos( $path, $theme_prefix ) ) {
			$path = substr( $path, strlen( $theme_prefix ) );
		}
		$parts = array();
		foreach ( explode( '/', ltrim( $path, '/' ) ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				throw new RuntimeException( __( 'Theme paths cannot contain parent-directory references.', 'theme-agent-blueprint' ) );
			}
			$parts[] = $part;
		}
		return implode( '/', $parts );
	}

	private static function resolve_archive_reference( $source, $reference ) {
		$reference = rawurldecode( preg_replace( '/[?#].*$/', '', trim( $reference ) ) );
		if ( '' === $reference || 0 === strpos( $reference, 'data:' ) || preg_match( '#^(?:[a-z]+:)?//#i', $reference ) || 0 === strpos( $reference, '#' ) ) {
			return '';
		}
		$path = 0 === strpos( $reference, '/' ) ? ltrim( $reference, '/' ) : dirname( $source ) . '/' . $reference;
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

	private static function normalize_archive_path( $path ) {
		return ltrim( wp_normalize_path( trim( (string) $path ) ), '/' );
	}

	private static function commit( $writes, $theme_root ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			throw new RuntimeException( __( 'WordPress could not initialize filesystem access.', 'theme-agent-blueprint' ) );
		}

		$backups = array();
		foreach ( $writes as $path => $content ) {
			$path = wp_normalize_path( $path );
			if ( 0 !== strpos( $path, $theme_root . '/' ) ) {
				throw new RuntimeException( __( 'An import destination is outside the active theme.', 'theme-agent-blueprint' ) );
			}
			$directory = dirname( $path );
			if ( ! $wp_filesystem->is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
				self::rollback( $backups );
				throw new RuntimeException( __( 'A destination directory could not be created.', 'theme-agent-blueprint' ) );
			}
			$backups[ $path ] = $wp_filesystem->exists( $path ) ? $wp_filesystem->get_contents( $path ) : null;
			if ( ! $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE ) ) {
				self::rollback( $backups );
				throw new RuntimeException( __( 'The import could not write all theme files. Previous changes were restored.', 'theme-agent-blueprint' ) );
			}
		}
	}

	private static function rollback( $backups ) {
		global $wp_filesystem;
		foreach ( array_reverse( $backups, true ) as $path => $content ) {
			if ( null === $content ) {
				$wp_filesystem->delete( $path );
			} else {
				$wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
			}
		}
	}

	private static function redirect( $type, $message ) {
		$key = wp_generate_password( 12, false, false );
		set_transient( 'tab_import_' . get_current_user_id() . '_' . $key, array( 'type' => $type, 'message' => sanitize_text_field( $message ) ), 300 );
		wp_safe_redirect( add_query_arg( array( 'page' => TAB_Theme_Agent_Blueprint_V111::SLUG, 'tab_import' => $key ), admin_url( 'admin.php' ) ) );
		exit;
	}
}