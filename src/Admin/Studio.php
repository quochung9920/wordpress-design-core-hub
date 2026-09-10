<?php
namespace DesignCoreHub\Admin;

use DesignCoreHub\Remote\Config;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Studio {
    public static function register_menu(): void {
        add_menu_page(
            'Design Core Hub',
            'Design Core',
            DCH_CAPABILITY,
            'design-core-hub',
            array( self::class, 'render' ),
            'dashicons-layout',
            58
        );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( false === strpos( $hook, 'design-core-hub' ) ) {
            return;
        }

        wp_enqueue_style( 'dch-admin', DCH_URL . 'assets/admin.css', array(), DCH_VERSION );
        wp_enqueue_script( 'dch-admin', DCH_URL . 'assets/admin.js', array(), DCH_VERSION, true );
        wp_localize_script(
            'dch-admin',
            'DCH_HUB',
            array(
                'restBase' => esc_url_raw( rest_url( 'design-core-hub/v1' ) ),
                'nonce'    => wp_create_nonce( 'wp_rest' ),
            )
        );
    }

    public static function render(): void {
        if ( ! current_user_can( DCH_CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to use Design Core Hub.', 'wordpress-design-core-hub' ) );
        }

        $config = Config::get();
        ?>
        <div class="wrap dch-hub" id="dch-hub">
            <header class="dch-header">
                <div>
                    <h1>WordPress Design Core Hub</h1>
                    <p>ChatGPT Chat → GitHub build inbox → validated Gutenberg draft. No MCP and no browser automation required for normal builds.</p>
                </div>
                <span class="dch-version">v<?php echo esc_html( DCH_VERSION ); ?></span>
            </header>

            <div id="dch-status-banner" class="dch-status" role="status" aria-live="polite">Loading sync status…</div>

            <div class="dch-grid dch-grid-3 dch-summary">
                <section class="dch-card dch-card-compact">
                    <h2>Site key</h2>
                    <code id="dch-site-key"><?php echo esc_html( $config['site_key'] ); ?></code>
                    <p>ChatGPT writes builds under this site's inbox.</p>
                </section>
                <section class="dch-card dch-card-compact">
                    <h2>Expected pointer</h2>
                    <code id="dch-latest-path"><?php echo esc_html( Config::latest_path( $config ) ); ?></code>
                    <p><code>latest.json</code> must be committed last.</p>
                </section>
                <section class="dch-card dch-card-compact">
                    <h2>Auto sync</h2>
                    <strong id="dch-auto-sync-state">Checking…</strong>
                    <p id="dch-next-cron">Next cron: —</p>
                </section>
            </div>

            <section class="dch-card">
                <div class="dch-card-title-row">
                    <div>
                        <h2>Chat-only endpoints</h2>
                        <p class="description">These URLs let a normal ChatGPT conversation verify and trigger the GitHub inbox without logging in to WordPress.</p>
                    </div>
                    <button type="button" class="button" id="dch-copy-chat-prompt">Copy setup prompt</button>
                </div>

                <label class="dch-copy-field">
                    <span>Public status URL</span>
                    <input id="dch-public-status-url" type="text" readonly>
                    <button type="button" class="button dch-copy" data-copy="#dch-public-status-url">Copy</button>
                </label>
                <label class="dch-copy-field">
                    <span>Public sync URL</span>
                    <input id="dch-public-sync-url" type="text" readonly>
                    <button type="button" class="button dch-copy" data-copy="#dch-public-sync-url">Copy</button>
                </label>
                <p class="description">The public sync URL cannot accept page content. It can only pull the latest validated build from the configured trusted GitHub repository and write to Design Core managed drafts.</p>
            </section>

            <section class="dch-card">
                <div class="dch-card-title-row">
                    <div>
                        <h2>GitHub build inbox</h2>
                        <p class="description">v0.2 supports a public GitHub repository. No GitHub token is stored in WordPress.</p>
                    </div>
                    <div class="dch-actions">
                        <button type="button" class="button" id="dch-refresh">Refresh</button>
                        <button type="button" class="button button-primary" id="dch-sync-now">Sync now</button>
                    </div>
                </div>

                <div class="dch-grid dch-grid-2">
                    <label>GitHub owner
                        <input id="dch-owner" type="text" value="<?php echo esc_attr( $config['owner'] ); ?>" autocomplete="off">
                    </label>
                    <label>Repository
                        <input id="dch-repo" type="text" value="<?php echo esc_attr( $config['repo'] ); ?>" autocomplete="off">
                    </label>
                    <label>Branch
                        <input id="dch-branch" type="text" value="<?php echo esc_attr( $config['branch'] ); ?>" autocomplete="off">
                    </label>
                    <label>Build root
                        <input id="dch-build-root" type="text" value="<?php echo esc_attr( $config['build_root'] ); ?>" autocomplete="off">
                    </label>
                    <label>Site key
                        <input id="dch-site-key-input" type="text" value="<?php echo esc_attr( $config['site_key'] ); ?>" autocomplete="off">
                    </label>
                </div>

                <div class="dch-checks">
                    <label><input id="dch-enabled" type="checkbox" <?php checked( $config['enabled'] ); ?>> Enable automatic GitHub build sync</label>
                    <label><input id="dch-public-trigger" type="checkbox" <?php checked( $config['public_trigger'] ); ?>> Allow the public draft-only sync trigger</label>
                </div>
                <div class="dch-actions">
                    <button type="button" class="button button-primary" id="dch-save-settings">Save settings</button>
                </div>
            </section>

            <section class="dch-card">
                <div class="dch-card-title-row">
                    <div>
                        <h2>Latest sync</h2>
                        <p class="description">The last observed pointer, validation result, and applied draft are shown here for debugging.</p>
                    </div>
                </div>
                <pre id="dch-sync-output" class="dch-output">No status loaded yet.</pre>
            </section>

            <section class="dch-card">
                <div class="dch-card-title-row">
                    <div>
                        <h2>Managed drafts</h2>
                        <p class="description">Remote builds never attach themselves to arbitrary or published pages.</p>
                    </div>
                    <button type="button" class="button" id="dch-refresh-drafts">Refresh drafts</button>
                </div>
                <div id="dch-drafts" class="dch-table-wrap"><p>No drafts loaded yet.</p></div>
            </section>

            <section class="dch-card dch-security">
                <h2>Build safety model</h2>
                <ul>
                    <li>Only files from the configured GitHub repository/branch are trusted as build input.</li>
                    <li><code>latest.json</code>, <code>manifest.json</code>, <code>blocks.html</code>, and <code>styles.css</code> are integrity checked with SHA-256.</li>
                    <li>Only registered Gutenberg blocks are accepted; semantic policy rejects freeform HTML and <code>core/html</code>/<code>core/shortcode</code> by default.</li>
                    <li>Remote builds only create or update Design Core managed <strong>draft</strong> pages.</li>
                    <li>No remote PHP, SQL, shell commands, plugin installation, theme editing, or publish action exists.</li>
                </ul>
            </section>
        </div>
        <?php
    }
}
