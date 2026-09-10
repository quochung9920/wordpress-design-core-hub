<?php
namespace DesignCoreHub\Admin;
if ( ! defined( 'ABSPATH' ) ) exit;
final class Studio {
    public static function register_menu(): void {
        add_menu_page('Design Core Studio','Design Core',DCH_CAPABILITY,'design-core-hub',array(self::class,'render'),'dashicons-layout',58);
        add_submenu_page(null,'Design Core Reference','Design Core Reference',DCH_CAPABILITY,'design-core-hub-reference',array(self::class,'render_reference'));
    }
    public static function enqueue_assets(string $hook): void {
        if(false===strpos($hook,'design-core-hub')) return;
        wp_enqueue_style('dch-admin',DCH_URL.'assets/admin.css',array(),DCH_VERSION);
        wp_enqueue_script('dch-admin',DCH_URL.'assets/admin.js',array(),DCH_VERSION,true);
        wp_localize_script('dch-admin','DCH_STUDIO',array('restBase'=>esc_url_raw(rest_url('design-core-hub/v1')),'nonce'=>wp_create_nonce('wp_rest')));
    }
    public static function render(): void {
        if(!current_user_can(DCH_CAPABILITY)) wp_die(esc_html__('You do not have permission to use Design Core Hub.','wordpress-design-core-hub'));
        ?>
        <div class="wrap dch-studio" id="dch-studio">
            <header class="dch-header"><div><h1>Design Core Studio</h1><p>Browser-optimized Gutenberg builder. Build drafts, validate native blocks, compare a reference, and iterate safely.</p></div><span class="dch-version">v<?php echo esc_html(DCH_VERSION); ?></span></header>
            <div id="dch-status" class="dch-status" role="status" aria-live="polite">Ready.</div>
            <section class="dch-card"><h2>1. Target draft</h2><div class="dch-grid dch-grid-3"><label>New page title<input id="dch-new-title" type="text" placeholder="Homepage AI Draft"></label><label>Slug<input id="dch-new-slug" type="text" placeholder="homepage-ai-draft"></label><div class="dch-field-actions"><button class="button button-primary" id="dch-create-draft">Create Draft</button></div></div><div class="dch-grid dch-grid-2 dch-mt"><label>Existing page<select id="dch-page-select"><option value="">Select a page...</option></select></label><div class="dch-field-actions"><button class="button" id="dch-refresh-pages">Refresh Pages</button><button class="button" id="dch-load-page">Load Page</button><a class="button" id="dch-open-preview" href="#" target="_blank" rel="noopener">Open Draft</a></div></div><p class="description">Writes are blocked for published pages. Build on a draft, inspect it, then publish with WordPress when approved.</p></section>
            <section class="dch-card"><h2>2. Reference HTML</h2><textarea id="dch-reference-html" class="dch-code dch-code-lg" spellcheck="false" placeholder="Paste the complete reference HTML/CSS here."></textarea><div class="dch-actions"><button class="button" id="dch-save-reference">Create Sandboxed Reference</button><a class="button" id="dch-open-reference" href="#" target="_blank" rel="noopener">Open Reference</a></div></section>
            <section class="dch-card"><h2>3. Gutenberg build</h2><p class="description">Preferred path: semantic Gutenberg block markup plus page-scoped CSS.</p><details class="dch-details"><summary>Optional Blueprint JSON compiler</summary><textarea id="dch-blueprint" class="dch-code" spellcheck="false" placeholder='{"version":1,"blocks":[{"type":"group","className":"hero","children":[{"type":"heading","level":1,"text":"Hello"}]}],"css":".hero{min-height:80vh}"}'></textarea><div class="dch-actions"><button class="button" id="dch-compile">Compile Blueprint</button></div></details><label for="dch-content"><strong>Serialized Gutenberg blocks</strong></label><textarea id="dch-content" class="dch-code dch-code-lg" spellcheck="false"></textarea><label for="dch-css"><strong>Page CSS</strong></label><textarea id="dch-css" class="dch-code" spellcheck="false"></textarea><div class="dch-actions"><button class="button" id="dch-validate">Validate Build</button><button class="button button-primary" id="dch-apply">Apply to Draft</button></div><pre id="dch-validation" class="dch-output">No validation run yet.</pre></section>
            <section class="dch-card"><h2>4. Site inspection</h2><div class="dch-actions"><button class="button" id="dch-load-context">Site Context</button><button class="button" id="dch-load-design-system">Design System</button></div><div class="dch-grid dch-grid-2 dch-mt"><label>Find registered blocks<input id="dch-block-search" type="search" placeholder="button, gallery, query..."></label><div class="dch-field-actions"><button class="button" id="dch-search-blocks">Search Blocks</button></div></div><pre id="dch-inspection" class="dch-output dch-output-lg">Use Site Context, Design System, or Search Blocks when implementation details are needed.</pre></section>
            <section class="dch-card"><h2>5. Build history</h2><div class="dch-actions"><button class="button" id="dch-load-history">Refresh History</button></div><div id="dch-history" class="dch-history"><p>No history loaded.</p></div></section>
        </div><?php
    }
    public static function render_reference(): void {
        if(!current_user_can(DCH_CAPABILITY)) wp_die(esc_html__('You do not have permission to view this reference.','wordpress-design-core-hub'));
        $token=isset($_GET['ref'])?sanitize_text_field(wp_unslash($_GET['ref'])):'';
        $data=$token?get_transient('dch_ref_'.$token):false;
        if(!is_array($data)||(int)($data['user_id']??0)!==get_current_user_id()) wp_die(esc_html__('Reference not found or expired.','wordpress-design-core-hub'));
        $html=(string)($data['html']??''); $name=(string)($data['name']??'Reference'); ?>
        <div class="wrap dch-reference-page"><h1><?php echo esc_html($name); ?></h1><p>This preview is sandboxed: scripts, forms, popups, and top-level navigation are disabled.</p><iframe class="dch-reference-frame" title="Design reference" sandbox="" referrerpolicy="no-referrer" srcdoc="<?php echo esc_attr($html); ?>"></iframe></div><?php
    }
}
