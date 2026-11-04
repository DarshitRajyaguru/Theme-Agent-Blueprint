<?php

$test_root = sys_get_temp_dir() . '/tab-importer-' . uniqid();
define( 'ABSPATH', $test_root . '/wordpress/' );

@mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
file_put_contents( ABSPATH . 'wp-admin/includes/file.php', '<?php' );

function __( $text, $domain = null ) { return $text; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_file_name( $value ) { return preg_replace( '/[^a-zA-Z0-9._-]/', '', (string) $value ); }
function esc_url_raw( $value ) { return $value; }
function esc_js( $value ) { return addslashes( $value ); }
function wp_generate_password( $length = 12, $special = false, $extra = false ) { return 'abc123def456'; }
function get_stylesheet() { return 'test-theme'; }
function get_theme_file_uri( $relative ) { return '/theme/' . ltrim( (string) $relative, '/' ); }

final class TAB_Theme_Agent_Blueprint_V111 {
	const OPTION = 'tab_blueprint_settings';
	const SLUG = 'theme-agent-blueprint';
}

require dirname( __DIR__ ) . '/includes/class-tab-template-importer.php';

$failures = array();
$class = new ReflectionClass( 'TAB_Template_Importer' );

$invoke = static function ( $method, array $args ) use ( $class ) {
	$reflection = $class->getMethod( $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $args );
};

$html = '<section id="hero" class="hero"><h1><span>Title</span></h1><p data-copy="desc">Desc</p></section>';
$usage = $invoke( 'collect_body_usage_context', array( $html ) );
$css = '.hero h1 { color: red; } .unused { color: blue; } @media (max-width: 900px) { .hero p { margin: 0; } .ghost { display: none; } }';
$filtered = $invoke( 'filter_css_for_usage', array( $css, $usage ) );
if ( false === strpos( $filtered, '.hero h1' ) || false === strpos( $filtered, '.hero p' ) ) {
	$failures[] = 'Used body selectors were removed from CSS filtering.';
}
if ( false !== strpos( $filtered, '.unused' ) || false !== strpos( $filtered, '.ghost' ) ) {
	$failures[] = 'Unused selectors were not removed from CSS filtering.';
}

$js = $invoke( 'build_js', array(
	array(
		"document.querySelector('.hero h1').textContent = 'X';",
		"document.querySelector('.unused').classList.add('on');",
		"window.__tabGeneric = true;",
	),
	'demo',
	$usage,
) );
if ( false === strpos( $js, ".hero h1" ) ) {
	$failures[] = 'Body-relevant JavaScript was removed unexpectedly.';
}
if ( false !== strpos( $js, ".unused" ) ) {
	$failures[] = 'Body-unrelated JavaScript selector was not filtered out.';
}

$asset_file = $test_root . '/shared.css';
@mkdir( dirname( $asset_file ), 0777, true );
file_put_contents( $asset_file, ".hero h1{color:red;}\n" );
$append_same = $invoke( 'append_asset', array( $asset_file, '.hero h1 { color: red; }', 'CSS', 'same' ) );
if ( ! is_array( $append_same ) || ! isset( $append_same['appended'] ) || true === $append_same['appended'] ) {
	$failures[] = 'Equivalent CSS payload was appended instead of being deduplicated.';
}
$append_new = $invoke( 'append_asset', array( $asset_file, '.hero p { color: #123; }', 'CSS', 'new' ) );
if ( empty( $append_new['appended'] ) || false === strpos( $append_new['content'], '/* START: template-new */' ) ) {
	$failures[] = 'New CSS payload was not wrapped and prepared for append.';
}

$theme_root = wp_normalize_path( $test_root . '/wp-content/themes/test-theme' );
$images_dir = $theme_root . '/assets/images';
@mkdir( $images_dir, 0777, true );
$existing_png = $images_dir . '/existing.png';
file_put_contents( $existing_png, 'png-content' );

$settings = array(
	'images_dir' => 'assets/images',
	'fonts_dir'  => 'assets/fonts',
);
$media = $invoke( 'prepare_media', array(
	array(
		'source/one.png' => 'png-content',
		'source/two.png' => 'png-content',
	),
	$settings,
	$theme_root,
) );
if ( ! empty( $media['writes'] ) ) {
	$failures[] = 'Duplicate media content should reuse existing files instead of scheduling writes.';
}
if ( empty( $media['map']['source/one.png']['relative'] ) || false === strpos( $media['map']['source/one.png']['relative'], 'existing.png' ) ) {
	$failures[] = 'Media map did not point duplicate content to an existing file.';
}

$delete = static function ( $path ) use ( &$delete ) {
	if ( ! file_exists( $path ) ) {
		return;
	}
	if ( is_dir( $path ) ) {
		foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $item ) {
			$delete( $path . '/' . $item );
		}
		rmdir( $path );
		return;
	}
	unlink( $path );
};
$delete( $test_root );

if ( $failures ) {
	fwrite( STDERR, "FAIL\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo 'PASS: importer CSS/JS filtering and dedupe behavior.' . PHP_EOL;
