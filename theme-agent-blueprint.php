<?php
/**
 * Plugin Name: Theme Agent Blueprint
 * Description: Creates theme-aware development playbooks and imports HTML designs as dynamic SCF/ACF WordPress templates.
 * Version: 1.6.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Darshit Rajyaguru
 * Author URI: https://profiles.wordpress.org/darshitrajyaguru97/
 * License: GPL-2.0-or-later
 * Text Domain: theme-agent-blueprint
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-tab-dynamic-template.php';
require_once __DIR__ . '/includes/class-tab-template-history.php';
require_once __DIR__ . '/includes/class-tab-template-importer.php';

final class TAB_Theme_Agent_Blueprint_V111 {
	const OPTION = 'tab_blueprint_settings';
	const SLUG   = 'theme-agent-blueprint';
	const VERSION = '1.6.1';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_tab_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_tab_download_blueprint', array( __CLASS__, 'download_blueprint' ) );
		add_action( 'admin_post_tab_import_template', array( 'TAB_Template_Importer', 'handle' ) );
		add_action( 'admin_post_tab_remove_template', array( 'TAB_Template_History', 'handle_remove' ) );
		add_action( 'admin_post_tab_restore_template', array( 'TAB_Template_History', 'handle_restore' ) );
		add_action( 'wp_ajax_tab_load_history', array( __CLASS__, 'ajax_load_history' ) );
	}

	private static function defaults() {
		$theme = wp_get_theme();
		return array(
			'site_name'       => get_bloginfo( 'name' ),
			'site_url'        => home_url( '/' ),
			'theme_name'      => $theme->get( 'Name' ),
			'theme_slug'      => get_stylesheet(),
			'theme_root'      => 'wp-content/themes/' . get_stylesheet(),
			'css_files'       => '',
			'js_files'        => '',
			'images_dir'      => 'assets/images/',
			'fonts_dir'       => 'assets/fonts/',
			'route_files'     => '',
			'sections_dir'    => 'template-parts/',
			'fields_dir'      => 'acf-json/',
			'field_plugin'    => 'SCF / ACF-compatible field APIs',
			'colors'          => '',
			'fonts'           => '',
			'frameworks'      => 'Reuse the active theme’s existing framework and component conventions. Do not add a new framework.',
			'enqueue_file'    => '',
			'notes'           => '',
			'field_schema'    => '',
			'dependencies'    => '',
			'acceptance'      => '',
		);
	}

	private static function get_settings() {
		return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function admin_menu() {
		add_menu_page(
			__( 'Theme Agent Blueprint', 'theme-agent-blueprint' ),
			__( 'Theme Agent', 'theme-agent-blueprint' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-media-code',
			58
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'tab-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.css', array(), self::VERSION );
		wp_enqueue_script( 'tab-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.js', array(), self::VERSION, true );
		wp_localize_script( 'tab-admin', 'TABAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'tab_load_history' ),
		) );
	}

	public static function ajax_load_history() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view template history.', 'theme-agent-blueprint' ) ), 403 );
		}
		check_ajax_referer( 'tab_load_history', 'nonce' );
		ob_start();
		TAB_Template_History::render_page();
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	private static function field( $key, $label, $description = '', $type = 'text', $settings = null ) {
		$settings = is_array( $settings ) ? $settings : self::get_settings();
		$value = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
		echo '<div class="tab-field">';
		echo '<label for="tab-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		if ( 'textarea' === $type ) {
			echo '<textarea rows="3" id="tab-' . esc_attr( $key ) . '" name="settings[' . esc_attr( $key ) . ']">' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input type="' . esc_attr( $type ) . '" id="tab-' . esc_attr( $key ) . '" name="settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
		}
		if ( $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</div>';
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'theme-agent-blueprint' ) );
		}
		$s = self::get_settings();
		$theme = wp_get_theme();
		$view = isset( $_GET['tab_view'] ) ? sanitize_key( wp_unslash( $_GET['tab_view'] ) ) : 'project';
		$view = 'history' === $view ? 'history' : 'project';
		?>
		<div class="wrap tab-wrap">
			<?php
			TAB_Template_Importer::render_notice();
			TAB_Template_History::render_notice();
			if ( isset( $_GET['tab_saved'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['tab_saved'] ) ) ) {
				add_settings_error( self::SLUG, 'settings_saved', __( 'Blueprint settings saved.', 'theme-agent-blueprint' ), 'success' );
			}
			settings_errors( self::SLUG );
			?>
			<header class="tab-hero">
				<div class="tab-brand-icon"><span class="dashicons dashicons-media-code"></span></div>
				<div><p class="tab-eyebrow">WORDPRESS DEVELOPMENT TOOLKIT</p>
					<h1>Theme Agent Blueprint</h1>
					<p>Build a reliable <code>theme-name-agent.md</code> playbook that follows this site’s active theme, file conventions, brand system, and custom-field workflow.</p>
				</div>
				<div class="tab-active-theme"><span>ACTIVE THEME</span><strong><?php echo esc_html( $theme->get( 'Name' ) ); ?></strong><code><?php echo esc_html( get_stylesheet() ); ?></code></div>
			</header>
			<nav class="nav-tab-wrapper tab-nav" aria-label="<?php esc_attr_e( 'Theme Agent sections', 'theme-agent-blueprint' ); ?>">
				<a href="<?php echo esc_url( add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo 'project' === $view ? 'nav-tab-active' : ''; ?>" data-tab-view="project"><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span><?php esc_html_e( 'Project', 'theme-agent-blueprint' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab_view' => 'history' ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo 'history' === $view ? 'nav-tab-active' : ''; ?>" data-tab-view="history"><span class="dashicons dashicons-backup" aria-hidden="true"></span><?php esc_html_e( 'History', 'theme-agent-blueprint' ); ?></a>
			</nav>
			<div id="tab-view-content" data-current-view="<?php echo esc_attr( $view ); ?>" aria-live="polite">
			<?php if ( 'history' === $view ) : ?>
				<?php TAB_Template_History::render_page(); ?>
			</div>
				<footer class="tab-footer">Theme Agent Blueprint <span>v<?php echo esc_html( self::VERSION ); ?></span> <span class="tab-footer-dot">•</span> Designed to be theme-aware, not theme-invasive.</footer>
			</div>
				<?php return; ?>
			<?php endif; ?>
			<div class="tab-layout">
				<form class="tab-card tab-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="tab_save_settings">
					<?php wp_nonce_field( 'tab_save_settings', 'tab_nonce' ); ?>
					<div class="tab-card-heading"><div><span class="tab-step">01</span><h2>Project profile</h2></div><p>Start with the site and theme identity.</p></div>
					<div class="tab-grid">
						<?php self::field( 'site_name', 'Site name' ); ?>
						<?php self::field( 'site_url', 'Site URL', 'Use the canonical public URL.', 'url' ); ?>
						<?php self::field( 'theme_name', 'Active theme name' ); ?>
						<?php self::field( 'theme_slug', 'Theme directory / slug' ); ?>
						
						<?php self::field( 'enqueue_file', 'Asset registration PHP file (optional)', 'Leave blank to discover the active theme’s existing enqueue/registration file; otherwise use functions.php.' ); ?>
					</div>

					<div class="tab-card-heading tab-section-heading"><div><span class="tab-step">02</span><h2>Assets &amp; structure</h2></div><p>Use paths that already exist in this project.</p></div>
					<div class="tab-grid">
						<?php self::field( 'css_files', 'Shared CSS destination', 'Theme-relative existing file, for example assets/css/custom.css. Imported CSS is appended here between template markers.', 'textarea' ); ?>
						<?php self::field( 'js_files', 'Shared JavaScript destination', 'Theme-relative existing file, for example assets/js/custom.js. Imported JavaScript is appended here between template markers.', 'textarea' ); ?>
						<?php self::field( 'images_dir', 'Shared images directory', 'Theme-relative directory where ZIP images will be copied.' ); ?>
						<?php self::field( 'fonts_dir', 'Shared fonts directory', 'Theme-relative directory where ZIP fonts will be copied.' ); ?>
						<?php self::field( 'sections_dir', 'Template destination directory', 'Theme-relative directory for the generated WordPress page template.' ); ?>
						<?php self::field( 'fields_dir', 'SCF / ACF JSON directory', 'Theme-relative directory where the generated dynamic field group will be saved.' ); ?>
					</div>

					<div class="tab-card-heading tab-section-heading"><div><span class="tab-step">03</span><h2>Brand system</h2></div><p>Document design tokens without overriding the active theme.</p></div>
					<div class="tab-grid">
						<?php self::field( 'colors', 'Brand colors', 'Optional override only. Blank means inherit actual active theme colors and CSS variables.', 'textarea' ); ?>
						<?php self::field( 'fonts', 'Typography / font rules', 'Optional override only. Blank means inherit active theme font families, sizes and weights.', 'textarea' ); ?>
						<?php self::field( 'frameworks', 'Framework and component conventions', '', 'textarea' ); ?>
						<?php self::field( 'notes', 'Project-specific rules / exclusions', 'Mention special routing, CPTs, naming conventions, plugins, or constraints.', 'textarea' ); ?>
                        <?php self::field( 'field_schema', 'Dynamic fields and content mapping', 'List field names, field types, relationships, repeaters, fallback values, and output rules. Leave blank for project discovery.', 'textarea' ); ?>
                        <?php self::field( 'dependencies', 'Required plugins and dependencies', 'Identify plugin integrations, JavaScript libraries, API dependencies and graceful fallback behavior.', 'textarea' ); ?>
                        <?php self::field( 'acceptance', 'Acceptance criteria and testing', 'Define required breakpoints, accessibility, performance, functionality and browser checks.', 'textarea' ); ?>
					</div>
					<div class="tab-actions">
						<button type="submit" class="button button-primary button-hero"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><span>Save project profile</span></button>
						<span>Settings are stored in this WordPress site only.</span>
					</div>
				</form>

				<aside class="tab-side">
					<div class="tab-card tab-import-card">
						<span class="tab-side-icon dashicons dashicons-upload"></span>
						<h2>Import HTML template</h2>
						<p>Upload a ZIP containing HTML, CSS, JavaScript, images, and fonts. Choose static template content or editable SCF/ACF fields. Source header/footer markup is always excluded.</p>
						<form class="tab-import-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="tab_import_template">
							<?php wp_nonce_field( 'tab_import_template', 'tab_import_nonce' ); ?>
							<label class="tab-download-label" for="tab-template-name">WordPress template name</label>
							<input id="tab-template-name" name="template_name" type="text" required placeholder="e.g. Product landing">
							<label class="tab-download-label" for="tab-html-file">HTML filename <span>(optional)</span></label>
							<input id="tab-html-file" name="html_file" type="text" placeholder="e.g. pages/index.html">
							<p class="description">Required only when the ZIP contains more than one HTML file.</p>
							<label class="tab-download-label" for="tab-content-mode">Content management</label>
							<select id="tab-content-mode" name="content_mode">
								<option value="static">Static HTML content</option>
								<option value="dynamic">Dynamic SCF / ACF fields</option>
							</select>
							<p class="description">Dynamic mode creates template-assigned field JSON. Static mode keeps content in the PHP template.</p>
							<label class="tab-download-label" for="tab-template-zip">Template package</label>
							<input id="tab-template-zip" name="template_zip" type="file" required accept=".zip,application/zip">
							<button type="submit" class="button button-primary button-hero tab-import-button"><span class="dashicons dashicons-migrate"></span><span>Import template</span></button>
						</form>
						<p class="tab-import-note"><strong>25 MB maximum.</strong> Dynamic mode requires SCF or ACF. Existing templates are never overwritten, and every import can be revoked from History.</p>
					</div>
					<div class="tab-card tab-quick-card">
						<span class="tab-side-icon dashicons dashicons-download"></span>
						<h2>Generate your agent file</h2>
						<p>Create a project-specific Markdown playbook with route-aware instructions for pages, posts, single templates, and archives.</p>
						<form class="tab-download-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="tab_download_blueprint">
							<?php wp_nonce_field( 'tab_download_blueprint', 'tab_download_nonce' ); ?>
							<label class="tab-download-label" for="tab-template-type">Template work type</label>
							<select id="tab-template-type" name="template_type">
								
								<option value="page">Page template</option>
								<option value="post">Post template</option>
								<option value="single">Single CPT / single content</option>
								<option value="archive">Archive / listing page</option>
							</select>
							<label class="tab-download-label" for="tab-content-type">Template slug / post type (optional)</label>
							<input id="tab-content-type" name="content_type" type="text" placeholder="e.g. landing or product">
							<button type="submit" class="button button-primary button-hero tab-download-button"><span class="dashicons dashicons-media-text"></span> Generate Markdown</button>
						</form>
						<div class="tab-file-preview"><span class="dashicons dashicons-media-document"></span><div><strong>theme-name-agent.md</strong><small>Portable project context for your AI coding agent</small></div></div>
					</div>
					<div class="tab-card tab-check-card">
						<h3><span class="dashicons dashicons-shield-alt"></span> Safe by default</h3>
						<ul>
							<li>Admin-only settings and exports</li>
							<li>Nonce-protected form actions</li>
							<li>Sanitized inputs and escaped output</li>
							<li>Guarded imports with transaction rollback and reversible History revoke</li>
							<li>Template-type-specific workflow and validation checklist</li>
						</ul>
					</div>
					<div class="tab-card tab-check-card"><h3><span class="dashicons dashicons-admin-tools"></span> Site diagnostics</h3>
                        <ul>
                            <li>WordPress: <?php echo esc_html( get_bloginfo( 'version' ) ); ?></li>
                            <li>PHP: <?php echo esc_html( PHP_VERSION ); ?></li>
                            <li>Active theme: <?php echo esc_html( get_stylesheet() ); ?></li>
                            <li>Child theme: <?php echo is_child_theme() ? 'Yes' : 'No'; ?></li>
                            <li>ACF: <?php echo function_exists( 'acf_get_field_groups' ) ? 'Available' : 'Not detected'; ?></li>
                            <li>WooCommerce: <?php echo class_exists( 'WooCommerce' ) ? 'Available' : 'Not detected'; ?></li>
                        </ul>
                    </div>
                    <div class="tab-card tab-tip-card"><h3>Best results</h3><p>Before generating, inspect the active theme’s closest existing template, its field JSON, CSS/JS registration, and media library. Fill in paths that actually exist instead of assuming a standard folder layout.</p></div>
				</aside>
			</div>
			</div>
			<footer class="tab-footer">Theme Agent Blueprint <span>v<?php echo esc_html( self::VERSION ); ?></span> <span class="tab-footer-dot">•</span> Designed to be theme-aware, not theme-invasive.</footer>
		</div>
		<?php
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'theme-agent-blueprint' ) );
		}
		check_admin_referer( 'tab_save_settings', 'tab_nonce' );
		$input = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$defaults = self::defaults();
		$clean = array();
		$textarea_keys = array( 'css_files', 'js_files', 'route_files', 'colors', 'fonts', 'frameworks', 'notes', 'field_schema', 'dependencies', 'acceptance' );
		foreach ( $defaults as $key => $default ) {
			$value = isset( $input[ $key ] ) ? $input[ $key ] : $default;
			if ( 'site_url' === $key ) {
				$value = esc_url_raw( trim( $value ) );
			} elseif ( in_array( $key, $textarea_keys, true ) || in_array( $key, array( 'images_dir', 'fonts_dir', 'sections_dir', 'fields_dir', 'theme_root', 'enqueue_file', 'field_plugin' ), true ) ) {
				$value = sanitize_textarea_field( $value );
			} else {
				$value = sanitize_text_field( $value );
			}
			$clean[ $key ] = $value;
		}
		update_option( self::OPTION, $clean, false );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab_saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function safe_lines( $value ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
		$lines = array_map( 'trim', $lines );
		return implode( "\n", array_filter( $lines, 'strlen' ) );
	}

	private static function build_markdown( $settings, $type, $content_type ) {
		$site = $settings['site_name'] ? $settings['site_name'] : 'WordPress site';
		$target = $content_type ? $content_type : '[provide slug or post type]';
		$routes = array(
			'page' => "Create a NEW named page template at template-parts/page-{$target}.php (use a real unique slug). Add a WordPress Template Name header so it can be selected in the Page editor. Never edit page.php. If the active theme does not discover templates from template-parts automatically, register the new template through the existing theme template registration hooks (theme_page_templates and template_include), without editing page.php. Keep reusable sections in separate uniquely named parts where appropriate.",
			'post' => "Create a NEW post-specific template at the theme root: single-post-{$target}.php. WordPress does not automatically select single-post-{slug}.php: implement a narrowly scoped template_include selection for the intended post slug, or use a supported custom post template registration and assignment. Never edit single.php or single-post.php. Preserve post metadata, SEO, content and comments behavior.",
			'single' => "Create a NEW root-level single-{$target}.php for a registered custom post type. Never replace single.php or another existing file. If that filename already exists, create a uniquely named template and use a scoped template_include mapping. For a particular item rather than a CPT, use a unique template with an explicit, scoped selection rule.",
			'archive' => "Create a NEW root-level archive-{$target}.php for a registered post type archive. Never edit archive.php. For category, tag, taxonomy, author or date archives, use the appropriate WordPress hierarchy and a unique non-conflicting filename plus scoped selection logic where necessary. Preserve main query, pagination, filters and empty states.",
			'all' => "For page work create template-parts/page-{slug}.php and register it as a selectable page template. For post-specific work create root-level single-post-{slug}.php with scoped selection logic (not an automatic WordPress hierarchy filename). For CPT singles create root-level single-{post_type}.php. For CPT archives create root-level archive-{post_type}.php. For other archives follow their own hierarchy. NEVER overwrite existing templates, including page.php, single.php, single-post.php or archive.php. If a proposed filename exists, choose a unique new filename and register a narrowly scoped selection rule.",
		);
		$type_label = array( 'all' => 'All template types', 'page' => 'Page template', 'post' => 'Post template', 'single' => 'Single CPT / single content', 'archive' => 'Archive / listing page' );
		$markdown = "# {$site} Theme Agent Playbook\n\n";
		$markdown .= "> Generated by Theme Agent Blueprint. Treat the active theme and existing code as the source of truth. Verify project conventions before editing.\n\n";
		$markdown .= "## Project profile\n\n";
		$markdown .= "- Site: {$site}\n- URL: {$settings['site_url']}\n- Active theme: {$settings['theme_name']}\n- Theme slug: `{$settings['theme_slug']}`\n- Theme root: `{$settings['theme_root']}`\n- Work type: " . $type_label[ $type ] . "\n- Slug / post type: {$target}\n\n";
		$markdown .= "## Asset and directory conventions\n\nIf a configured path is blank or missing, inspect the installed theme and discover its real path; never assume a default path exists. Asset registration is a PHP file containing wp_enqueue_style/wp_enqueue_script calls or a hooked enqueue function. Do not create a duplicate registration file when one already exists.\n\n";
		$markdown .= "- CSS files (preferred existing targets):\n" . self::code_lines( $settings['css_files'] ) . "\n";
		$markdown .= "- JavaScript files (preferred existing targets):\n" . self::code_lines( $settings['js_files'] ) . "\n";
		$markdown .= "- Images directory: `{$settings['images_dir']}`\n- Fonts directory: `{$settings['fonts_dir']}`\n- Route/template files:\n" . self::code_lines( $settings['route_files'] ) . "\n";
		$markdown .= "- Template parts / sections directory: `{$settings['sections_dir']}`\n- SCF/ACF JSON directory: `{$settings['fields_dir']}`\n- Custom-field system: {$settings['field_plugin']}\n- Asset registration location: `{$settings['enqueue_file']}`\n\n";
		$markdown .= "## Brand guidelines (mandatory inheritance)\n\nWhen overrides are blank, inspect the installed active theme, theme.json, style.css, custom properties, Customizer/global styles, enqueued CSS and font declarations. Inherit the actual project brand colors, font-family, font weights, typography and component tokens. Never substitute generic colors or fonts. A Markdown export cannot inspect an arbitrary future theme: the coding agent MUST verify the real project before implementing. Child-theme overrides take precedence where applicable.\n\n- Colors:\n" . ( $settings['colors'] ? self::safe_lines( $settings['colors'] ) : "  - Inspect existing CSS custom properties, theme styles, design files, and components. Do not invent brand colors." ) . "\n- Typography:\n" . ( $settings['fonts'] ? self::safe_lines( $settings['fonts'] ) : "  - Inspect existing font-face declarations, theme typography, and loaded fonts. Reuse current families and weights." ) . "\n- Framework/components: {$settings['frameworks']}\n\n";
		$markdown .= "## Routing guidance — new files only\n\n" . $routes[ $type ] . "\n\n";
		$markdown .= "## Required implementation workflow\n\n";
		$markdown .= "1. Inspect the active theme and the nearest existing page/template/section, field group JSON, enqueue logic, and similar components before coding.\n";
		$markdown .= "2. Confirm the route, template hierarchy, content source, field names, repeaters, image return formats, and editable-versus-fixed content before markup changes.\n";
		$markdown .= "3. Reuse existing layout utilities, Bootstrap/components, sliders, fonts, naming, and enqueue conventions. Do not introduce a framework or duplicate shared assets.\n";
		$markdown .= "4. Use the configured directories above only after verifying that paths exist. Search for reusable media before adding files. Use unique filenames for genuinely new assets.\n";
		$markdown .= "5. Keep route files small. Put substantial markup in the project’s existing template-parts/section structure. Do not put large markup in functions.php or add inline CSS/JS.\n";
		$markdown .= "6. Use the project's SCF/ACF-compatible APIs and preserve existing field names. Save field-group JSON in the configured JSON folder and keep field names synchronized. Do not assume SCF is active without checking.\n";
		$markdown .= "7. Guard optional fields and empty repeaters. Use sensible fallbacks only when required by the design. Avoid notices and malformed empty wrappers.\n";
		$markdown .= "8. Escape plain text with `esc_html()`, attributes with `esc_attr()`, URLs with `esc_url()`, and allow-listed rich text with `wp_kses()`. Prefer `wp_get_attachment_image()` for attachment IDs.\n";
		$markdown .= "9. For custom queries, set `post_status` explicitly, use `no_found_rows` when pagination is unnecessary, set `ignore_sticky_posts` where appropriate, and call `wp_reset_postdata()` after loops.\n";
		$markdown .= "10. Prefer separate page-scoped assets when consistent with the theme. If extending shared CSS/JS, wrap EVERY addition in explicit matching markers: `/* START: {template-file-name} */` and `/* END: {template-file-name} */` for CSS, and `// START: {template-file-name}` and `// END: {template-file-name}` for JS. Use the exact generated PHP template basename (without .php) in BOTH markers. Scope selectors and JS initialization to that template; never affect other pages.\n";
		$markdown .= "11. Register/enqueue all new CSS and JS using wp_enqueue_scripts in the theme’s EXISTING asset-registration PHP file, if present; otherwise add a small, uniquely named enqueue callback to functions.php without changing existing callbacks. Use get_theme_file_uri()/get_theme_file_path(), proper dependencies, versioning, and conditional is_page_template()/is_singular()/is_post_type_archive() as appropriate. Use images via theme asset URLs or WordPress attachment APIs; images are not enqueued like CSS/JS. Do not enqueue assets globally unless shared across the site.\n12. Preserve existing functionality, accessibility, semantic HTML, responsive behavior, security controls, SEO integrations, and plugin compatibility. Do not overwrite unrelated code or remove comments without reason.\n\n";
		$markdown .= "## Template-type requirements\n\n- **Never overwrite:** page.php, single.php, single-post.php, archive.php, index.php, or any existing custom template. Create a new unique file for every requested template.\n- **Selection:** A file in template-parts/ is not automatically a selectable page template in every theme; register/discover it correctly and verify selection in the editor. WordPress does not auto-select single-post-{slug}.php by slug; use explicit scoped routing.\n";
		$markdown .= "- **Page:** verify page.php / custom page template and whether flexible sections are the established pattern.\n- **Post:** preserve native post content and existing metadata behavior; do not hardcode post-specific content.\n- **Single CPT:** confirm post type registration, singular template hierarchy, and field group location rules.\n- **Archive:** preserve query behavior, pagination, filters, taxonomy context, empty states, and query globals.\n\n";
		$markdown .= "## Definition of done\n\n- Correct URL resolves through the intended WordPress route.\n- Existing theme conventions and brand system are followed.\n- Field JSON is valid and imports without duplicate/conflicting names.\n- Empty optional fields and zero repeater/query results render safely.\n- Output is escaped, custom query globals are restored, and links/images are valid.\n- CSS/JS is scoped and does not affect unrelated routes.\n- Desktop and mobile layouts match the supplied reference without horizontal overflow.\n- PHP lint passes for every changed PHP file; JSON parses; no obvious whitespace/merge errors.\n- Test with fields populated and empty; report files changed, validation performed, and assumptions.\n\n";
		if ( $settings['notes'] ) {
			$markdown .= "## Project-specific notes\n\n" . self::safe_lines( $settings['notes'] ) . "\n\n";
		}
		$markdown .= "## Short handoff format\n\n```text\nChanged: [files]\nSCF: [field group/layout and import status]\nAssets: [CSS/JS/media and loading condition]\nValidated: [commands and desktop/mobile checks]\nAssumptions: [only if needed]\n```\n";
		return $markdown;
	}

	private static function code_lines( $value ) {
		$lines = self::safe_lines( $value );
		if ( ! $lines ) {
			return "- Inspect the active theme and choose the existing project convention.\n";
		}
		$out = '';
		foreach ( explode( "\n", $lines ) as $line ) {
			$out .= "- `" . str_replace( '`', '', $line ) . "`\n";
		}
		return $out;
	}

	public static function download_blueprint() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export this file.', 'theme-agent-blueprint' ) );
		}
		check_admin_referer( 'tab_download_blueprint', 'tab_download_nonce' );
		$s = self::get_settings();
		$type = isset( $_POST['template_type'] ) ? sanitize_key( wp_unslash( $_POST['template_type'] ) ) : 'page';
		$allowed = array( 'page', 'post', 'single', 'archive' );
		if ( ! in_array( $type, $allowed, true ) ) {
			$type = 'page';
		}
		$content_type = isset( $_POST['content_type'] ) ? sanitize_text_field( wp_unslash( $_POST['content_type'] ) ) : '';
		$content_type = preg_replace( '/[^a-zA-Z0-9_-]/', '', $content_type );
		$markdown = self::build_markdown( $s, $type, $content_type );
        $markdown .= "\n## Dynamic content and implementation contract\n\n";
        $markdown .= "- Field schema and content mapping: " . ( self::safe_lines( $s['field_schema'] ) ?: 'Inspect actual field groups and registered post meta; never invent field keys.' ) . "\n";
        $markdown .= "- Dependencies and integration constraints: " . ( self::safe_lines( $s['dependencies'] ) ?: 'Inspect active plugins, enqueue registrations and relevant APIs; avoid adding duplicate libraries.' ) . "\n";
        $markdown .= "- Acceptance criteria: " . ( self::safe_lines( $s['acceptance'] ) ?: 'Verify responsive layouts, keyboard navigation, semantic markup, output escaping, cache compatibility and error states.' ) . "\n\n";
        $markdown .= "### Reusable delivery rules\n\n1. Inventory existing reusable components and field definitions first. Reuse instead of recreating.\n2. Map each dynamic field to its exact data source, expected return type, sanitization/escaping and empty state.\n3. Check plugin availability before calling plugin-specific APIs. Avoid fatal errors if optional integrations are absent.\n4. Match the active theme's existing CSS variables, typography and design system by default; avoid hardcoded branding.\n5. Scope any new styles/scripts to the generated template; avoid duplicate assets and conflicting global selectors.\n6. Include a dependency checklist, file change list, manual QA plan and rollback notes in the implementation response.\n7. Do not claim implementation or tests succeeded without actually running them.\n";

		$slug = sanitize_file_name( $s['theme_slug'] ? $s['theme_slug'] : 'wordpress-theme' );
		$filename = $slug . '-agent.md';
		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $markdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain Markdown download, all values sanitized above.
		exit;
	}
}
TAB_Theme_Agent_Blueprint_V111::init();
