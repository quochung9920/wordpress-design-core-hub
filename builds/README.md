# Design Core Hub build inbox protocol

This directory is the transport between ChatGPT Chat and WordPress Design Core Hub.

## Directory layout

For a site key such as `example-com`:

```text
builds/example-com/
├── latest.json
└── 20260910-homepage-001/
    ├── manifest.json
    ├── blocks.html
    └── styles.css
```

Build directories are immutable. To change a design, create a new build ID instead of editing a build that WordPress may already have applied.

## Required write order

Always commit/write in this order:

```text
1. blocks.html
2. styles.css
3. manifest.json
4. latest.json
```

`latest.json` MUST be written last. This keeps the pointer atomic from WordPress' perspective.

## `blocks.html`

Contains normal serialized Gutenberg content, for example:

```html
<!-- wp:group {"className":"hero"} -->
<div class="wp-block-group hero">
<!-- wp:heading {"level":1,"className":"hero-title"} -->
<h1 class="wp-block-heading hero-title">Engineering a better future.</h1>
<!-- /wp:heading -->
</div>
<!-- /wp:group -->
```

The remote validator requires all referenced block types to be registered on the target WordPress site.

The default policy also rejects freeform HTML, `core/html`, and `core/shortcode`.

## `styles.css`

Optional page CSS. It is loaded only on the Design Core managed page.

Do not include `<style>` tags.

## `manifest.json`

Schema:

```json
{
  "schema": "design-core-hub/build@1",
  "build_id": "20260910-homepage-001",
  "target": {
    "post_type": "page",
    "operation": "upsert_draft",
    "title": "Homepage AI Draft",
    "slug": "homepage-ai-draft"
  },
  "files": {
    "content": {
      "path": "builds/example-com/20260910-homepage-001/blocks.html",
      "sha256": "<sha256 of exact blocks.html bytes>"
    },
    "css": {
      "path": "builds/example-com/20260910-homepage-001/styles.css",
      "sha256": "<sha256 of exact styles.css bytes>"
    }
  },
  "policy": {
    "require_semantic_blocks": true
  },
  "source": {
    "kind": "chatgpt",
    "reference": "homepage.html",
    "note": "Recreated from supplied HTML reference"
  }
}
```

`files.css` may be omitted when no CSS is needed.

### Optional conflict guard

A later build may add:

```json
"expected_previous_build_id": "20260910-homepage-001"
```

If the managed draft is not currently on that build, WordPress refuses the new build instead of silently branching from unexpected state.

## `latest.json`

Schema:

```json
{
  "schema": "design-core-hub/latest@1",
  "build_id": "20260910-homepage-001",
  "manifest_path": "builds/example-com/20260910-homepage-001/manifest.json",
  "manifest_sha256": "<sha256 of exact manifest.json bytes>"
}
```

The `manifest_path` is intentionally constrained to:

```text
<build-root>/<site-key>/<build-id>/manifest.json
```

## Hashing

All SHA-256 values are lowercase hexadecimal hashes of the **exact bytes committed to GitHub**.

Pseudo workflow:

```text
content_hash  = sha256(blocks.html)
css_hash      = sha256(styles.css)
manifest.json = create manifest containing those hashes
manifest_hash = sha256(manifest.json)
latest.json   = create latest pointer containing manifest_hash
```

## ChatGPT completion check

After `latest.json` is committed, request:

```text
https://YOUR-SITE/wp-json/design-core-hub/v1/remote-sync
```

Then read:

```text
https://YOUR-SITE/wp-json/design-core-hub/v1/remote-status
```

A successful status includes the applied `build_id`, managed draft page ID/slug, block count, and validation warnings.

## Security note

This repository is public by default. Build files therefore must not contain secrets, credentials, private customer data, API keys, or unpublished confidential material.
