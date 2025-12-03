<?php
/**
 * Plugin Name: Plugin Usage Audit
 * Description: Analyse which plugins are actually in use (DB, content, cron, tables) and show an admin-only report with CSV export.
 * Version:     1.1.0
 * Author:      Christopher Wells
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin_Usage_Audit {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_post_plugin_usage_audit_csv', [ $this, 'handle_csv_export' ] );
    }

    /**
     * Add admin menu item under Tools
     */
    public function add_menu() {
        add_management_page(
            'Plugin Usage Audit',
            'Plugin Usage Audit',
            'manage_options',
            'plugin-usage-audit',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Helper to get a clean slug from plugin file path.
     */
    protected function clean_slug( $file ) {
        $dir = dirname( $file );
        if ( $dir === '.' ) {
            $dir = basename( $file );
        }
        return sanitize_key( $dir );
    }

    /**
     * Core analysis logic – returns an array of plugin usage data.
     */
    protected function get_results() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        global $wpdb;

        $plugins = get_plugins();
        $results = [];

        foreach ( $plugins as $plugin_file => $data ) {
            $slug      = $this->clean_slug( $plugin_file );
            $name      = isset( $data['Name'] ) ? $data['Name'] : $slug;
            $is_active = is_plugin_active( $plugin_file );

            // LIKE pattern based on slug
            $like_slug = '%' . $wpdb->esc_like( $slug ) . '%';

            // 1) Options footprint
            $option_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) 
                     FROM {$wpdb->options} 
                     WHERE option_name LIKE %s OR option_value LIKE %s",
                    $like_slug,
                    $like_slug
                )
            );

            // 2) Postmeta footprint
            $postmeta_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) 
                     FROM {$wpdb->postmeta} 
                     WHERE meta_key LIKE %s OR meta_value LIKE %s",
                    $like_slug,
                    $like_slug
                )
            );

            // 3) Content footprint (slug + possible shortcode)
            $content_like_shortcode = '%' . $wpdb->esc_like( '[' . $slug ) . '%';
            $content_like_slug      = '%' . $wpdb->esc_like( $slug ) . '%';

            $content_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) 
                     FROM {$wpdb->posts} 
                     WHERE (post_content LIKE %s 
                            OR post_content LIKE %s
                            OR post_excerpt LIKE %s 
                            OR post_excerpt LIKE %s)
                       AND post_status NOT IN ('auto-draft','trash')",
                    $content_like_shortcode,
                    $content_like_slug,
                    $content_like_shortcode,
                    $content_like_slug
                )
            );

            // 4) Custom tables footprint
            $table_like  = $wpdb->esc_like( $wpdb->prefix . $slug ) . '%';
            $tables      = $wpdb->get_col( "SHOW TABLES LIKE '{$table_like}'" );
            $table_count = is_array( $tables ) ? count( $tables ) : 0;

            // 5) Cron footprint
            $cron_count = 0;
            if ( ! function_exists( '_get_cron_array' ) ) {
                require_once ABSPATH . 'wp-includes/cron.php';
            }
            if ( function_exists( '_get_cron_array' ) ) {
                $cron = _get_cron_array();
                if ( is_array( $cron ) ) {
                    foreach ( $cron as $timestamp => $hooks ) {
                        foreach ( $hooks as $hook => $instances ) {
                            if ( stripos( $hook, $slug ) !== false ) {
                                $cron_count += count( $instances );
                            }
                        }
                    }
                }
            }

            // Usage score – tweak weights if you like
            $usage_score =
                ( $option_count   > 0 ? 2 : 0 ) +
                ( $postmeta_count > 0 ? 2 : 0 ) +
                ( $content_count  > 0 ? 3 : 0 ) +
                ( $table_count    > 0 ? 2 : 0 ) +
                ( $cron_count     > 0 ? 1 : 0 );

            $results[] = [
                'name'        => $name,
                'slug'        => $slug,
                'file'        => $plugin_file,
                'active'      => $is_active ? 'yes' : 'no',
                'options'     => $option_count,
                'postmeta'    => $postmeta_count,
                'content'     => $content_count,
                'tables'      => $table_count,
                'cron'        => $cron_count,
                'usage_score' => $usage_score,
            ];
        }

        // Sort: highest usage_score first, then active plugins before inactive
        usort( $results, function ( $a, $b ) {
            if ( $a['usage_score'] === $b['usage_score'] ) {
                if ( $a['active'] === $b['active'] ) {
                    return strcasecmp( $a['name'], $b['name'] );
                }
                return ( $a['active'] === 'yes' ) ? -1 : 1;
            }
            return $b['usage_score'] <=> $a['usage_score'];
        } );

        return $results;
    }

    /**
     * Handle CSV export.
     */
    public function handle_csv_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You are not allowed to export this data.' );
        }

        check_admin_referer( 'plugin_usage_audit_csv' );

        $results = $this->get_results();

        // Set headers
        $filename = 'plugin-usage-audit-' . date( 'Y-m-d-H-i-s' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $output = fopen( 'php://output', 'w' );

        // CSV header row
        fputcsv( $output, [
            'Plugin Name',
            'Slug',
            'Plugin File',
            'Active',
            'Options Count',
            'Postmeta Count',
            'Content Count',
            'Tables Count',
            'Cron Count',
            'Usage Score',
        ] );

        // Data rows
        foreach ( $results as $row ) {
            fputcsv( $output, [
                $row['name'],
                $row['slug'],
                $row['file'],
                $row['active'],
                $row['options'],
                $row['postmeta'],
                $row['content'],
                $row['tables'],
                $row['cron'],
                $row['usage_score'],
            ] );
        }

        fclose( $output );
        exit;
    }

    /**
     * Render the admin page.
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $results = $this->get_results();

        // WP-CLI detection
        $wp_cli_available = ( defined( 'WP_CLI' ) && WP_CLI ) || class_exists( 'WP_CLI' );
        ?>
        <div class="wrap">
            <h1>Plugin Usage Audit</h1>

            <p>
                This report estimates how much each plugin is actually used based on:
                <strong>options</strong>, <strong>meta</strong>, <strong>content</strong>, <strong>tables</strong>, and <strong>cron</strong>.
            </p>

            <h2>Environment</h2>
            <p>
                <strong>WP-CLI status:</strong>
                <?php if ( $wp_cli_available ) : ?>
                    <span style="color: #008000; font-weight: bold;">Detected</span>
                <?php else : ?>
                    <span style="color: #cc0000; font-weight: bold;">Not detected</span>
                    <br>
                    <small>You can install WP-CLI at <a href="https://wp-cli.org/" target="_blank" rel="noopener noreferrer">wp-cli.org</a>, but it cannot be auto-installed by this plugin.</small>
                <?php endif; ?>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 15px 0;">
                <?php wp_nonce_field( 'plugin_usage_audit_csv' ); ?>
                <input type="hidden" name="action" value="plugin_usage_audit_csv">
                <button type="submit" class="button button-primary">
                    Download CSV
                </button>
            </form>

            <h2>Plugin usage table</h2>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>Plugin</th>
                        <th>Slug</th>
                        <th>Active</th>
                        <th>Options</th>
                        <th>Meta</th>
                        <th>Content</th>
                        <th>Tables</th>
                        <th>Cron</th>
                        <th>Usage score</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $results ) ) : ?>
                    <tr><td colspan="9">No plugins found.</td></tr>
                <?php else : ?>
                    <?php foreach ( $results as $row ) : ?>
                        <?php
                        $score       = (int) $row['usage_score'];
                        $score_label = $score;
                        $score_color = '#000000';

                        if ( $score <= 1 ) {
                            $score_color = '#cc0000'; // likely unused
                        } elseif ( $score <= 3 ) {
                            $score_color = '#e6a700'; // maybe
                        } else {
                            $score_color = '#008000'; // clearly used
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html( $row['name'] ); ?></td>
                            <td><?php echo esc_html( $row['slug'] ); ?></td>
                            <td>
                                <?php if ( $row['active'] === 'yes' ) : ?>
                                    <span style="color:#008000; font-weight:bold;">Yes</span>
                                <?php else : ?>
                                    <span style="color:#777;">No</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $row['options'] ); ?></td>
                            <td><?php echo esc_html( $row['postmeta'] ); ?></td>
                            <td><?php echo esc_html( $row['content'] ); ?></td>
                            <td><?php echo esc_html( $row['tables'] ); ?></td>
                            <td><?php echo esc_html( $row['cron'] ); ?></td>
                            <td>
                                <span style="color: <?php echo esc_attr( $score_color ); ?>; font-weight:bold;">
                                    <?php echo esc_html( $score_label ); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <h2>Interpretation</h2>
            <ul>
                <li><strong>Score 0–1 &amp; inactive</strong> → almost certainly safe to remove.</li>
                <li><strong>Score 0–1 &amp; active</strong> → suspicious bloat; investigate why it’s active.</li>
                <li><strong>Score ≥ 3</strong> → clearly doing something your site depends on.</li>
            </ul>
        </div>
        <?php
    }
}

new Plugin_Usage_Audit();
