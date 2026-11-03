const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const { runInNewContext } = require('node:vm');

const script = readFileSync(join(__dirname, '../admin-next/pages/botwarden.js'), 'utf8');

function pageFor(confirm, post, get, globals = {}) {
  let Page;
  runInNewContext(script, {
    HTMLElement: class {},
    window: { __GRAV_DIALOGS: { confirm }, __GRAV_API: { post, get }, ...globals },
    customElements: { get() {}, define(tag, type) { Page = type; } },
  });
  const page = new Page();
  const button = { disabled: false };
  const message = { textContent: '', className: '' };
  const elements = { migrate: button, message };
  page.shadowRoot = { getElementById(id) {
    const element = elements[id] || (elements[id] = {});
    if (!element.addEventListener) {
      element.listeners = {};
      element.addEventListener = (type, handler) => { element.listeners[type] = handler; };
    }
    return element;
  } };
  const refresh = page.refresh.bind(page);
  let refreshes = 0;
  page.refresh = async () => { refreshes++; };
  return { page, button, message, elements, refresh, refreshes: () => refreshes };
}

test('settings uses Admin2 navigation with the configured base for an ordinary click', () => {
  for (const base of [undefined, '/subsite/control']) {
    const routes = [];
    const fixture = pageFor(undefined, undefined, undefined, {
      __GRAV_ADMIN_BASE: base, __GRAV_NAVIGATE: path => routes.push(path),
    });
    fixture.page.render();
    const link = fixture.elements.settings;
    const expected = base ? '/subsite/control/plugins/botwarden' : '/admin/plugins/botwarden';
    assert.equal(link.href, expected);
    let prevented = false;
    link.listeners.click({button: 0, preventDefault() { prevented = true; }});
    assert.equal(prevented, true);
    assert.deepEqual(routes, [expected]);
  }
});

test('modified and already-handled settings clicks retain normal link behavior', () => {
  let navigations = 0;
  const fixture = pageFor(undefined, undefined, undefined, {__GRAV_NAVIGATE: () => { navigations++; }});
  fixture.page.render();
  for (const options of [{ctrlKey: true}, {metaKey: true}, {shiftKey: true}, {altKey: true},
    {button: 1}, {button: 2}, {defaultPrevented: true}]) {
    fixture.elements.settings.listeners.click({button: 0, ...options,
      preventDefault() { assert.fail('Native link behavior must be preserved'); }});
  }
  assert.equal(navigations, 0);
  assert.equal(fixture.elements.settings.href, '/admin/plugins/botwarden');
});

test('settings retains a working link if the Admin2 navigation hook is unavailable', () => {
  const fixture = pageFor(undefined, undefined, undefined);
  fixture.page.render();
  fixture.elements.settings.listeners.click({button: 0,
    preventDefault() { assert.fail('The ordinary link must remain available'); }});
  assert.equal(fixture.elements.settings.href, '/admin/plugins/botwarden');
});

test('tables format Unix seconds as local dates without changing API rows or other cells', () => {
  const fixture = pageFor(undefined, undefined, undefined);
  const blocks = [{ip: '203.0.113.1', reason: '1700000000', added_at: 1700000000},
    {ip: '203.0.113.2', reason: '<script>untrusted</script>', added_at: null}];
  const history = [{ip: '203.0.113.1', action: 'block', reason: 'manual', acted_at: 1700003600},
    {ip: '203.0.113.2', action: 'unblock', reason: 'manual'}];
  const before = JSON.stringify([blocks, history]);
  fixture.page.table('blocks', ['IP', 'Reason', 'Added (local time)'], blocks, ['ip', 'reason', 'added_at']);
  fixture.page.table('history', ['IP', 'Action', 'Reason', 'Time (local time)'], history, ['ip', 'action', 'reason', 'acted_at']);
  const cells = id => Array.from(fixture.elements[id].innerHTML.matchAll(/<td>(.*?)<\/td>/g), match => match[1]);
  assert.deepEqual(cells('blocks'), ['203.0.113.1', '1700000000', new Date('2023-11-14T22:13:20Z').toLocaleString(),
    '203.0.113.2', '&lt;script&gt;untrusted&lt;/script&gt;', '']);
  assert.deepEqual(cells('history'), ['203.0.113.1', 'block', 'manual', new Date('2023-11-14T23:13:20Z').toLocaleString(),
    '203.0.113.2', 'unblock', 'manual', '']);
  assert.equal(JSON.stringify([blocks, history]), before);
});

test('cancelling migration sends no request and explains which installation remains unchanged', async () => {
  let dialog;
  let requests = 0;
  const fixture = pageFor(async options => { dialog = options; return false; }, async () => { requests++; });
  await fixture.page.migrate();
  assert.match(dialog.message, /botWarden will remain disabled/);
  assert.match(dialog.message, /GRVHulk will NOT be changed/);
  assert.equal(requests, 0);
  assert.equal(fixture.refreshes(), 0);
  assert.equal(fixture.button.disabled, false);
});

test('confirmed migration sends explicit confirmation once and reports its backup', async () => {
  let finish;
  let started;
  const requestStarted = new Promise(resolve => { started = resolve; });
  const requests = [];
  const fixture = pageFor(async () => true, (path, body) => {
    requests.push({ path, confirm: body.confirm });
    started();
    return new Promise(resolve => { finish = resolve; });
  });
  const operation = fixture.page.migrate();
  await requestStarted;
  assert.equal(fixture.button.disabled, true);
  assert.deepEqual(requests, [{ path: '/botwarden/migrate', confirm: true }]);
  finish({ backup_id: 'synthetic-backup' });
  await operation;
  assert.match(fixture.message.textContent, /backup synthetic-backup/);
  assert.match(fixture.message.textContent, /Disable GRVHulk before enabling botWarden/);
  assert.equal(fixture.message.className, '');
  assert.equal(fixture.refreshes(), 1);
  assert.equal(fixture.button.disabled, false);
});

test('failed migration exposes the error and restores the confirmation button', async () => {
  const fixture = pageFor(async () => true, async () => { throw new Error('Synthetic import failure'); });
  await fixture.page.migrate();
  assert.equal(fixture.message.textContent, 'Migration failed: Synthetic import failure');
  assert.equal(fixture.message.className, 'error');
  assert.equal(fixture.refreshes(), 0);
  assert.equal(fixture.button.disabled, false);
});

function dashboardFor(legacy, post, stats = {}) {
  return pageFor(async () => true, post, async path => {
    if (path === '/botwarden/legacy') return legacy;
    if (path === '/botwarden/status') return {
      local_count: 0, history_count: 0, abuseipdb_bulk_count: 0, dark_visitors_count: 0, ...stats,
    };
    return { entries: [], total: 0 };
  });
}

test('proxy warning displays as text and clears after configuration is corrected', async () => {
  const stats = { proxy_warning: 'Configure Trusted Proxies <script>untrusted</script>' };
  const fixture = dashboardFor({ migration_complete: true }, undefined, stats);
  await fixture.refresh();
  assert.equal(fixture.elements['proxy-warning'].hidden, false);
  assert.equal(fixture.elements['proxy-warning'].textContent, stats.proxy_warning);
  assert.equal(fixture.elements['proxy-warning'].innerHTML, undefined);
  stats.proxy_warning = null;
  await fixture.refresh();
  assert.equal(fixture.elements['proxy-warning'].hidden, true);
  assert.equal(fixture.elements['proxy-warning'].textContent, '');
});

test('dashboard hides the proxy warning when the API has none', async () => {
  const fixture = dashboardFor({ migration_complete: true });
  await fixture.refresh();
  assert.equal(fixture.elements['proxy-warning'].hidden, true);
  assert.equal(fixture.elements['proxy-warning'].textContent, '');
});

test('completed migration hides the notice even while legacy artifacts remain', async () => {
  const fixture = dashboardFor({ detected: true, can_migrate: false, migration_complete: true });
  await fixture.refresh();
  assert.equal(fixture.elements['legacy-migration'].hidden, true);
  assert.equal(fixture.button.hidden, true);
  assert.equal(fixture.message.textContent, '');
});

test('unfinished migrations keep their import or blocked-source notice visible', async () => {
  for (const can_migrate of [true, false]) {
    const fixture = dashboardFor({ detected: true, can_migrate, migration_complete: false });
    await fixture.refresh();
    assert.equal(fixture.elements['legacy-migration'].hidden, false);
    assert.equal(fixture.button.hidden, !can_migrate);
    assert.match(fixture.elements.legacy.textContent,
      can_migrate ? /Older GRVHulk configuration/ : /prevent automatic migration/);
    assert.equal(fixture.message.textContent, '');
  }
});

test('successful import hides the notice immediately and on later refreshes', async () => {
  const legacy = { detected: true, can_migrate: true, migration_complete: false };
  const fixture = dashboardFor(legacy, async () => {
    legacy.can_migrate = false;
    legacy.migration_complete = true;
    return { backup_id: 'synthetic-backup' };
  });
  fixture.page.refresh = fixture.refresh;
  await fixture.page.refresh();
  assert.equal(fixture.elements['legacy-migration'].hidden, false);
  await fixture.page.migrate();
  assert.equal(fixture.elements['legacy-migration'].hidden, true);
  assert.match(fixture.message.textContent, /backup synthetic-backup/);
  await fixture.page.refresh();
  assert.equal(fixture.elements['legacy-migration'].hidden, true);
});

test('history navigation and exact-IP search preserve the other pagination state', async () => {
  const calls = [];
  const fixture = pageFor(undefined, undefined, async (path, query) => {
    calls.push([path, query]);
    if (path.endsWith('/status')) return {};
    if (path.endsWith('/legacy')) return {migration_complete:true};
    return {entries:[], total:100, offset:query.offset};
  });
  fixture.page.render();
  fixture.page.offset = 50;
  fixture.elements['history-next'].listeners.click();
  await fixture.refresh();
  const query = path => calls.filter(([name]) => name.endsWith(path)).at(-1)[1];
  assert.equal(query('/history').offset, 25);
  assert.equal(query('/blocks').offset, 50);
  fixture.page.shadowRoot.getElementById('search').value = '8.8.8.8';
  fixture.elements.find.listeners.click();
  await fixture.refresh();
  assert.equal(query('/blocks').search, '8.8.8.8');
  assert.equal(query('/blocks').offset, 0);
  assert.equal(query('/history').offset, 25);
});

test('robots withdrawal previews as text and requires confirmation with its current hash', async () => {
  const calls = [];
  const fixture = pageFor(async () => true, async (path, body) => {
    calls.push({path, ...body}); return {content:'Owner <policy>', sha256:'current-hash'};
  });
  await fixture.page.withdrawRobots();
  assert.equal(fixture.elements['robots-preview'].textContent, 'Owner <policy>');
  assert.equal(fixture.elements['robots-preview'].innerHTML, undefined);
  assert.deepEqual(calls, [
    {path:'/botwarden/robots/withdraw', confirm:false},
    {path:'/botwarden/robots/withdraw', confirm:true, sha256:'current-hash'},
  ]);
  const cancelled = [];
  const other = pageFor(async () => false, async (path, body) => { cancelled.push(body.confirm); return {content:'Preview'}; });
  await other.page.withdrawRobots();
  assert.deepEqual(cancelled, [false]);
});


test('history pruning moves to an available page without resetting the local list', async () => {
  const offsets=[];
  const fixture=pageFor(undefined, undefined, async (path, query) => {
    if(path.endsWith('/status')) return {};
    if(path.endsWith('/legacy')) return {migration_complete:true};
    if(path.endsWith('/history')) { offsets.push(query.offset); return {entries:[], total:10, offset:query.offset}; }
    return {entries:[], total:100, offset:query.offset};
  });
  fixture.page.refresh=fixture.refresh;
  fixture.page.offset=50;
  fixture.page.historyOffset=50;
  await fixture.page.refresh();
  assert.deepEqual(offsets,[50,0]);
  assert.equal(fixture.page.offset,50);
  assert.equal(fixture.page.historyOffset,0);
  assert.equal(fixture.elements['history-prev'].disabled,true);
});
