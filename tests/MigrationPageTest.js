const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const { runInNewContext } = require('node:vm');

const script = readFileSync(join(__dirname, '../admin-next/pages/botwarden.js'), 'utf8');

function pageFor(confirm, post, get) {
  let Page;
  runInNewContext(script, {
    HTMLElement: class {},
    window: { __GRAV_DIALOGS: { confirm }, __GRAV_API: { post, get } },
    customElements: { get() {}, define(tag, type) { Page = type; } },
  });
  const page = new Page();
  const button = { disabled: false };
  const message = { textContent: '', className: '' };
  const elements = { migrate: button, message };
  page.shadowRoot = { getElementById(id) { return elements[id] || (elements[id] = {}); } };
  const refresh = page.refresh.bind(page);
  let refreshes = 0;
  page.refresh = async () => { refreshes++; };
  return { page, button, message, elements, refresh, refreshes: () => refreshes };
}

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

function dashboardFor(legacy, post) {
  return pageFor(async () => true, post, async path => {
    if (path === '/botwarden/legacy') return legacy;
    if (path === '/botwarden/status') return {
      local_count: 0, history_count: 0, abuseipdb_bulk_count: 0, dark_visitors_count: 0,
    };
    return { entries: [], total: 0 };
  });
}

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

test('Known Agents connection sends one test request and reports acceptance independently of refresh', async () => {
  const requests = [];
  const fixture = pageFor(null, async (path, body) => {
    requests.push({ path, body });
    assert.equal(fixture.elements['connect-known-agents'].disabled, true);
    return { accepted: true };
  });
  await fixture.page.connectKnownAgents();
  assert.equal(requests.length, 1);
  assert.equal(requests[0].path, '/botwarden/known-agents/connect');
  assert.equal(Object.keys(requests[0].body).length, 0);
  assert.match(fixture.message.textContent, /accepted the test visit/);
  assert.match(fixture.message.textContent, /Regular visit reporting is controlled separately/);
  assert.equal(fixture.elements['connect-known-agents'].disabled, false);
  assert.equal(fixture.refreshes(), 0);
});

test('Known Agents connection failure is displayed and allows retrying', async () => {
  const fixture = pageFor(null, async () => { throw new Error('Known Agents visits HTTP 401'); });
  await fixture.page.connectKnownAgents();
  assert.match(fixture.message.textContent, /connection failed: Known Agents visits HTTP 401/);
  assert.equal(fixture.message.className, 'error');
  assert.equal(fixture.elements['connect-known-agents'].disabled, false);
});

test('Known Agents connection suppresses repeated clicks while its test visit is pending', async () => {
  let complete;
  let requests = 0;
  const fixture = pageFor(null, () => {
    requests++;
    return new Promise(resolve => { complete = resolve; });
  });
  const pending = fixture.page.connectKnownAgents();
  await fixture.page.connectKnownAgents();
  assert.equal(requests, 1);
  complete({ accepted: true });
  await pending;
  assert.equal(fixture.elements['connect-known-agents'].disabled, false);
});
