=== Theme Agent Blueprint ===
Contributors: internal
Tags: developer-tools, themes, markdown, admin
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.6.1
License: GPLv2 or later

Create a project-specific Markdown playbook and import packaged HTML designs into the active WordPress theme.

== Installation ==
1. In WordPress Admin, go to Plugins > Add New Plugin > Upload Plugin.
2. Upload theme-agent-blueprint.zip and activate it.
3. Open Theme Agent in the admin sidebar.
4. Review the detected active theme. Optional paths can be left blank for the coding agent to discover.
5. Save the profile, select a template type, and click Generate Markdown.
6. Place the downloaded [theme-slug]-agent.md in the project repository and reference it in future coding requests.

== HTML template import ==
1. Save the active theme's existing shared CSS and JavaScript file paths, image/font directories, and template destination in the project profile.
2. Prepare a ZIP containing the source HTML and its local CSS, JavaScript, image, and font assets.
3. Enter a unique WordPress template name. Enter the HTML filename when the ZIP contains more than one HTML file.
4. Choose Static HTML content or Dynamic SCF / ACF fields, then upload the ZIP.
5. Review the generated PHP template and, for dynamic mode, its SCF/ACF JSON field group. Assign the template to a page in WordPress.

The source HTML header and footer are excluded in both modes so the active theme's get_header() and get_footer() output remains authoritative. Static mode keeps page content in the generated PHP template. Dynamic mode converts headings, text, images, links, and annotated file downloads into typed SCF/ACF fields. Repeated card, testimonial, logo, FAQ, step, and similar structures become repeater fields. Source content remains as a frontend fallback until fields are populated.

Dynamic fields are inferred automatically. Repeated steps, fields, choices, cards, and similar sibling structures become one repeater with typed image, text, textarea, link, file, and URL subfields. For deterministic names or specialized types, add `data-tab-field="field_name"` to an editable HTML element and optionally add `data-tab-type="text|textarea|wysiwyg|image|file|link|url"`. For example, `<a href="guide.pdf" data-tab-field="course_guide" data-tab-type="file">Download guide</a>` creates a file-upload field. URL-bearing buttons retain their button markup and receive a URL field. These authoring attributes are removed from generated frontend markup.

Structure-aware generation keeps forms, inputs, runtime result placeholders, summaries, and JavaScript-bound controls static so field editing cannot break application behavior. Institution/product identity values become required repeater subfields. Functional step repeaters are fixed to their source row count. Field and subfield keys/names are validated for duplicates before any JSON is written.

When inference needs project-specific intent, use these source HTML annotations:
- `data-tab-field="name"` sets a deterministic field name.
- `data-tab-type="type"` sets text, textarea, WYSIWYG, image, file, link, or URL type.
- `data-tab-repeater="items"` forces and names a repeated collection on its parent element.
- `data-tab-ignore` keeps an element and its descendants static and out of SCF/ACF.

Annotations are authoring metadata and are removed from generated frontend HTML. Existing generated templates and JSON are snapshots; revoke and re-import them to rebuild with newer generation rules.

Imported CSS and JavaScript are appended to the configured existing shared files between named markers. Media is copied into the configured shared directories, and local URLs are rewritten to active-theme URLs. The importer never creates template-named CSS, JavaScript, or media directories. The generated field-group JSON is saved in the configured SCF/ACF JSON directory.

== Template history ==
Every new import creates a History record and a private restore snapshot. The History tab can revoke the generated PHP template, optional field-group JSON, imported media, and the template's marked CSS/JavaScript blocks in one action. Revoked imports remain available for one-click restore.

Revoke removes tracked generated files and complete marked asset blocks even when they were edited after import. Media shared by another active tracked import is retained until it is no longer referenced. Incomplete marker pairs still stop removal to avoid deleting unrelated shared CSS or JavaScript. Restore snapshots are stored under the WordPress uploads directory with Apache and IIS access-denial rules.

Project and History views switch through an AJAX content swap with browser back/forward support. Their normal admin URLs remain available as a fallback when JavaScript or a request is unavailable.

History is prefetched through a lightweight authenticated AJAX fragment and both Project and History markup are cached in memory, making repeated tab switching immediate. Template import and agent generation display an accessible top-level progress visual. Import progress uses measured upload bytes through 65%, then labeled validation, conversion, asset, snapshot, and finalization stages while the server completes. Agent generation displays build and download stages and starts the Markdown download without leaving the page.

== Security ==
- Only users with manage_options can view, save, or export settings.
- Save and export actions are nonce-protected.
- Values are sanitized on save and escaped when rendered in the admin UI.
- ZIP imports are limited to administrators, nonce-protected, restricted by file type/count/size, and checked for unsafe paths.
- Embedded PHP is removed from uploaded HTML, existing templates are never overwritten, and writes are rolled back after a filesystem failure.
- Importing intentionally writes a new PHP template and merges assets into the configured active-theme files. Back up or version-control the theme before importing.
- Use HTTPS for the WordPress admin and keep WordPress/plugins/themes updated.

== Notes ==
SCF or ACF is required to edit generated custom fields. Field inference is structural: common content and repeated UI patterns are converted automatically, but business data sources, API queries, taxonomies, and application-specific JavaScript cannot be inferred reliably from visual HTML alone. Verify the generated template in a staging environment and keep theme files under version control.

== Changelog ==
= 1.6.1 =
* Fixed hidden action inputs shadowing the form action URL and causing [object HTMLInputElement] requests.
* Kept successful and failed import results on the current Project view without opening a new tab.
* Displayed returned WordPress notices in place and preserved form values after failed imports.
* Invalidated the prefetched History cache after successful imports.

= 1.6.0 =
* Added measured upload and staged server progress for template imports.
* Added animated percentage and water-fill progress for agent Markdown generation.
* Added a lightweight authenticated History AJAX endpoint with idle prefetch and in-memory view caching.
* Applied display: contents to Dashicon spans inside clickable controls.
* Added accessible status messaging and reduced-motion behavior to progress visuals.

= 1.5.0 =
* Added structure-aware schema generation that excludes form controls, summaries, runtime placeholders, and JavaScript-bound content.
* Added semantic panel field names and concise labels without numeric implementation prefixes.
* Added institution, product, step, and Bootstrap-wrapped card repeater models with typed semantic subfields.
* Added required functional identity fields and fixed source row counts for data-step repeaters.
* Enforced unique field keys, top-level names, and repeater subfield names before JSON writes.
* Added data-tab-repeater and data-tab-ignore authoring annotations.
* Preserved source document order in generated field groups.

= 1.4.0 =
* Grouped repeated steps, fields, choices, images, text, and textarea content into typed repeater fields.
* Normalized state classes such as current and active while detecting repeated structures.
* Added URL fields for URL-bearing buttons without converting button markup into anchors.
* Made Revoke remove edited tracked files and complete CSS/JavaScript marker blocks.
* Added AJAX Project/History navigation with fallback URLs and browser history support.

= 1.3.1 =
* Preserved nested span and inline markup structure in dynamic templates without exposing raw tags.
* Generated plain text fields at leaf spans instead of replacing styled parent elements.
* Protected interactive elements and JavaScript data hooks from custom-field conversion.
* Added regression coverage for step/progress layouts with nested labels.

= 1.3.0 =
* Added separate static HTML and dynamic SCF/ACF import modes.
* Added explicit field naming and text, textarea, WYSIWYG, image, file, and link type annotations.
* Kept automatic repeater inference and template-assigned JSON generation for dynamic imports.
* Added a reversible Revoke action backed by protected History snapshots.
* Preserved active-theme header and footer handling in both import modes.

= 1.2.0 =
* Excluded packaged header and footer markup in favor of the active theme header and footer.
* Added automatic typed SCF/ACF field generation for text, rich text, images, and links.
* Added repeater inference for testimonials, cards, logos, FAQs, steps, and similar repeated structures.
* Added template-specific field-group JSON generation with source-content fallbacks.
* Added a standalone dynamic-conversion regression test.
* Added tracked template history with safe one-click removal and restoration.

= 1.1.0 =
* Moved plugin status messages above the custom banner and improved notice/version contrast.
* Added ZIP upload with optional HTML filename selection.
* Added HTML-to-page-template generation with local asset URL rewriting.
* Merged imported CSS and JavaScript into configured existing shared files.
* Copied images and fonts into configured shared directories without overwriting unrelated files.
* Added ZipArchive and WordPress PclZip support, archive limits, rollback, and path validation.

= 1.0.0 =
* Updated author and author URI.
* Removed all-template export option.
* Added dynamic field, dependency and acceptance-criteria prompts.
* Added read-only environment diagnostics.
* Improved responsive form and button alignment.
