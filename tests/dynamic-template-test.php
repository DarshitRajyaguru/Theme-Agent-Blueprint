<?php

if ( ! class_exists( 'DOMDocument' ) ) {
	echo 'SKIP: PHP DOM extension is required for dynamic template tests.' . PHP_EOL;
	exit( 0 );
}

define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );

function __( $text, $domain = null ) {
	return $text;
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $key ) );
}

function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', $path );
}

function wp_strip_all_tags( $text ) {
	return strip_tags( $text );
}

function esc_url_raw( $url ) {
	return filter_var( $url, FILTER_SANITIZE_URL );
}

require dirname( __DIR__ ) . '/includes/class-tab-dynamic-template.php';

$html = '<header>Old header</header>
<section class="hero"><header><h1 data-tab-field="hero_title">Hero <span>title</span></h1></header><p>Hero description</p><ol class="steps"><li class="step current" data-step="0" aria-current="step"><span class="step-number">1</span><span class="step-title">Institution</span></li><li class="step" data-step="1"><span class="step-number">2</span><span class="step-title">Product</span></li><li class="step" data-step="2"><span class="step-number">3</span><span class="step-title">Numbers</span></li></ol><a href="/go">Go now</a><a href="/guide.pdf" data-tab-field="course_guide" data-tab-type="file">Download guide</a><button type="button" data-url="/start">Start</button></section>
<section class="testimonials">
<article class="testimonial-card"><img src="../images/person.jpg" alt="Alice"><blockquote>Great course</blockquote><h3>Alice</h3><p>Engineer</p></article>
<article class="testimonial-card"><img src="../images/person.jpg" alt="Bob"><blockquote>Loved it</blockquote><h3>Bob</h3><p>Manager</p></article>
</section><section class="calculator"><div class="choices"><button class="choice" data-institution="University"><img src="../images/person.jpg"><span class="choice-label">University</span></button><button class="choice" data-institution="Government"><img src="../images/person.jpg"><span class="choice-label">Government</span></button></div><div class="fields"><div class="field"><input id="volume"><span class="field-hint">Volume hint</span></div><div class="field"><input id="cost"><span class="field-hint">Cost hint</span></div></div><div class="selection-summary"><span class="summary-item"><button data-go="institution">Change</button></span><span class="summary-item"><button data-go="product">Change</button></span></div><div data-panel="contact"><h2>Contact title</h2><p>Contact description</p><button type="submit">Send</button></div><p>Result: <span data-result-product></span></p>
</section><section class="suite"><div class="row"><div class="col-lg-4"><article class="suite-step-card"><img src="../images/person.jpg"><h3 class="suite-step__name">One</h3><p class="suite-step__description">First</p></article></div><div class="col-lg-4"><article class="suite-step-card"><img src="../images/person.jpg"><h3 class="suite-step__name">Two</h3><p class="suite-step__description">Second</p></article></div></div><ul data-tab-repeater="resources"><li><span>Guide</span></li><li><span>Report</span></li></ul><div data-tab-ignore><h3>Runtime only</h3></div>
</section><footer>Old footer</footer>';

$result = TAB_Dynamic_Template::convert(
	$html,
	'demo',
	'pages/index.html',
	array( 'images/person.jpg' => array( 'relative' => 'assets/images/person.jpg', 'absolute' => 'unused' ) ),
	'template-parts/template-demo.php'
);
$generated = strtr( $result['html'], $result['tokens'] );
$static = TAB_Dynamic_Template::prepare_static( $html );
$failures = array();

if ( false !== stripos( $generated, 'Old header' ) || false !== stripos( $generated, 'Old footer' ) ) {
	$failures[] = 'Source header/footer content was retained.';
}
if ( false !== stripos( $static, 'Old header' ) || false !== stripos( $static, 'Old footer' ) || false !== strpos( $static, '<?php' ) ) {
	$failures[] = 'Static conversion did not preserve static content while removing source chrome.';
}
if ( false !== strpos( $static, 'data-tab-field' ) || false !== strpos( $static, 'data-tab-type' ) ) {
	$failures[] = 'Static conversion retained dynamic field authoring attributes.';
}
if ( false === strpos( $static, 'Hero <span>title</span>' ) || false === strpos( $static, '<header>' ) ) {
	$failures[] = 'Static conversion removed page content or a nested semantic header.';
}
if ( false === strpos( $generated, '<header>' ) ) {
	$failures[] = 'A semantic header inside page content was removed.';
}
if ( false === strpos( $generated, '$tab_field(' ) ) {
	$failures[] = 'Scalar custom-field output was not generated.';
}
if ( false === strpos( $generated, 'wp_kses_post( $tab_field( \'hero_title\'' ) || false === strpos( $generated, '<span>title</span>' ) ) {
	$failures[] = 'Nested inline markup was not preserved with allow-listed sanitization.';
}
if ( false !== strpos( $generated, 'esc_html( $tab_field( \'hero_title\'' ) ) {
	$failures[] = 'Structured content was escaped as plain text.';
}
if ( false === strpos( $generated, '<span class="step-number"><?php echo esc_html(' ) || false === strpos( $generated, '<span class="step-title"><?php echo esc_html(' ) ) {
	$failures[] = 'Leaf span fields did not preserve their parent step structure.';
}
if ( false !== strpos( $generated, '<li><?php echo' ) ) {
	$failures[] = 'A structured step parent was replaced instead of its leaf text.';
}
if ( false === strpos( $generated, 'as $tab_row_index => $tab_row' ) || false === strpos( $generated, 'esc_attr( $tab_row_index )' ) ) {
	$failures[] = 'Repeater rows did not retain row-specific indexes.';
}
if ( false === strpos( $generated, "array (\n  0 => 'step current'," ) || false === strpos( $generated, "array (\n  0 => 'step'," ) ) {
	$failures[] = 'Repeater rows did not retain row-specific class and data attribute values.';
}
if ( false === strpos( $generated, 'foreach ( $tab_rows(' ) ) {
	$failures[] = 'Repeater output was not generated.';
}
if ( false === strpos( $generated, "['target']" ) ) {
	$failures[] = 'Dynamic link targets were not generated.';
}
if ( false === strpos( $generated, '$tab_file(' ) ) {
	$failures[] = 'Dynamic file output was not generated.';
}
if ( false !== strpos( $generated, 'data-tab-field' ) || false !== strpos( $generated, 'data-tab-type' ) ) {
	$failures[] = 'Dynamic field authoring attributes were retained in output.';
}
if ( false === strpos( $generated, 'Great course' ) || false === strpos( $generated, 'Hero <span>title</span>' ) ) {
	$failures[] = 'Source fallback values were not retained.';
}

$repeaters = array_filter(
	$result['field_group']['fields'],
	static function ( $field ) {
		return 'repeater' === $field['type'];
	}
);
if ( empty( $repeaters ) ) {
	$failures[] = 'No repeater field was inferred.';
} else {
	$testimonial_repeaters = array_filter( $repeaters, static function ( $field ) {
		return in_array( 'image', array_column( $field['sub_fields'], 'type' ), true );
	} );
	$repeater = reset( $testimonial_repeaters );
	if ( ! $repeater ) {
		$failures[] = 'No testimonial repeater was inferred.';
		$repeater = reset( $repeaters );
	}
	$types = array_column( $repeater['sub_fields'], 'type' );
	foreach ( array( 'image', 'text', 'textarea' ) as $type ) {
		if ( ! in_array( $type, $types, true ) ) {
			$failures[] = 'Missing inferred repeater subfield type: ' . $type;
		}
	}
	foreach ( $repeater['sub_fields'] as $sub_field ) {
		if ( $repeater['key'] !== $sub_field['parent_repeater'] ) {
			$failures[] = 'Repeater subfield has an incorrect parent key.';
			break;
		}
	}
}

if ( false === json_encode( $result['field_group'] ) ) {
	$failures[] = 'Field group is not JSON encodable.';
}
$field_types = array_column( $result['field_group']['fields'], 'type', 'name' );
if ( ! isset( $field_types['hero_title'] ) || 'wysiwyg' !== $field_types['hero_title'] ) {
	$failures[] = 'An explicit heading field with inline markup was not mapped to WYSIWYG.';
}
if ( ! isset( $field_types['course_guide'] ) || 'file' !== $field_types['course_guide'] ) {
	$failures[] = 'An explicit file field was not generated.';
}
$step_repeaters = array_filter( $repeaters, static function ( $field ) { return false !== strpos( $field['name'], 'step' ); } );
if ( empty( $step_repeaters ) ) {
	$failures[] = 'Step items with state classes were not grouped into a repeater.';
} else {
	$step_repeater = reset( $step_repeaters );
	if ( 3 !== $step_repeater['min'] || 3 !== $step_repeater['max'] ) {
		$failures[] = 'Functional step repeaters were not locked to their source row count.';
	}
}
if ( ! in_array( 'url', $field_types, true ) || false === strpos( $generated, 'data-url="<?php echo esc_url(' ) ) {
	$failures[] = 'A URL-bearing button was not converted to a URL field.';
}
$choice_repeaters = array_filter( $repeaters, static function ( $field ) { return false !== strpos( $field['name'], 'institutions' ); } );
$choice_repeater = reset( $choice_repeaters );
if ( ! $choice_repeater || ! in_array( 'institution', array_column( $choice_repeater['sub_fields'], 'name' ), true ) || ! in_array( 'label', array_column( $choice_repeater['sub_fields'], 'name' ), true ) ) {
	$failures[] = 'Choice identity and semantic labels were not modeled as repeater subfields: ' . json_encode( array_map( static function ( $field ) { return array( $field['name'], array_column( $field['sub_fields'], 'name' ) ); }, $repeaters ) );
}
if ( $choice_repeater ) {
	$identity_fields = array_filter( $choice_repeater['sub_fields'], static function ( $field ) { return 'institution' === $field['name']; } );
	$identity_field = reset( $identity_fields );
	if ( ! $identity_field || 1 !== $identity_field['required'] ) {
		$failures[] = 'Functional repeater identity fields were not required.';
	}
}
if ( $choice_repeater && 'Institutions' !== $choice_repeater['label'] ) {
	$failures[] = 'Repeater labels expose implementation prefixes instead of concise content labels.';
}
if ( false !== strpos( $generated, 'foreach ( $tab_rows( \'demo_calculator_field' ) || false !== strpos( $generated, 'foreach ( $tab_rows( \'demo_calculator_summary' ) ) {
	$failures[] = 'Functional form or summary controls were incorrectly converted to repeaters.';
}
if ( false !== strpos( $generated, "get_field( 'demo_calculator_result" ) ) {
	$failures[] = 'Runtime result placeholders were incorrectly converted to fields.';
}
$suite_repeaters = array_filter( $repeaters, static function ( $field ) { return false !== strpos( $field['name'], 'suite_step_cards' ); } );
if ( empty( $suite_repeaters ) ) {
	$failures[] = 'Cards inside layout columns were not inferred as one repeater.';
}
if ( ! isset( $field_types['demo_calculator_contact_title'], $field_types['demo_calculator_contact_description'], $field_types['demo_calculator_contact_submit_label'] ) ) {
	$failures[] = 'Panel scalar fields were not given stable contextual names.';
}
$resource_repeaters = array_filter( $repeaters, static function ( $field ) { return false !== strpos( $field['name'], 'resources' ); } );
if ( empty( $resource_repeaters ) || false !== strpos( $generated, 'Runtime only</h3><?php' ) ) {
	$failures[] = 'Explicit repeater or ignore annotations were not honored.';
}

if ( $failures ) {
	fwrite( STDERR, "FAIL\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo 'PASS: chrome removed, scalar fields and repeater inferred, fallbacks retained, schema valid.' . PHP_EOL;