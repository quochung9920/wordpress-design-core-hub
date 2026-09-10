# WordPress Design Core Hub

Browser-optimized Gutenberg build studio for ChatGPT-driven visual implementation.

The project is intentionally **not an MCP server**. Its primary workflow is a logged-in ChatGPT browser session using a compact WordPress admin screen to create and iterate on Gutenberg draft pages with very few browser actions.

## Goal

The target experience is:

1. Upload a reference HTML file to ChatGPT.
2. Tell ChatGPT to recreate it in WordPress with Gutenberg.
3. ChatGPT opens **Design Core Studio** in `wp-admin`.
4. It creates a draft, inspects the site design system/block registry when necessary, pastes semantic Gutenberg serialization plus page-scoped CSS, validates, and applies.
5. ChatGPT opens the sandboxed reference and WordPress draft, visually compares them, patches the build, and repeats.
6. Publishing remains a separate deliberate WordPress action.

## v0.1 scope

- Browser-first WordPress admin studio.
- Create new WordPress draft pages.
- Load existing pages for inspection.
- Draft-only build writes.
- Serialized Gutenberg block validation using WordPress' native parser and live block registry.
- Optional JSON Blueprint compiler for common native blocks.
- Page-scoped CSS for high-fidelity implementation without polluting other pages.
- Sandboxed temporary HTML reference preview.
- Live registered block catalog and schema-like metadata.
- Global design-system inspection through WordPress global settings/styles.
- Optimistic revision check before writes.
- Design Core build snapshots and rollback.
- No MCP, API key, shell, arbitrary PHP execution, or arbitrary SQL execution.

## Installation

Copy this repository to:

```text
wp-content/plugins/wordpress-design-core-hub
```

Activate **WordPress Design Core Hub** in WordPress. Activation grants the `design_core_hub_build` capability to the Administrator role.

Open:

```text
wp-admin/admin.php?page=design-core-hub
```

## Recommended ChatGPT browser prompt

```text
Use the attached HTML as the visual reference. Open Design Core Studio in the WordPress admin and recreate the page as a Gutenberg draft. Prefer semantic native Gutenberg blocks. Use page CSS for visual fidelity. Validate before every apply. Open both the sandboxed reference and draft frontend, compare desktop and mobile, then iterate until the draft matches the reference closely. Do not publish the page.
```

## Build modes

### Direct Gutenberg serialization (preferred)

ChatGPT can paste valid serialized Gutenberg content directly into the Studio. This is the most flexible v0.1 path.

```html
<!-- wp:group {"className":"hero"} -->
<div class="wp-block-group hero">
<!-- wp:heading {"level":1,"className":"hero-title"} -->
<h1 class="wp-block-heading hero-title">Engineering a better future.</h1>
<!-- /wp:heading -->
</div>
<!-- /wp:group -->
```

Page CSS is stored separately and printed only on that page.

### Blueprint JSON compiler

The deterministic compiler currently supports `group`, `columns`, `column`, `heading`, `paragraph`, `buttons`, `button`, `image`, `spacer`, `separator`, `list`, and `quote`.

For designs outside this schema, use direct Gutenberg serialization rather than falling back to `core/html`.

## Security model

- Build access requires both `design_core_hub_build` and `edit_pages`.
- REST calls use normal logged-in WordPress REST authentication and nonce protection from the admin UI.
- Apply/rollback operations only work on `draft` pages.
- Expected `post_modified_gmt` prevents overwriting a draft changed after ChatGPT loaded it.
- Builds are validated against the live WordPress block registry.
- `core/html` and `core/shortcode` are reported as fallback warnings.
- References are temporary (6 hours), tied to the current user, and displayed in a restrictive sandboxed iframe.
- Every apply and rollback snapshots both Gutenberg content and Design Core CSS.

## Architecture principle

WordPress stores block-editor content as serialized block markup in `post_content`. Design Core Hub validates that markup with WordPress' parser and runtime block registry before writing, keeping WordPress itself as the source of truth instead of maintaining a proprietary parallel page tree.

## Roadmap

The next useful increments are visual-iteration capabilities, not ACF or WooCommerce: media workflows, targeted block patching, richer native block helpers, reusable patterns, responsive QA helpers, and optional custom Gutenberg interaction blocks only where core blocks cannot preserve the reference design.

ACF, WooCommerce, Elementor, and other builders are intentionally outside the v0.1 focus.
