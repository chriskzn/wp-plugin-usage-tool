# wp-plugin-usage-tool
This is a simple tool to check if the plugins installed on a WordPress Website are actually used anywhere.

## Plugin Usage Audit

This repository includes the WordPress plugin `plugin-usage-audit` which analyses installed plugins and estimates whether they are actively used by your site. It inspects options, postmeta, content, custom tables, and cron hooks and produces an admin-only report with an optional CSV export.

### Installation

- Copy the `plugin-usage-audit` folder into your WordPress `wp-content/plugins/` directory.
- Activate the plugin in the WordPress admin under **Plugins**.

### Requirements

- WordPress (tested on modern WP versions). The plugin runs in the admin area and requires the `manage_options` capability (administrator).
- WP-CLI is optional — the plugin detects if WP-CLI is available and will show the status on the report page, but WP-CLI is not required for operation.

### Usage

- In the WordPress admin, go to **Tools → Plugin Usage Audit**.
- The page displays a table for each installed plugin showing:
	- Plugin name and slug
	- Active status
	- Counts for matching options, postmeta, content occurrences, custom tables, and cron hooks
	- A computed "usage score" (higher = more likely in use)
- Use the **Download CSV** button to export the report as a CSV file for further analysis.

### How it works (brief)

The plugin checks each installed plugin by deriving a slug from the plugin file path and then searching the database for matching strings in:
- `wp_options` (option_name / option_value)
- `wp_postmeta` (meta_key / meta_value)
- `wp_posts` (post_content / post_excerpt, including shortcodes)
- Database tables matching the site's prefix + slug
- Registered cron hooks containing the slug

Each type of footprint contributes to a small weighted usage score. Results are sorted by score (highest first) and active plugins are preferred when scores tie.

### Interpreting the results

- Score 0–1 and inactive → likely safe to remove.
- Score 0–1 but active → investigate why it is active; might be bloat or misconfiguration.
- Score ≥ 3 → plugin is likely providing functionality used by the site — proceed with caution before removing.

### Security & privacy

- The report is only visible to users with the `manage_options` capability (usually site administrators).
- The plugin performs database reads to count matches; it does not modify data.

### Troubleshooting & notes

- If the CSV export prompts a permissions or nonce error, ensure you are an administrator and that the form is submitted from the report page (nonces are checked).
- The plugin relies on simple substring matching of slugs — it is a heuristic and can produce false positives or false negatives. Use the report as a guide, not an absolute truth.

### Example workflow

1. Run the report and export CSV.
2. Review plugins with low scores, especially inactive ones, and test plugin removal on a staging site first.
3. For borderline cases, search your theme and content for plugin-specific shortcodes or function usages before uninstalling.
4. Always back up your site before removing plugins.
