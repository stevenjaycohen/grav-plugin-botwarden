const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const { runInNewContext } = require('node:vm');

const script = readFileSync(join(__dirname, '../admin-next/pages/botwarden.js'), 'utf8');

function pageFor(confirm, post) {
  let Page;
  runInNewContext(script, {
    HTMLElement: class {},
    window: { __GRAV_DIALOGS: { confirm }, __GRAV_API: { post } },
    customElements: { get() {}, define(tag, type) { Page = type; } },
  });
  const page = new Page();
  const button = { disabled: false };
  const message = { textContent: '', className: '' };
  page.shadowRoot = { getElementById(id) { return id === 'migrate' ? button : message; } };
  let refreshes = 0;
  page.refresh = async () => { refreshes++; };
  return { page, button, message, refreshes: () => refreshes };
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
