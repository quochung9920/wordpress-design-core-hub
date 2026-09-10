(function () {
    'use strict';

    const root = document.getElementById('dch-hub');
    if (!root || typeof DCH_HUB === 'undefined') return;

    const $ = (selector) => root.querySelector(selector);
    const banner = $('#dch-status-banner');

    function setBanner(message, type) {
        banner.textContent = message;
        banner.className = 'dch-status ' + (type ? 'is-' + type : '');
    }

    async function request(path, options) {
        const settings = Object.assign({ method: 'GET', credentials: 'same-origin' }, options || {});
        settings.headers = Object.assign({ 'X-WP-Nonce': DCH_HUB.nonce }, settings.headers || {});
        if (settings.body && typeof settings.body !== 'string') {
            settings.headers['Content-Type'] = 'application/json';
            settings.body = JSON.stringify(settings.body);
        }

        const response = await fetch(DCH_HUB.restBase + path, settings);
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.success === false) {
            const error = payload.error && payload.error.message ? payload.error.message : ('Request failed with HTTP ' + response.status);
            throw new Error(error);
        }
        return payload.data || {};
    }

    function settingsPayload() {
        return {
            owner: $('#dch-owner').value.trim(),
            repo: $('#dch-repo').value.trim(),
            branch: $('#dch-branch').value.trim(),
            build_root: $('#dch-build-root').value.trim(),
            site_key: $('#dch-site-key-input').value.trim(),
            enabled: $('#dch-enabled').checked,
            public_trigger: $('#dch-public-trigger').checked
        };
    }

    function renderSettings(data) {
        const settings = data.settings || {};
        $('#dch-owner').value = settings.owner || '';
        $('#dch-repo').value = settings.repo || '';
        $('#dch-branch').value = settings.branch || '';
        $('#dch-build-root').value = settings.build_root || '';
        $('#dch-site-key-input').value = settings.site_key || '';
        $('#dch-enabled').checked = !!settings.enabled;
        $('#dch-public-trigger').checked = !!settings.public_trigger;
        $('#dch-site-key').textContent = settings.site_key || '—';
        $('#dch-latest-path').textContent = data.latest_path || '—';
        $('#dch-public-status-url').value = data.status_url || '';
        $('#dch-public-sync-url').value = data.public_sync_url || '';
        $('#dch-auto-sync-state').textContent = settings.enabled ? 'Enabled' : 'Disabled';
        $('#dch-next-cron').textContent = 'Next cron (GMT): ' + (data.next_cron_gmt || 'not scheduled');
        if (data.status) renderStatus(data.status);
    }

    function renderStatus(status) {
        $('#dch-sync-output').textContent = JSON.stringify(status, null, 2);
        const type = status.state === 'success' || status.state === 'no_change' ? 'success' : (status.state === 'error' ? 'error' : 'info');
        setBanner((status.state || 'status') + ': ' + (status.message || ''), type);
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderDrafts(data) {
        const drafts = data.drafts || [];
        if (!drafts.length) {
            $('#dch-drafts').innerHTML = '<p>No Design Core managed drafts found.</p>';
            return;
        }

        const rows = drafts.map((draft) => `
            <tr>
                <td>${escapeHtml(draft.id)}</td>
                <td><strong>${escapeHtml(draft.title)}</strong><br><code>${escapeHtml(draft.slug)}</code></td>
                <td><code>${escapeHtml(draft.build_id || 'local/unbuilt')}</code></td>
                <td>${escapeHtml(draft.modified_gmt)}</td>
                <td class="dch-row-actions">
                    ${draft.preview_url ? `<a class="button button-small" href="${escapeHtml(draft.preview_url)}" target="_blank" rel="noopener">Preview</a>` : ''}
                    ${draft.edit_url ? `<a class="button button-small" href="${escapeHtml(draft.edit_url)}">Edit</a>` : ''}
                </td>
            </tr>
        `).join('');

        $('#dch-drafts').innerHTML = `
            <table class="widefat striped">
                <thead><tr><th>ID</th><th>Draft</th><th>Build</th><th>Modified GMT</th><th></th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    async function loadSettings() {
        try {
            const data = await request('/settings');
            renderSettings(data);
        } catch (error) {
            setBanner(error.message, 'error');
        }
    }

    async function loadDrafts() {
        try {
            renderDrafts(await request('/managed-drafts'));
        } catch (error) {
            setBanner(error.message, 'error');
        }
    }

    $('#dch-save-settings').addEventListener('click', async function () {
        setBanner('Saving settings…', 'info');
        try {
            await request('/settings', { method: 'POST', body: { settings: settingsPayload() } });
            await loadSettings();
            setBanner('Settings saved.', 'success');
        } catch (error) {
            setBanner(error.message, 'error');
        }
    });

    $('#dch-sync-now').addEventListener('click', async function () {
        setBanner('Fetching the latest GitHub build…', 'info');
        try {
            const result = await request('/sync', { method: 'POST', body: {} });
            renderStatus(result);
            await loadDrafts();
        } catch (error) {
            setBanner(error.message, 'error');
        }
    });

    $('#dch-refresh').addEventListener('click', loadSettings);
    $('#dch-refresh-drafts').addEventListener('click', loadDrafts);

    root.addEventListener('click', async function (event) {
        const button = event.target.closest('.dch-copy');
        if (!button) return;
        const input = $(button.getAttribute('data-copy'));
        if (!input) return;
        try {
            await navigator.clipboard.writeText(input.value);
            setBanner('Copied to clipboard.', 'success');
        } catch (error) {
            input.select();
            document.execCommand('copy');
            setBanner('Copied to clipboard.', 'success');
        }
    });

    $('#dch-copy-chat-prompt').addEventListener('click', async function () {
        const siteKey = $('#dch-site-key').textContent.trim();
        const latestPath = $('#dch-latest-path').textContent.trim();
        const statusUrl = $('#dch-public-status-url').value;
        const syncUrl = $('#dch-public-sync-url').value;
        const repo = $('#dch-owner').value.trim() + '/' + $('#dch-repo').value.trim();
        const branch = $('#dch-branch').value.trim();
        const prompt = [
            'Use WordPress Design Core Hub for this site.',
            'GitHub repository: ' + repo,
            'Branch: ' + branch,
            'Site key: ' + siteKey,
            'Latest pointer path: ' + latestPath,
            'Status URL: ' + statusUrl,
            'Sync URL: ' + syncUrl,
            '',
            'For every build: write immutable build files first, write manifest.json after them, then update latest.json LAST. Trigger the sync URL and verify the status URL. Only create Gutenberg draft builds; do not publish.'
        ].join('\n');
        try {
            await navigator.clipboard.writeText(prompt);
            setBanner('ChatGPT setup prompt copied.', 'success');
        } catch (error) {
            setBanner('Could not access the clipboard. Copy the endpoint fields manually.', 'error');
        }
    });

    loadSettings();
    loadDrafts();
})();
