<?php

defined( 'ABSPATH' ) || exit;

final class TAB_Template_History {
	const OPTION = 'tab_blueprint_import_history';
	const LIMIT  = 100;

	public static function prepare_import( $name, $slug, $theme_root, $writes, $blocks, $asset_paths ) {
		self::filesystem();
		$id = 'import_' . gmdate( 'YmdHis' ) . '_' . strtolower( wp_generate_password( 20, false, false ) );
		$base_directory = self::snapshot_base_directory();
		if ( ! wp_mkdir_p( $base_directory ) ) {
			throw new RuntimeException( __( 'The import restore snapshot directory could not be created.', 'theme-agent-blueprint' ) );
		}
		self::protect_snapshot_directory( $base_directory );
		$directory = self::snapshot_directory( $id );
		if ( ! wp_mkdir_p( $directory ) ) {
			throw new RuntimeException( __( 'The import restore snapshot directory could not be created.', 'theme-agent-blueprint' ) );
		}

		$record = array(
			'id'         => $id,
			'name'       => sanitize_text_field( $name ),
			'slug'       => sanitize_title( $slug ),
			'theme'      => sanitize_file_name( get_stylesheet() ),
			'created_at' => current_time( 'mysql', true ),
			'removed_at' => '',
			'status'     => 'active',
			'files'      => array(),
		);

		try {
			$index = 0;
			foreach ( $writes as $path => $content ) {
				$path = wp_normalize_path( $path );
				$relative = self::relative_path( $path, $theme_root );
				$mode = isset( $blocks[ $path ] ) ? 'block' : ( in_array( $path, $asset_paths, true ) ? 'asset' : 'created' );
				$stored_content = 'block' === $mode ? self::extract_block( $content, $blocks[ $path ], $slug ) : $content;
				$backup = 'file-' . $index++ . '.dat';
				self::write_backup( $directory . '/' . $backup, $stored_content );
				$record['files'][] = array(
					'path'   => $relative,
					'mode'   => $mode,
					'type'   => isset( $blocks[ $path ] ) ? $blocks[ $path ] : '',
					'hash'   => md5( 'block' === $mode ? $stored_content : $content ),
					'backup' => $backup,
					'owned'  => true,
				);
			}

			foreach ( array_unique( $asset_paths ) as $path ) {
				$path = wp_normalize_path( $path );
				if ( isset( $writes[ $path ] ) ) {
					continue;
				}
				$record['files'][] = array(
					'path'   => self::relative_path( $path, $theme_root ),
					'mode'   => 'asset',
					'type'   => '',
					'hash'   => file_exists( $path ) ? md5_file( $path ) : '',
					'backup' => '',
					'owned'  => false,
				);
			}

			self::write_backup( $directory . '/index.html', '' );
			self::write_backup( $directory . '/manifest.json', wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} catch ( Throwable $exception ) {
			self::delete_snapshot( $id );
			throw $exception;
		}

		return $record;
	}

	public static function finalize_import( $record ) {
		$history = self::get_history();
		array_unshift( $history, $record );
		$discarded = array_slice( $history, self::LIMIT );
		$history = array_slice( $history, 0, self::LIMIT );
		update_option( self::OPTION, $history, false );
		foreach ( $discarded as $old_record ) {
			if ( ! empty( $old_record['id'] ) ) {
				self::delete_snapshot( $old_record['id'] );
			}
		}
	}

	public static function discard_import( $record ) {
		if ( ! empty( $record['id'] ) ) {
			self::delete_snapshot( $record['id'] );
		}
	}

	public static function handle_remove() {
		self::authorize( 'tab_remove_template' );
		try {
			$id = self::posted_id();
			$history = self::get_history();
			$index = self::find_index( $history, $id );
			if ( 'active' !== $history[ $index ]['status'] ) {
				throw new RuntimeException( __( 'This template import is already removed.', 'theme-agent-blueprint' ) );
			}
			self::remove_record( $history, $index );
			self::redirect( 'success', __( 'Template import revoked. Its files and tracked asset additions were removed, and a restore snapshot remains available.', 'theme-agent-blueprint' ) );
		} catch ( Throwable $exception ) {
			self::redirect( 'error', $exception->getMessage() );
		}
	}

	public static function handle_restore() {
		self::authorize( 'tab_restore_template' );
		try {
			$id = self::posted_id();
			$history = self::get_history();
			$index = self::find_index( $history, $id );
			if ( 'removed' !== $history[ $index ]['status'] ) {
				throw new RuntimeException( __( 'This template import is already active.', 'theme-agent-blueprint' ) );
			}
			self::restore_record( $history, $index );
			self::redirect( 'success', __( 'Template files, assets, and shared CSS/JavaScript blocks were restored.', 'theme-agent-blueprint' ) );
		} catch ( Throwable $exception ) {
			self::redirect( 'error', $exception->getMessage() );
		}
	}

	public static function render_notice() {
		if ( empty( $_GET['tab_history'] ) ) {
			return;
		}
		$key = sanitize_key( wp_unslash( $_GET['tab_history'] ) );
		$data = get_transient( 'tab_history_' . get_current_user_id() . '_' . $key );
		if ( ! is_array( $data ) || empty( $data['message'] ) ) {
			return;
		}
		delete_transient( 'tab_history_' . get_current_user_id() . '_' . $key );
		add_settings_error( TAB_Theme_Agent_Blueprint_V111::SLUG, 'template_history', $data['message'], 'success' === $data['type'] ? 'success' : 'error' );
	}

	public static function render_page() {
		$history = self::get_history();
		?>
		<section class="tab-card tab-history-card">
			<div class="tab-card-heading">
				<div><span class="tab-step"><span class="dashicons dashicons-backup" aria-hidden="true"></span></span><h2><?php esc_html_e( 'Template history', 'theme-agent-blueprint' ); ?></h2></div>
				<p><?php esc_html_e( 'Revoke or restore every tracked file and asset block from an import.', 'theme-agent-blueprint' ); ?></p>
			</div>
			<?php if ( empty( $history ) ) : ?>
				<div class="tab-history-empty"><span class="dashicons dashicons-media-code" aria-hidden="true"></span><h3><?php esc_html_e( 'No tracked imports yet', 'theme-agent-blueprint' ); ?></h3><p><?php esc_html_e( 'Templates imported from now on will appear here with remove and restore controls.', 'theme-agent-blueprint' ); ?></p></div>
			<?php else : ?>
				<div class="tab-history-list">
					<?php foreach ( $history as $record ) : ?>
						<?php
						$is_active = 'active' === $record['status'];
						$file_count = count( array_filter( $record['files'], static function ( $file ) { return 'asset' !== $file['mode'] || ! empty( $file['owned'] ); } ) );
						?>
						<article class="tab-history-item">
							<div class="tab-history-main">
								<span class="tab-history-icon dashicons <?php echo $is_active ? 'dashicons-media-document' : 'dashicons-hidden'; ?>" aria-hidden="true"></span>
								<div><div class="tab-history-title"><h3><?php echo esc_html( $record['name'] ); ?></h3><span class="tab-status tab-status-<?php echo esc_attr( $record['status'] ); ?>"><?php echo esc_html( ucfirst( $record['status'] ) ); ?></span></div>
								<p><code><?php echo esc_html( 'template-' . $record['slug'] . '.php' ); ?></code><span><?php echo esc_html( sprintf( _n( '%d tracked file', '%d tracked files', $file_count, 'theme-agent-blueprint' ), $file_count ) ); ?></span><span><?php echo esc_html( get_date_from_gmt( $record['created_at'], 'M j, Y g:i a' ) ); ?></span></p></div>
							</div>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tab-history-action">
								<input type="hidden" name="action" value="<?php echo $is_active ? 'tab_remove_template' : 'tab_restore_template'; ?>">
								<input type="hidden" name="history_id" value="<?php echo esc_attr( $record['id'] ); ?>">
								<?php wp_nonce_field( $is_active ? 'tab_remove_template' : 'tab_restore_template', 'tab_history_nonce' ); ?>
								<button type="submit" class="button <?php echo $is_active ? 'tab-remove-button' : 'button-primary tab-restore-button'; ?>" data-confirm="<?php echo esc_attr( $is_active ? __( 'Revoke this template and all tracked additions? You can restore it later.', 'theme-agent-blueprint' ) : __( 'Restore this template and all tracked additions?', 'theme-agent-blueprint' ) ); ?>"><span class="dashicons <?php echo $is_active ? 'dashicons-undo' : 'dashicons-update'; ?>" aria-hidden="true"></span><?php echo esc_html( $is_active ? __( 'Revoke', 'theme-agent-blueprint' ) : __( 'Restore', 'theme-agent-blueprint' ) ); ?></button>
							</form>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function remove_record( &$history, $index ) {
		$record = $history[ $index ];
		$root = self::theme_root( $record );
		$changes = array();

		foreach ( $record['files'] as $file ) {
			$path = self::record_path( $root, $file['path'] );
			if ( 'block' === $file['mode'] ) {
				if ( ! file_exists( $path ) ) {
					continue;
				}
				$current = file_get_contents( $path );
				if ( 'absent' === self::block_status( $current, $file['type'], $record['slug'] ) ) {
					continue;
				}
				if ( 'partial' === self::block_status( $current, $file['type'], $record['slug'] ) ) {
					throw new RuntimeException( __( 'A tracked CSS or JavaScript marker is incomplete. Repair or remove the marker pair before revoking.', 'theme-agent-blueprint' ) );
				}
				$changes[ $path ] = self::remove_block( $current, $file['type'], $record['slug'] );
			} elseif ( 'created' === $file['mode'] ) {
				if ( file_exists( $path ) ) {
					$changes[ $path ] = null;
				}
			} elseif ( 'asset' === $file['mode'] && ! self::has_other_active_reference( $history, $record['id'], $file['path'] ) ) {
				$owner = self::find_asset_owner( $history, $file['path'] );
				if ( $owner && file_exists( $path ) ) {
					$changes[ $path ] = null;
				}
			}
		}

		self::apply_changes( $changes, $root );
		$history[ $index ]['status'] = 'removed';
		$history[ $index ]['removed_at'] = current_time( 'mysql', true );
		update_option( self::OPTION, $history, false );
	}

	private static function restore_record( &$history, $index ) {
		$record = $history[ $index ];
		$root = self::theme_root( $record );
		$changes = array();

		foreach ( $record['files'] as $file ) {
			$path = self::record_path( $root, $file['path'] );
			if ( 'block' === $file['mode'] ) {
				if ( ! file_exists( $path ) ) {
					throw new RuntimeException( __( 'A shared asset file is missing; restore was cancelled.', 'theme-agent-blueprint' ) );
				}
				$current = file_get_contents( $path );
				$block_status = self::block_status( $current, $file['type'], $record['slug'] );
				if ( 'complete' === $block_status ) {
					if ( md5( self::extract_block( $current, $file['type'], $record['slug'] ) ) === $file['hash'] ) {
						continue;
					}
					throw new RuntimeException( __( 'A different CSS or JavaScript block now uses this template marker. Restore was cancelled.', 'theme-agent-blueprint' ) );
				}
				if ( 'partial' === $block_status ) {
					throw new RuntimeException( __( 'A shared asset marker is incomplete; restore was cancelled to protect the file.', 'theme-agent-blueprint' ) );
				}
				$changes[ $path ] = rtrim( $current ) . "\n\n" . self::read_record_backup( $record, $file ) . "\n";
			} elseif ( 'created' === $file['mode'] ) {
				self::prepare_restore_file( $changes, $path, $record, $file );
			} elseif ( 'asset' === $file['mode'] ) {
				if ( file_exists( $path ) ) {
					if ( md5_file( $path ) !== $file['hash'] ) {
						throw new RuntimeException( __( 'A tracked media file was changed; restore was cancelled to protect it.', 'theme-agent-blueprint' ) );
					}
					continue;
				}
				$owner = self::find_asset_owner( $history, $file['path'] );
				if ( ! $owner ) {
					throw new RuntimeException( __( 'A media restore snapshot is unavailable.', 'theme-agent-blueprint' ) );
				}
				$changes[ $path ] = self::read_record_backup( $owner['record'], $owner['file'] );
			}
		}

		self::apply_changes( $changes, $root );
		$history[ $index ]['status'] = 'active';
		$history[ $index ]['removed_at'] = '';
		update_option( self::OPTION, $history, false );
	}

	private static function prepare_restore_file( &$changes, $path, $record, $file ) {
		if ( file_exists( $path ) ) {
			if ( md5_file( $path ) !== $file['hash'] ) {
				throw new RuntimeException( __( 'A destination file now exists with different content; restore was cancelled.', 'theme-agent-blueprint' ) );
			}
			return;
		}
		$changes[ $path ] = self::read_record_backup( $record, $file );
	}

	private static function apply_changes( $changes, $root ) {
		self::filesystem();
		global $wp_filesystem;
		$backups = array();
		foreach ( $changes as $path => $content ) {
			$path = wp_normalize_path( $path );
			if ( 0 !== strpos( $path, $root . '/' ) ) {
				self::rollback_changes( $backups );
				throw new RuntimeException( __( 'A history destination is outside the recorded theme.', 'theme-agent-blueprint' ) );
			}
			$backups[ $path ] = $wp_filesystem->exists( $path ) ? $wp_filesystem->get_contents( $path ) : null;
			$success = null === $content ? $wp_filesystem->delete( $path ) : ( wp_mkdir_p( dirname( $path ) ) && $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE ) );
			if ( ! $success ) {
				self::rollback_changes( $backups );
				throw new RuntimeException( __( 'The history operation failed. Completed file changes were rolled back.', 'theme-agent-blueprint' ) );
			}
		}
	}

	private static function rollback_changes( $backups ) {
		global $wp_filesystem;
		foreach ( array_reverse( $backups, true ) as $path => $content ) {
			if ( null === $content ) {
				$wp_filesystem->delete( $path );
			} else {
				$wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
			}
		}
	}

	private static function assert_unchanged( $path, $hash ) {
		if ( file_exists( $path ) && md5_file( $path ) !== $hash ) {
			throw new RuntimeException( __( 'A tracked file was changed after import. Removal was cancelled to protect your changes.', 'theme-agent-blueprint' ) );
		}
	}

	private static function extract_block( $content, $type, $slug ) {
		$bounds = self::block_bounds( $type, $slug );
		$start = strpos( $content, $bounds[0] );
		$end = false === $start ? false : strpos( $content, $bounds[1], $start );
		if ( false === $start || false === $end ) {
			throw new RuntimeException( __( 'A generated shared asset block could not be recorded.', 'theme-agent-blueprint' ) );
		}
		return substr( $content, $start, $end + strlen( $bounds[1] ) - $start );
	}

	private static function remove_block( $content, $type, $slug ) {
		$block = self::extract_block( $content, $type, $slug );
		return rtrim( str_replace( $block, '', $content ) ) . "\n";
	}

	private static function block_status( $content, $type, $slug ) {
		$bounds = self::block_bounds( $type, $slug );
		$has_start = false !== strpos( $content, $bounds[0] );
		$has_end = false !== strpos( $content, $bounds[1] );
		if ( $has_start && $has_end ) {
			return 'complete';
		}
		return $has_start || $has_end ? 'partial' : 'absent';
	}

	private static function block_bounds( $type, $slug ) {
		return 'CSS' === $type
			? array( '/* START: template-' . $slug . ' */', '/* END: template-' . $slug . ' */' )
			: array( '// START: template-' . $slug, '// END: template-' . $slug );
	}

	private static function has_other_active_reference( $history, $id, $path ) {
		foreach ( $history as $record ) {
			if ( $record['id'] === $id || 'active' !== $record['status'] ) {
				continue;
			}
			foreach ( $record['files'] as $file ) {
				if ( 'asset' === $file['mode'] && $file['path'] === $path ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function find_asset_owner( $history, $path ) {
		foreach ( $history as $record ) {
			foreach ( $record['files'] as $file ) {
				if ( 'asset' === $file['mode'] && $file['path'] === $path && ! empty( $file['owned'] ) && ! empty( $file['backup'] ) ) {
					return array( 'record' => $record, 'file' => $file );
				}
			}
		}
		return null;
	}

	private static function read_record_backup( $record, $file ) {
		$path = self::snapshot_directory( $record['id'] ) . '/' . sanitize_file_name( $file['backup'] );
		$content = file_get_contents( $path );
		if ( false === $content ) {
			throw new RuntimeException( __( 'A restore snapshot file is missing or unreadable.', 'theme-agent-blueprint' ) );
		}
		return $content;
	}

	private static function get_history() {
		$history = get_option( self::OPTION, array() );
		return is_array( $history ) ? array_values( $history ) : array();
	}

	private static function find_index( $history, $id ) {
		foreach ( $history as $index => $record ) {
			if ( isset( $record['id'] ) && hash_equals( $record['id'], $id ) ) {
				return $index;
			}
		}
		throw new RuntimeException( __( 'The selected history record was not found.', 'theme-agent-blueprint' ) );
	}

	private static function theme_root( $record ) {
		$theme = isset( $record['theme'] ) ? sanitize_file_name( $record['theme'] ) : '';
		if ( ! $theme ) {
			throw new RuntimeException( __( 'The recorded theme is invalid.', 'theme-agent-blueprint' ) );
		}
		return wp_normalize_path( WP_CONTENT_DIR . '/themes/' . $theme );
	}

	private static function record_path( $root, $relative ) {
		$relative = ltrim( wp_normalize_path( $relative ), '/' );
		if ( in_array( '..', explode( '/', $relative ), true ) || preg_match( '/^[a-zA-Z]:/', $relative ) ) {
			throw new RuntimeException( __( 'A recorded history path is invalid.', 'theme-agent-blueprint' ) );
		}
		return $root . '/' . $relative;
	}

	private static function relative_path( $path, $root ) {
		$root = rtrim( wp_normalize_path( $root ), '/' );
		if ( 0 !== strpos( $path, $root . '/' ) ) {
			throw new RuntimeException( __( 'An imported path is outside the active theme.', 'theme-agent-blueprint' ) );
		}
		return ltrim( substr( $path, strlen( $root ) ), '/' );
	}

	private static function snapshot_directory( $id ) {
		return self::snapshot_base_directory() . '/' . sanitize_file_name( $id );
	}

	private static function snapshot_base_directory() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			throw new RuntimeException( $uploads['error'] );
		}
		return wp_normalize_path( $uploads['basedir'] . '/theme-agent-blueprint/history' );
	}

	private static function protect_snapshot_directory( $directory ) {
		if ( ! file_exists( $directory . '/index.html' ) ) {
			self::write_backup( $directory . '/index.html', '' );
		}
		if ( ! file_exists( $directory . '/.htaccess' ) ) {
			self::write_backup( $directory . '/.htaccess', "Deny from all\n" );
		}
		if ( ! file_exists( $directory . '/web.config' ) ) {
			self::write_backup( $directory . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" );
		}
	}

	private static function write_backup( $path, $content ) {
		global $wp_filesystem;
		if ( ! $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE ) ) {
			throw new RuntimeException( __( 'A restore snapshot could not be written.', 'theme-agent-blueprint' ) );
		}
	}

	private static function delete_snapshot( $id ) {
		self::filesystem();
		global $wp_filesystem;
		$wp_filesystem->delete( self::snapshot_directory( $id ), true );
	}

	private static function filesystem() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		if ( ! $wp_filesystem && ! WP_Filesystem() ) {
			throw new RuntimeException( __( 'WordPress could not initialize filesystem access.', 'theme-agent-blueprint' ) );
		}
	}

	private static function authorize( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage template history.', 'theme-agent-blueprint' ) );
		}
		check_admin_referer( $action, 'tab_history_nonce' );
	}

	private static function posted_id() {
		$id = isset( $_POST['history_id'] ) ? sanitize_key( wp_unslash( $_POST['history_id'] ) ) : '';
		if ( ! $id ) {
			throw new RuntimeException( __( 'No template history record was selected.', 'theme-agent-blueprint' ) );
		}
		return $id;
	}

	private static function redirect( $type, $message ) {
		$key = wp_generate_password( 12, false, false );
		set_transient( 'tab_history_' . get_current_user_id() . '_' . $key, array( 'type' => $type, 'message' => sanitize_text_field( $message ) ), 300 );
		wp_safe_redirect( add_query_arg( array( 'page' => TAB_Theme_Agent_Blueprint_V111::SLUG, 'tab_view' => 'history', 'tab_history' => $key ), admin_url( 'admin.php' ) ) );
		exit;
	}
}