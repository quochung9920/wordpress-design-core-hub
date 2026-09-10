# WordPress Design Core Hub

**GitHub-driven Gutenberg build agent for ChatGPT Chat.**

Design Core Hub lets a normal ChatGPT conversation create and iterate on WordPress Gutenberg draft pages without MCP and without ChatGPT Work/browser automation.

The control path is intentionally simple:

```text
ChatGPT Chat
   ↓
GitHub build inbox
   ↓
WordPress Design Core Hub
   ↓
Validate integrity + Gutenberg structure
   ↓
Managed WordPress draft
```

## What changed in v0.2

v0.1 was a browser-oriented wp-admin studio. v0.2 makes **GitHub the primary control transport** and keeps wp-admin only for setup, monitoring, manual sync, and rollback/debugging.

A ChatGPT conversation can now:

1. Read an uploaded HTML/CSS reference.
2. Generate semantic Gutenberg block serialization plus page CSS.
3. Commit an immutable build bundle to this repository.
4. Update `latest.json` last.
5. Call the site's public draft-only sync URL.
6. Read the site's public sync-status URL to verify whether the build was accepted.

No WordPress credentials, MCP server, OpenAI API key, or GitHub token needs to be sent to ChatGPT for this workflow.

## Installation

Download this repository as a ZIP and install it in WordPress:

```text
Plugins → Add New Plugin → Upload Plugin
```

Activate **WordPress Design Core Hub**.

On activation the plugin:

- grants `design_core_hub_build` to Administrators;
- creates safe default remote settings;
- derives a site key from the WordPress home URL;
- schedules a GitHub inbox check every five minutes through WP-Cron.

Then open:

```text
wp-admin/admin.php?page=design-core-hub
```

The admin screen shows the site's exact GitHub inbox path and the two public chat-only URLs:

```text
/wp-json/design-core-hub/v1/remote-status
/wp-json/design-core-hub/v1/remote-sync
```

## Default repository configuration

```text
Owner:      quochung9920
Repository: wordpress-design-core-hub
Branch:     main
Build root: builds
Site key:   derived from the WordPress domain
```

For `https://example.com`, the default pointer is:

```text
builds/example-com/latest.json
```

The settings are editable from **Design Core → GitHub build inbox**.

## ChatGPT workflow

After initial WordPress installation, a normal chat can be given:

- the WordPress site URL;
- an HTML reference file;
- the GitHub repository (already connected to ChatGPT in the intended workflow).

A useful instruction is:

```text
Recreate the attached HTML on https://example.com using WordPress Design Core Hub.
Use semantic registered Gutenberg blocks and page-scoped CSS. Do not use core/html or freeform HTML.
Write the immutable build bundle under builds/example-com/<build_id>/, then update builds/example-com/latest.json LAST.
Trigger https://example.com/wp-json/design-core-hub/v1/remote-sync and verify https://example.com/wp-json/design-core-hub/v1/remote-status.
Only create/update the Design Core managed draft. Never publish it.
```

See [`builds/README.md`](builds/README.md) for the exact build protocol.

## Build bundle

Every build is immutable:

```text
builds/<site-key>/
├── latest.json
└── <build-id>/
    ├── manifest.json
    ├── blocks.html
    └── styles.css
```

The write order matters:

```text
1. blocks.html
2. styles.css
3. manifest.json
4. latest.json   ← ALWAYS LAST
```

`latest.json` is the atomic pointer. WordPress never scans arbitrary commits or partially uploaded directories.

## Safety model

### Trusted source

The configured GitHub repository and branch are the only build source. v0.2 supports a **public GitHub repository**, so WordPress stores no GitHub access token.

### Integrity

`latest.json` contains the SHA-256 of `manifest.json`. The manifest contains the SHA-256 of `blocks.html` and `styles.css`. WordPress verifies every hash before parsing or applying anything.

### Draft-only writes

Remote sync can only create or update a page that:

- is a WordPress `page`;
- is in `draft` status;
- carries Design Core management metadata;
- matches the deterministic remote target key for the configured site and manifest slug.

It cannot attach itself to an arbitrary existing page and it refuses published targets.

### Gutenberg validation

Before a build is applied, Design Core Hub parses the content with WordPress' native block parser and checks every block against the site's live `WP_Block_Type_Registry`.

By default, the remote manifest enables semantic-only policy, which rejects:

- freeform markup outside blocks;
- `core/html`;
- `core/shortcode`.

Registered native or installed third-party Gutenberg blocks are allowed.

### No remote code execution surface

The remote build protocol does **not** support:

- PHP execution;
- SQL;
- shell commands;
- arbitrary WordPress options;
- plugin/theme installation;
- PHP/theme file editing;
- publishing;
- user management.

The public sync endpoint accepts no page content. It only asks WordPress to fetch the current trusted GitHub pointer.

### Public trigger

The public trigger exists so ChatGPT Chat can apply a build without a logged-in WordPress browser session. It is globally rate-limited and can be disabled in wp-admin. Even when enabled, it remains draft-only and can only pull from the configured GitHub inbox.

## WP-Cron

Design Core Hub checks GitHub every five minutes. WordPress WP-Cron is traffic-driven, so an exact five-minute delivery is not guaranteed on low-traffic sites. The public `/remote-sync` endpoint provides an immediate chat-driven trigger when needed.

## Admin UI

The v0.2 admin page is intentionally a monitoring/debug surface rather than the primary builder. It provides:

- site key and expected `latest.json` path;
- public status/sync URLs;
- GitHub owner/repository/branch/build-root settings;
- enable/disable switches;
- manual sync;
- last sync details;
- managed draft listing;
- history data/rollback through the authenticated REST controller.

## Page CSS

Build CSS is stored in `_dch_page_css` and printed only on the managed page, so a high-fidelity ChatGPT build can use detailed CSS without globally polluting unrelated pages.

Managed pages receive body classes such as:

```text
dch-managed-page
dch-page-123
dch-target-homepage-ai-draft
```

## Current scope

v0.2 stays focused on visual Gutenberg page creation:

- Gutenberg block content;
- page-specific CSS;
- site block registry inspection;
- global design-system inspection;
- draft creation/update;
- integrity validation;
- build history/rollback;
- GitHub inbox synchronization.

Not in scope yet:

- ACF;
- WooCommerce;
- Elementor;
- arbitrary WordPress administration;
- automatic screenshot/visual-diff infrastructure;
- automatic publishing.

## Visual QA limitation

The GitHub transport solves **creation and iteration from ChatGPT Chat**, but a normal text/web chat does not automatically provide the same interactive visual browser QA loop as ChatGPT Work/Computer Use.

A future release can add a separate screenshot runner/visual QA adapter. Until then, visual feedback can come from screenshots supplied in chat or another approved rendering service. This limitation does not affect automated GitHub → Gutenberg draft delivery.

## Version

Current plugin version: **0.2.0**
