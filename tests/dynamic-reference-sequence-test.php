<?php
/**
 * Regression for HTML source-order, nested heading spans, and editor field types.
 * Run in an environment with ext-dom: php tests/dynamic-reference-sequence-test.php
 */
define( 'ABSPATH', sys_get_temp_dir() . '/tab-test-wordpress/' );
if ( ! class_exists( 'DOMDocument' ) ) {
    echo "SKIP: PHP DOM extension unavailable; dynamic conversion requires ext-dom.\n";
    exit( 0 );
}
function __( $text, $domain = null ) { return $text; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function esc_url_raw( $url ) { return $url; }
require dirname( __DIR__ ) . '/includes/class-tab-dynamic-template.php';
$html = '<div class="site-content"><section class="case-study-banner"><div class="small-title">Proof of Potential</div><h1>See what you save with <span>AI Labs 365</span></h1><div class="description">Most institutions overspend. Find out why.</div></section><section class="savings-section"><header class="estimate-heading"><h2>Your savings estimate</h2><p>A few questions, then your number.</p></header><ol class="steps"><li class="step" data-step="0"><span class="step-number">1</span><span class="step-title">Institution</span></li><li class="step" data-step="1"><span class="step-number">2</span><span class="step-title">Product</span></li></ol><div class="question"><h2>What kind of organization are you?</h2><p>This sets the context.</p></div><div data-panel="results"><h2>Results</h2><div data-result-savings>$0</div></div></section></div>';
$result = TAB_Dynamic_Template::convert( $html, 'cost-saving', 'index.html', array(), 'template-parts/template-cost-saving.php' );
$fields = $result['field_group']['fields'];
$banner = array();
foreach ( $fields as $field ) {
    if ( ! $field['name'] ) continue;
    if ( false !== strpos( $field['name'], '_case_study_banner_' ) ) $banner[] = $field;
}
$failures = array();
$expected = array( 'eyebrow', 'title', 'description' );
$actual = array_map( static function ( $f ) { $parts = explode( '_', $f['name'] ); return end( $parts ); }, $banner );
if ( $actual !== $expected ) $failures[] = 'Banner field sequence should be eyebrow, title, description. Actual: ' . json_encode( $actual );
if ( count( $banner ) !== 3 || 'wysiwyg' !== $banner[1]['type'] || 'all' !== $banner[1]['tabs'] ) {
    $failures[] = 'Nested-span heading must be one WYSIWYG field with Visual and Text tabs.';
}
if ( false === strpos( $banner[1]['default_value'], '<span>AI Labs 365</span>' ) || false !== strpos( $banner[1]['default_value'], 'data-tab-source-order' ) ) {
    $failures[] = 'Default rich heading should preserve span markup without internal order markers.';
}
if ( 'textarea' !== $banner[2]['type'] ) $failures[] = 'Plain description should be textarea.';
$generated = strtr( $result['html'], $result['tokens'] );
if ( false !== strpos( $generated, 'data-tab-source-order' ) ) $failures[] = 'Source-order markers leaked to template output.';
if ( false !== strpos( $generated, "get_field( 'cost_saving_savings_section_results_savings'" ) ) {
    $failures[] = 'JavaScript result placeholders were turned into editable fields.';
}
if ( $failures ) { fwrite( STDERR, "FAIL\n- " . implode( "\n- ", $failures ) . "\n" ); exit( 1 ); }
echo "PASS: HTML section order, rich headings, field types, and runtime selectors.\n";
