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
        <section><h3>Security statistics</h3><div id="stats">Loading…</div></section>
        <section id="legacy-migration" hidden><h3>GRVHulk migration</h3><div id="legacy">Checking…</div><button id="migrate" hidden>Import old settings and data</button></section>
        <section><h3>Local IP blacklist</h3>
          <label>IP <input id="ip" placeholder="203.0.113.7" autocomplete="off"></label>
          <label>Reason <input id="reason" placeholder="Manual block"></label>
          <button id="block">Block</button><button id="unblock">Unblock</button>
          <div id="blocks"></div><button id="prev" disabled>Previous</button><button id="next" disabled>Next</button>
        </section>
        <section><h3>Block history</h3><div id="history">Loading…</div></section>
        <p id="message" role="status" aria-live="polite"></p>`;
      this.shadowRoot.getElementById('settings').href =
        (window.__GRAV_ADMIN_BASE || '/admin') + '/plugins/botwarden';
      this.shadowRoot.getElementById('migrate').addEventListener('click', () => this.migrate());
      this.shadowRoot.getElementById('block').addEventListener('click', () => this.updateBlock(true));
      this.shadowRoot.getElementById('unblock').addEventListener('click', () => this.updateBlock(false));
      this.shadowRoot.getElementById('prev').addEventListener('click', () => { this.offset = Math.max(0, (this.offset || 0)-50); this.refresh(); });
      this.shadowRoot.getElementById('next').addEventListener('click', () => { this.offset = (this.offset || 0)+50; this.refresh(); });
    }
    async refresh() {
      if (!this.api) { this.message('Admin2 API client unavailable', true); return; }
      try {
        const [stats, legacy, blocks, history] = await Promise.all([
          this.api.get('/botwarden/status'),
          this.api.get('/botwarden/legacy'),
          this.api.get('/botwarden/blocks', {limit:50, offset:this.offset || 0}),
          this.api.get('/botwarden/history', {limit:25, offset:0})
        ]);
        this.shadowRoot.getElementById('stats').textContent =
          `Local: ${stats.local_count}; historical events: ${stats.history_count}; AbuseIPDB: ${stats.abuseipdb_bulk_count}; Known Agents: ${stats.dark_visitors_count}`;
        this.shadowRoot.getElementById('legacy-migration').hidden = !!legacy.migration_complete;
        this.shadowRoot.getElementById('legacy').textContent = legacy.detected
          ? (legacy.can_migrate
            ? 'Older GRVHulk configuration/database detected. Import makes a backup and leaves botWarden disabled. GRVHulk remains unchanged.'
            : 'GRVHulk artifacts found, but existing botWarden data or missing readable sources prevent automatic migration.')
          : 'No older GRVHulk installation detected.';
        this.shadowRoot.getElementById('migrate').hidden = !legacy.can_migrate;
        this.table('blocks', ['IP','Reason','Added'], blocks.entries || [], ['ip','reason','added_at']);
        this.table('history', ['IP','Action','Reason','Time'], history.entries || [], ['ip','action','reason','acted_at']);
        this.shadowRoot.getElementById('prev').disabled = !this.offset;
        this.shadowRoot.getElementById('next').disabled = (this.offset || 0)+50 >= blocks.total;
      } catch (e) { this.message('Unable to load botWarden: ' + e.message, true); }
    }
    table(id, headings, entries, keys) {
      this.shadowRoot.getElementById(id).innerHTML = entries.length
        ? '<table><thead><tr>' + headings.map(x => '<th>' + this.esc(x) + '</th>').join('') +
          '</tr></thead><tbody>' + entries.map(row => '<tr>' + keys.map(key =>
            '<td>' + this.esc(row[key]) + '</td>').join('') + '</tr>').join('') + '</tbody></table>'
        : '<p>No entries.</p>';
    }
    message(text, isError=false) {
      const el=this.shadowRoot.getElementById('message');
      el.textContent=text; el.className=isError?'error':'';
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