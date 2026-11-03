# Theme Agent Blueprint

**Project-aware Markdown blueprints for safer, faster AI-assisted WordPress theme development.**

![Version](https://img.shields.io/badge/version-1.0.0-blue) ![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-21759b) ![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4) ![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green)

**Theme Agent Blueprint** is a WordPress administrator tool that produces a downloadable, site-specific Markdown playbook for use with an AI coding assistant or developer. It brings together the site's theme context, file conventions, brand rules, custom-field expectations, dependencies, and quality requirements so that repeated template-development requests can start from a consistent specification.

Rather than editing a theme automatically, the plugin **generates instructions** for implementing changes safely. The generated playbook asks the coding agent to inspect the real codebase, reuse existing components, and verify its work.

> **Release status:** v1.0.0 is the initial pre-release version. Test on a staging site before deploying to production.

## Why use it?

WordPress developers frequently repeat the same project instructions: which theme is active, where template parts belong, how assets are registered, which field framework is in use, and which existing files must not be overwritten. Theme Agent Blueprint captures that information in a reusable Markdown document to reduce repetitive prompting and make AI-assisted development more consistent.

## Features

### 1. Site-aware project profile

- Prefills the site name, site URL, active theme name, theme slug, and theme directory.
- Records the existing CSS and JavaScript files, image and font directories, template/route files, and template-parts directory.
- Lets you specify the theme's asset-registration file and ACF/SCF JSON directory.
- Stores optional project-specific exclusions and architectural conventions.
- Saves the configured profile in the site's WordPress options.

### 2. Four focused template workflows

Choose **one** template type when exporting:

| Workflow | Intended development task |
| --- | --- |
| **Page template** | Create a new, selectable page template, with scoped routing or registration where needed. |
| **Post template** | Create a new post-specific template and explicit selection logic where WordPress does not automatically discover the filename. |
| **Single CPT / single content** | Create a template for a registered custom post type or narrowly scoped single-content use case. |
| **Archive / listing page** | Create an archive/listing template with appropriate query, pagination, taxonomy, and empty-state handling. |

The **“All template types”** selection is intentionally absent from the admin dropdown, keeping exports focused on one task.

### 3. Dynamic content and custom fields

Document expected field names, field types, repeaters, relationships, image return formats, content mapping, fallback behavior, and output rules. The generated playbook instructs developers or coding agents to inspect real ACF/SCF field definitions and registered post meta rather than inventing field keys.

**Important:** The plugin does not itself create ACF field groups, register custom post types, or populate fields.

### 4. Theme-consistent styling and assets

- Inherit the active theme's existing colors, typography, variables, and component conventions by default.
- Supply explicit brand overrides only when needed.
- Encourage template-scoped CSS/JS, conditional enqueueing, and reuse of existing libraries.
- Include start/end code-marker conventions for changes added to shared CSS/JS files.
- Preserve existing theme behavior instead of replacing global templates or unrelated code.

### 5. Dependencies and acceptance criteria

Describe required plugins, JavaScript libraries, integrations, APIs, graceful fallbacks, browser checks, accessibility requirements, responsiveness, and performance expectations. The exported playbook includes a definition of done, validation checklist, file-change handoff, and rollback notes.

### 6. Read-only environment diagnostics

The administration screen displays:

- WordPress version and PHP version
- Active theme slug and child-theme status
- Whether ACF-compatible field-group functions are detected
- Whether the WooCommerce class is detected

These are **basic runtime checks**, not a comprehensive compatibility audit or source-code scan.

### 7. Admin interface

A dedicated **Theme Agent** menu in WordPress admin provides a project-profile form, a template-type selector, a template slug/post-type input, and a **Generate Markdown** download action. The v1.0.0 interface includes responsive layout and alignment improvements.

### 8. Exportable Markdown handoff

Download a file named after the theme, for example:

```text
my-theme-agent.md
```

Commit it to the relevant project repository or provide it to your preferred coding agent alongside access to the actual theme code. Reuse the playbook in future development prompts and regenerate it when project settings change.

## How it works

```text
WordPress site
    |
    v
Theme Agent admin page
    |
    +-- Site and active-theme context
    +-- File paths and coding conventions
    +-- Brand colors and typography
    +-- Dynamic fields and dependencies
    +-- Acceptance criteria
    |
    v
Save project profile
    |
    v
Select template type + optional slug
    |
    v
Generate Markdown
    |
    v
<theme-slug>-agent.md
    |
    v
Provide to developer / AI coding assistant
    |
    v
Review, implement, and test changes in the real project
```

## Requirements

- WordPress **6.2 or newer**
- PHP **7.4 or newer**
- Administrator account with the `manage_options` capability
- A compatible active WordPress theme

ACF/SCF and WooCommerce are **optional**; their availability is reported in diagnostics, not required for activation.

## Installation

1. Download the plugin ZIP from the repository's releases (when published), or package the `theme-agent-blueprint` directory as a ZIP.
2. In WordPress admin, navigate to **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the ZIP and activate the plugin.
4. Open **Theme Agent** in the WordPress sidebar.
5. Review the detected theme details, fill in verified project paths, and save the profile.
6. Select **Page**, **Post**, **Single CPT**, or **Archive**, optionally enter a target slug, and click **Generate Markdown**.
7. Save the downloaded Markdown file in the project repository and share it with your coding assistant or team.

## Recommended workflow

1. **Inspect the project first.** Confirm the active theme, child-theme status, existing template hierarchy, and any custom field JSON.
2. **Enter only known paths.** Leave optional paths blank rather than guessing; the playbook directs the implementer to discover them.
3. **Describe dynamic data.** List field names, repeaters, relationships, fallbacks, and integration constraints.
4. **Choose a single template task.** Enter a meaningful target slug/post type where applicable.
5. **Export and review the Markdown.** Check the generated instructions before sharing them.
6. **Implement on staging.** Have the developer or AI coding agent inspect the repository, create uniquely named templates, and avoid overwriting existing templates.
7. **Validate and deploy separately.** Test rendering, mobile behavior, empty-field states, permissions, compatibility, and rollback before promoting code to production.

## Example use case

**Goal:** Create a custom `services` single-CPT template using existing theme styling and ACF fields.

- Configure the active theme and its template/assets conventions.
- In **Dynamic fields and content mapping**, describe fields such as `service_subtitle`, `service_hero_image`, and `service_features` (only if these fields actually exist in your project).
- Select **Single CPT / single content** and enter `services` as the target.
- Generate the Markdown playbook and provide it to the coding assistant with the theme source.
- Verify that the resulting implementation follows the actual CPT registration, field definitions, and template hierarchy.

This is an **illustrative workflow**; the plugin does not automatically generate or deploy `single-services.php`.

## Security and privacy

- Admin access, profile saving, and exports require `manage_options`.
- Save and download form actions use WordPress nonces.
- Profile input is sanitized before storage and admin UI output is escaped.
- The export endpoint returns a Markdown download; it does not write, execute, or deploy theme code.
- The plugin does not include a built-in AI API connection or automatically transmit profile information to an AI provider.

**Data-sharing reminder:** The downloaded Markdown may contain project URLs, internal paths, or notes entered by the administrator. Review it before committing it to a public repository or sharing it with a third-party AI service. Never enter API keys, passwords, or other secrets into the project profile.

These safeguards do not constitute a security certification. Follow normal WordPress hardening practices and test before production use.

## Current scope and limitations

| Capability | v1.0.0 |
| --- | --- |
| Save a reusable project profile | Yes |
| Export template-specific Markdown instructions | Yes |
| Document dynamic fields and dependencies | Yes |
| Show basic site/environment diagnostics | Yes |
| Automatically scan all theme source files | No |
| Create PHP templates or ACF groups directly | No |
| Execute AI prompts or connect to an AI model | No |
| Preview or publish generated templates | No |
| Guarantee accurate code on every WordPress site | No |

The quality of the blueprint depends on the accuracy of the configured project information and the subsequent developer/agent review.

## Project structure

```text
theme-agent-blueprint/
├── theme-agent-blueprint.php    # Plugin bootstrap, admin UI, settings, Markdown export
├── assets/
│   ├── admin.css                # Admin interface styles
│   └── admin.js                 # Admin interface behavior
└── readme.txt                   # WordPress-style plugin readme
```

## Potential future enhancements

The following are **ideas, not included in v1.0.0**:

- Guided discovery of existing templates, assets, and field groups
- Reusable project presets and import/export
- More detailed validation of file paths and field mappings
- Blueprint previews and per-section editing before download
- Integrations with external coding assistants
- Automated test-report generation and compatibility checks

## Contributing

Bug reports, reproducible issues, and pull requests are welcome. When reporting an issue, include the WordPress and PHP versions, active theme/child-theme details, steps to reproduce, and relevant error messages. Remove credentials and sensitive project data from reports.

## Author

**Darshit Rajyaguru**  
WordPress.org: [profiles.wordpress.org/darshitrajyaguru97](https://profiles.wordpress.org/darshitrajyaguru97/)

## License

Licensed under **GPL-2.0-or-later**. See the plugin header and WordPress-style `readme.txt` for license metadata.

## Changelog

### 1.0.0 — Initial pre-release

- Project-aware Markdown blueprint generation
- Four targeted template workflows
- Dynamic field, dependency, and acceptance-criteria inputs
- Basic read-only WordPress environment diagnostics
- Responsive administration UI improvements
- Author metadata and initial release version standardized
