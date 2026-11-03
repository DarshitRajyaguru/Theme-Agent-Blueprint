<?php

$test_root = sys_get_temp_dir() . '/tab-history-' . uniqid();
define( 'ABSPATH', $test_root . '/wordpress/' );
define( 'WP_CONTENT_DIR', $test_root . '/wordpress/wp-content' );
define( 'FS_CHMOD_FILE', 0644 );

@mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
file_put_contents( ABSPATH . 'wp-admin/includes/file.php', '<?php' );

$tab_test_options = array();

function __( $text, $domain = null ) { return $text; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' ); }
function sanitize_file_name( $value ) { return preg_replace( '/[^a-zA-Z0-9._-]/', '', $value ); }
function wp_generate_password() { return 'restore1'; }
function get_stylesheet() { return 'test-theme'; }
function current_time() { return '2026-10-30 12:00:00'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function wp_upload_dir() { return array( 'basedir' => WP_CONTENT_DIR . '/uploads', 'error' => false ); }
function get_option( $name, $default = false ) { global $tab_test_options; return array_key_exists( $name, $tab_test_options ) ? $tab_test_options[ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { global $tab_test_options; $tab_test_options[ $name ] = $value; return true; }
function WP_Filesystem() { global $wp_filesystem; $wp_filesystem = new TAB_Test_Filesystem(); return true; }

final class TAB_Test_Filesystem {
	public function put_contents( $path, $content, $mode = false ) { return false !== file_put_contents( $path, $content ); }
	public function get_contents( $path ) { return file_get_contents( $path ); }
	public function exists( $path ) { return file_exists( $path ); }
	public function is_dir( $path ) { return is_dir( $path ); }
	public function delete( $path, $recursive = false ) {
		if ( is_dir( $path ) && $recursive ) {
			$items = array_diff( scandir( $path ), array( '.', '..' ) );
			foreach ( $items as $item ) { $this->delete( $path . '/' . $item, true ); }
			return rmdir( $path );
		}
		return ! file_exists( $path ) || unlink( $path );
	}
}

require dirname( __DIR__ ) . '/includes/class-tab-template-history.php';

$theme_root = wp_normalize_path( WP_CONTENT_DIR . '/themes/test-theme' );
$css_path = $theme_root . '/assets/css/custom.css';
$asset_path = $theme_root . '/assets/images/imported.png';
$template_path = $theme_root . '/template-parts/template-demo.php';
$fields_path = $theme_root . '/acf-json/group_tab_demo.json';
wp_mkdir_p( dirname( $css_path ) );
wp_mkdir_p( dirname( $asset_path ) );
wp_mkdir_p( dirname( $template_path ) );
wp_mkdir_p( dirname( $fields_path ) );

$css_before = ".existing { color: black; }\n";
$css_block = "/* START: template-demo */\n.demo { color: red; }\n/* END: template-demo */";
$writes = array(
	$css_path      => $css_before . "\n" . $css_block . "\n",
	$asset_path    => 'image-bytes',
	$template_path => '<?php /* Template Name: Demo */',
	$fields_path   => '{"key":"group_demo"}',
);
file_put_contents( $css_path, $css_before );

$record = TAB_Template_History::prepare_import(
	'Demo',
	'demo',
	$theme_root,
	$writes,
	array( $css_path => 'CSS' ),
	array( $asset_path )
);
foreach ( $writes as $path => $content ) { file_put_contents( $path, $content ); }
TAB_Template_History::finalize_import( $record );

$history = get_option( TAB_Template_History::OPTION );
$remove = new ReflectionMethod( 'TAB_Template_History', 'remove_record' );
$remove->setAccessible( true );
$remove->invokeArgs( null, array( &$history, 0 ) );

$failures = array();
if ( file_exists( $template_path ) || file_exists( $fields_path ) || file_exists( $asset_path ) ) { $failures[] = 'Generated files were not removed.'; }
if ( trim( file_get_contents( $css_path ) ) !== trim( $css_before ) ) { $failures[] = 'The generated CSS block was not removed cleanly.'; }
if ( 'removed' !== $history[0]['status'] ) { $failures[] = 'History status was not changed to removed.'; }

$restore = new ReflectionMethod( 'TAB_Template_History', 'restore_record' );
$restore->setAccessible( true );
$restore->invokeArgs( null, array( &$history, 0 ) );

foreach ( $writes as $path => $content ) {
	if ( ! file_exists( $path ) ) { $failures[] = 'A tracked file was not restored: ' . basename( $path ); continue; }
}
if ( false === strpos( file_get_contents( $css_path ), $css_block ) ) { $failures[] = 'The CSS block was not restored.'; }
if ( 'active' !== $history[0]['status'] ) { $failures[] = 'History status was not restored to active.'; }

file_put_contents( $css_path, str_replace( 'color: red', 'color: blue', file_get_contents( $css_path ) ) );
file_put_contents( $template_path, '<?php // manually changed' );
$remove->invokeArgs( null, array( &$history, 0 ) );
if ( file_exists( $template_path ) ) { $failures[] = 'Revoke did not remove a modified generated template.'; }
if ( false !== strpos( file_get_contents( $css_path ), 'template-demo' ) ) { $failures[] = 'Revoke did not remove a modified tracked CSS block.'; }

( new TAB_Test_Filesystem() )->delete( $test_root, true );

if ( $failures ) {
	fwrite( STDERR, "FAIL\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo 'PASS: history snapshot, revoke modified files, restore, and tracked block cleanup.' . PHP_EOL;