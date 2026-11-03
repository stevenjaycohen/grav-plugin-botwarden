(function () {
  'use strict';
  const TAG = window.__GRAV_PAGE_TAG || 'grav-botwarden--page';
  class BotwardenPage extends HTMLElement {
    connectedCallback() {
      this.attachShadow({mode: 'open'});
      this.render();
      this.refresh();
    }
    get api() { return window.__GRAV_API; }
    esc(value) { return String(value == null ? '' : value).replace(/[&<>"']/g,
      c => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[c])); }
    render() {
      this.shadowRoot.innerHTML = `
        <style>
          :host { display:block; color:var(--foreground,inherit); font-family:inherit; }
          section { border:1px solid var(--border,#bbb); border-radius:8px; padding:1rem; margin:1rem 0; }
          table { border-collapse:collapse; width:100%; }
          th,td { text-align:left; padding:.45rem; border-bottom:1px solid var(--border,#ddd); }
          input { padding:.5rem; margin:.3rem; background:var(--background,#fff); color:inherit; border:1px solid var(--border,#bbb); }
          button { padding:.5rem .8rem; margin:.2rem; cursor:pointer; }
          .warning { font-weight:600; } .error { color:#a52a2a; white-space:pre-wrap; }
        </style>
        <h2>botWarden</h2>
        <p>Application-layer request security and crawler policies. <a id="settings" href="#">Plugin settings</a></p>
        <p id="proxy-warning" class="warning" role="status" hidden></p>
        <section><h3>Security statistics</h3><div id="stats">Loading…</div><div id="integrations"></div><p id="delivery"></p>
          <button id="refresh-abuseipdb">Refresh AbuseIPDB</button><button id="refresh-agents">Check Known Agents freshness</button>
          <button id="withdraw-robots">Preview robots.txt policy removal</button><pre id="robots-preview" hidden></pre></section>
        <section id="legacy-migration" hidden><h3>GRVHulk migration</h3><div id="legacy">Checking…</div><button id="migrate" hidden>Import old settings and data</button></section>
        <section><h3>Local IP blacklist</h3>
          <label>IP <input id="ip" placeholder="203.0.113.7" autocomplete="off"></label>
          <label>Reason <input id="reason" placeholder="Manual block"></label>
          <button id="block">Block</button><button id="unblock">Unblock</button>
          <p>Removal affects local blocks only; other enabled sources may still block the address.</p><label>Find exact IP <input id="search"></label><button id="find">Find</button><button id="clear-search">Clear search</button><div id="blocks"></div><button id="prev" disabled>Previous</button><button id="next" disabled>Next</button>
        </section>
        <section><h3>Block history</h3><div id="history">Loading…</div><button id="history-prev" disabled>Previous history</button><button id="history-next" disabled>Next history</button></section>
        <p id="message" role="status" aria-live="polite"></p>`;
      const settings = this.shadowRoot.getElementById('settings');
      const settingsPath = (window.__GRAV_ADMIN_BASE || '/admin') + '/plugins/botwarden';
      settings.href = settingsPath;
      settings.addEventListener('click', event => {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
          || typeof window.__GRAV_NAVIGATE !== 'function') return;
        event.preventDefault();
        window.__GRAV_NAVIGATE(settingsPath);
      });
      this.shadowRoot.getElementById('migrate').addEventListener('click', () => this.migrate());
      this.shadowRoot.getElementById('block').addEventListener('click', () => this.updateBlock(true));
      this.shadowRoot.getElementById('unblock').addEventListener('click', () => this.updateBlock(false));
      this.shadowRoot.getElementById('prev').addEventListener('click', () => { this.offset = Math.max(0, (this.offset || 0)-50); this.refresh(); });
      this.shadowRoot.getElementById('next').addEventListener('click', () => { this.offset = (this.offset || 0)+50; this.refresh(); });
      this.shadowRoot.getElementById('history-prev').addEventListener('click', () => { this.historyOffset = Math.max(0, (this.historyOffset || 0)-25); this.refresh(); });
      this.shadowRoot.getElementById('history-next').addEventListener('click', () => { this.historyOffset = (this.historyOffset || 0)+25; this.refresh(); });
      this.shadowRoot.getElementById('find').addEventListener('click', () => {
        this.search = this.shadowRoot.getElementById('search').value.trim(); this.offset = 0; this.refresh();
      });
      this.shadowRoot.getElementById('clear-search').addEventListener('click', () => {
        this.search = ''; this.shadowRoot.getElementById('search').value = ''; this.offset = 0; this.refresh();
      });
      this.shadowRoot.getElementById('refresh-abuseipdb').addEventListener('click', () => this.refreshIntegration('abuseipdb_bulk'));
      this.shadowRoot.getElementById('refresh-agents').addEventListener('click', () => this.refreshIntegration('dark_visitors'));
      this.shadowRoot.getElementById('withdraw-robots').addEventListener('click', () => this.withdrawRobots());
    }
    async refresh() {
      if (!this.api) { this.message('Admin2 API client unavailable', true); return; }
      try {
        const [stats, legacy, blocks, history] = await Promise.all([
          this.api.get('/botwarden/status'),
          this.api.get('/botwarden/legacy'),
          this.api.get('/botwarden/blocks', {limit:50, offset:this.offset || 0, search:this.search || ''}),
          this.api.get('/botwarden/history', {limit:25, offset:this.historyOffset || 0})
        ]);
        const blockOffset = blocks.offset ?? this.offset ?? 0;
        const historyOffset = history.offset ?? this.historyOffset ?? 0;
        if ((blockOffset > 0 && blockOffset >= blocks.total) || (historyOffset > 0 && historyOffset >= history.total)) {
          this.offset = blocks.total ? Math.min(blockOffset, Math.floor((blocks.total - 1) / 50) * 50) : 0;
          this.historyOffset = history.total ? Math.min(historyOffset, Math.floor((history.total - 1) / 25) * 25) : 0;
          // Reload once at the last available page after removals or retention.
          return this.refresh();
        }
        this.shadowRoot.getElementById('stats').textContent =
          `Local: ${stats.local_count} stored rows; historical events: ${stats.history_count}; AbuseIPDB: ${stats.abuseipdb_bulk_count}; Known Agents: ${stats.dark_visitors_count}`;
        const proxyWarning = this.shadowRoot.getElementById('proxy-warning');
        proxyWarning.textContent = stats.proxy_warning || '';
        proxyWarning.hidden = !stats.proxy_warning;
        const integrations = stats.integrations || {};
        this.shadowRoot.getElementById('integrations').textContent = Object.entries(integrations).map(([name, state]) =>
          name + ': ' + state.state + (state.last_success ? '; last success ' + new Date(state.last_success * 1000).toLocaleString() : '') + (state.error ? '; ' + state.error : '')).join(' | ');
        this.shadowRoot.getElementById('refresh-abuseipdb').disabled = !integrations.abuseipdb_bulk?.can_refresh;
        this.shadowRoot.getElementById('refresh-agents').disabled = !integrations.dark_visitors?.can_refresh;
        this.shadowRoot.getElementById('delivery').textContent = 'Known Agents reporting: ' + (stats.visit_reporting || 'disabled') + '. ' +
          Object.entries(stats.delivery || {}).map(([key, value]) => {
            const labels = {reports_sent:'Reports sent', reports_failed:'Report failures', reports_dropped:'Reports dropped',
              reports_expired:'Reports expired', reports_not_queued:'Reports deduplicated or rejected', reports_retry_after:'Reports resume',
              visits_accepted:'Visits accepted by API', visits_failed:'Visit failures', visits_last_attempt:'Last visit attempt'};
            const timestamp = key === 'reports_retry_after' || key === 'visits_last_attempt';
            return (labels[key] || key) + ': ' + (timestamp ? (value ? new Date(value * 1000).toLocaleString() : '—') : value);
          }).join('; ');
        if ((stats.configuration_errors || []).length) this.message(stats.configuration_errors.join(' '), true);
        this.offset = blocks.offset ?? this.offset ?? 0;
        this.historyOffset = history.offset ?? this.historyOffset ?? 0;
        this.shadowRoot.getElementById('history-prev').disabled = !this.historyOffset;
        this.shadowRoot.getElementById('history-next').disabled = this.historyOffset + 25 >= history.total;
        this.shadowRoot.getElementById('legacy-migration').hidden = !!legacy.migration_complete;
        this.shadowRoot.getElementById('legacy').textContent = legacy.detected
          ? (legacy.can_migrate
            ? 'Older GRVHulk configuration/database detected. Import makes a backup and leaves botWarden disabled. GRVHulk remains unchanged.'
            : 'GRVHulk artifacts found. ' + (legacy.recovery || ['Existing destinations or unreadable sources prevent automatic migration.']).join(' '))
          : 'No older GRVHulk installation detected.';
        this.shadowRoot.getElementById('migrate').hidden = !legacy.can_migrate;
        this.table('blocks', ['IP','Reason','Type','Status','Added (local time)'], (blocks.entries || []).map(row => ({...row, block_type: row.is_manual ? 'Manual' : 'Automatic', block_status: row.is_active === false ? 'Expired' : 'Active'})), ['ip','reason','block_type','block_status','added_at']);
        this.table('history', ['IP','Action','Reason','Time (local time)'], history.entries || [], ['ip','action','reason','acted_at']);
        this.shadowRoot.getElementById('prev').disabled = !this.offset;
        this.shadowRoot.getElementById('next').disabled = (this.offset || 0)+50 >= blocks.total;
      } catch (e) { this.message('Unable to load botWarden: ' + e.message, true); }
    }
    table(id, headings, entries, keys) {
      this.shadowRoot.getElementById(id).innerHTML = entries.length
        ? '<table><thead><tr>' + headings.map(x => '<th>' + this.esc(x) + '</th>').join('') +
          '</tr></thead><tbody>' + entries.map(row => '<tr>' + keys.map(key =>
            '<td>' + this.esc((key === 'added_at' || key === 'acted_at') && row[key] != null
              ? new Date(Number(row[key]) * 1000).toLocaleString() : row[key]) + '</td>').join('') + '</tr>').join('') + '</tbody></table>'
        : '<p>No entries.</p>';
    }
    message(text, isError=false) {
      const el=this.shadowRoot.getElementById('message');
      el.textContent=text; el.className=isError?'error':'';
    }
    async refreshIntegration(source) {
      try {
        const result = await this.api.post('/botwarden/refresh', {source});
        this.message(result.message, !result.success);
        await this.refresh();
      } catch (error) { this.message(error.message, true); }
    }
    async withdrawRobots() {
      try {
        const preview = await this.api.post('/botwarden/robots/withdraw', {confirm:false});
        const element = this.shadowRoot.getElementById('robots-preview');
        element.textContent = preview.content; element.hidden = false;
        const confirmed = await window.__GRAV_DIALOGS?.confirm({title:'Remove botWarden robots.txt policy?',
          message:'Review the preview below. Remove only the marked botWarden block? Owner content and backups are retained.', confirmLabel:'Remove policy'});
        if (!confirmed) return;
        await this.api.post('/botwarden/robots/withdraw', {confirm:true, sha256:preview.sha256});
        this.message('botWarden policy removed; backups retained.');
      } catch (error) { this.message(error.message, true); }
    }
    async migrate() {
      const ok = await window.__GRAV_DIALOGS?.confirm({
        title:'Import from GRVHulk?',
        message:'Back up and import legacy settings/SQLite data? botWarden will remain disabled and GRVHulk will NOT be changed.',
        confirmLabel:'Import',
      });
      if (!ok) return;
      try {
        this.shadowRoot.getElementById('migrate').disabled=true;
        const result = await this.api.post('/botwarden/migrate', {confirm:true});
        this.message('Imported legacy data, backup ' + result.backup_id + '. Disable GRVHulk before enabling botWarden.');
        await this.refresh();
      } catch(e) { this.message('Migration failed: '+e.message, true); }
      finally { this.shadowRoot.getElementById('migrate').disabled=false; }
    }
    async updateBlock(add) {
      const ip=this.shadowRoot.getElementById('ip').value.trim();
      if (!ip) { this.message('Enter an IP address.', true); return; }
      if (!add) {
        const ok=await window.__GRAV_DIALOGS?.confirm({title:'Unblock IP?',message:'Remove ' + ip + ' from the local blacklist?'});
        if (!ok) return;
      }
      try {
        const endpoint=add?'/botwarden/blocks':'/botwarden/unblock';
        await this.api.post(endpoint, {ip, reason:this.shadowRoot.getElementById('reason').value});
        this.message((add?'Blocked ':'Unblocked ') + ip);
        await this.refresh();
      } catch(e) { this.message('Operation failed: '+e.message, true); }
    }
  }
  if (!customElements.get(TAG)) customElements.define(TAG, BotwardenPage);
})();
