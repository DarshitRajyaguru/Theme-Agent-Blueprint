<?php
/**
 * Fixture-based checks for strictly scoped assets and reuse of existing theme files.
 * Run: php tests/importer-reference-scope-test.php
 */
$test_root = sys_get_temp_dir() . '/tab-scope-' . uniqid( '', true );
define( 'ABSPATH', $test_root . '/wordpress/' );
function __( $text, $domain = null ) { return $text; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' ); }
function sanitize_file_name( $value ) { return preg_replace( '/[^a-zA-Z0-9._-]/', '', (string) $value ); }
function esc_url_raw( $value ) { return $value; }
function esc_js( $value ) { return addslashes( $value ); }
function get_stylesheet() { return 'test-theme'; }
function get_theme_file_uri( $path ) { return '/themes/test-theme/' . $path; }
require dirname( __DIR__ ) . '/includes/class-tab-template-importer.php';
$class = new ReflectionClass( 'TAB_Template_Importer' );
$call = static function ( $method, array $args ) use ( $class ) {
    return $class->getMethod( $method )->invokeArgs( null, $args );
};
$failures = array();
$html_path = 'pages/calculator.html';
$body = '<main class="site-content"><section class="case-study-banner"><h1>Save with <span class="brand-color">AI</span></h1><img src="../images/used.svg"></section><section class="savings"><div class="estimate-panel" data-panel="results"><h2>Results</h2></div></section></main>';
$styles = array( array( 'path' => 'css/style.css', 'content' => 'html, body { margin:0; } .site_header {height:90px;} .case-study-banner h1, .not-on-page {color:red;} .savings .estimate-panel {background-image:url("../images/bg.png");} @media (max-width:720px) {.case-study-banner {padding:1rem} .site_footer{padding:3rem}}' ) );
$scripts = array( "(() => { document.querySelector('.savings').querySelector('[data-panel]').hidden = false; })();", "document.querySelector('.site_header').classList.add('sticky');" );
$usage = $call( 'collect_body_usage_context', array( $body ) );
$css = $call( 'build_css', array( $styles, array(), $usage, 'cost-saving' ) );
foreach ( array( '#tab-template-cost-saving .case-study-banner h1', '#tab-template-cost-saving .savings .estimate-panel', '@media' ) as $required ) {
    if ( false === strpos( $css, $required ) ) $failures[] = 'Missing relevant/scoped CSS: ' . $required;
}
foreach ( array( '.site_header', '.site_footer', '.not-on-page', 'html, body' ) as $unwanted ) {
    if ( false !== strpos( $css, $unwanted ) ) $failures[] = 'Unrelated CSS imported: ' . $unwanted;
}
$entries = array( 'images/used.svg' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'images/bg.png' => 'used-bg', 'images/never-used.png' => 'unused-image' );
$needed = $call( 'referenced_media', array( $body, $html_path, $styles, $usage, $scripts, $entries ) );
if ( ! isset( $needed['images/used.svg'], $needed['images/bg.png'] ) || isset( $needed['images/never-used.png'] ) ) {
    $failures[] = 'Used media was not selected correctly or an unused file was included.';
}
$theme_root = $test_root . '/wp-content/themes/test-theme';
@mkdir( $theme_root . '/assets/images', 0777, true );
file_put_contents( $theme_root . '/assets/images/existing-bg.png', 'used-bg' );
$media = $call( 'prepare_media', array( $entries, array( 'images_dir' => 'assets/images/', 'fonts_dir' => 'assets/fonts/' ), $theme_root, $needed ) );
@mkdir( $theme_root . '/assets/legacy', 0777, true );
file_put_contents( $theme_root . '/assets/legacy/old-asset.png', 'legacy-image' );
$legacy = $call( 'prepare_media', array( array( 'images/from-zip.png' => 'legacy-image' ), array( 'images_dir' => 'assets/images/', 'fonts_dir' => 'assets/fonts/' ), $theme_root, array( 'images/from-zip.png' => true ) ) );
if ( ! empty( $legacy['writes'] ) || 'assets/legacy/old-asset.png' !== $legacy['map']['images/from-zip.png']['relative'] ) {
    $failures[] = 'Identical media in a different active-theme directory was duplicated.';
}

if ( isset( $media['writes'][ $theme_root . '/assets/images/existing-bg.png' ] ) || isset( $media['map']['images/never-used.png'] ) ) {
    $failures[] = 'Existing media was duplicated or an unused media item was scheduled.';
}
if ( empty( $media['map']['images/bg.png']['relative'] ) || 'assets/images/existing-bg.png' !== $media['map']['images/bg.png']['relative'] ) {
    $failures[] = 'Existing image hash was not reused.';
}
$skipped = 0;
$js_method = $class->getMethod( 'build_js' );
$args = array( $scripts, 'cost-saving', $usage, &$skipped );
$js = $js_method->invokeArgs( null, $args );
if ( false === strpos( $js, "querySelector('.savings')" ) || false !== strpos( $js, '.site_header' ) || 1 !== $skipped ) {
    $failures[] = 'Page-scoped JS selection or excluded-script warning count is incorrect.';
}
$cleanup = static function ( $dir ) use ( &$cleanup ) {
    if ( ! file_exists( $dir ) ) return;
    if ( is_dir( $dir ) ) {
        foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $name ) $cleanup( $dir . '/' . $name );
        rmdir( $dir );
    } else unlink( $dir );
};
$cleanup( $test_root );
if ( $failures ) { fwrite( STDERR, "FAIL\n- " . implode( "\n- ", $failures ) . "\n" ); exit( 1 ); }
echo "PASS: body-scoped CSS/JS, referenced-only media, and existing-file reuse.\n";
