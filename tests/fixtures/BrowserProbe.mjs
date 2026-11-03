// Real browser metadata; only the disposable loopback origins are permitted.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const [source, action] = process.argv.slice(2);
const sources = {
    'cross-site': 'http://localhost:8777/',
    'same-site': 'http://127.0.0.1:8778/',
    'same-origin': 'http://127.0.0.1:8777/',
    none: null,
    dashboard: null,
};
assert.ok(Object.hasOwn(sources, source));
assert.ok(['image', 'link', 'navigate', 'dashboard'].includes(action));
assert.equal(action === 'navigate', source === 'none');
assert.equal(process.env.BOTWARDEN_BROWSER_URL, 'http://127.0.0.1:8877');

const endpoint = process.env.BOTWARDEN_BROWSER_URL;
const target = source === 'dashboard' ? 'http://127.0.0.1:8777/admin/plugin/botwarden' : 'http://127.0.0.1:8777/.env';
const page = await (await fetch(`${endpoint}/json/new?about:blank`, {method: 'PUT'})).json();
assert.ok(page.webSocketDebuggerUrl.startsWith('ws://127.0.0.1:8877/'));
const socket = new WebSocket(page.webSocketDebuggerUrl);
const pending = new Map();
const listeners = new Set();
let sequence = 0;
let closing = false;

function command(method, params = {}) {
    if(closing) return Promise.resolve({});
    return new Promise((resolve, reject) => {
        const id = ++sequence;
        const timeout = setTimeout(() => {
            pending.delete(id);
            reject(new Error(`Timed out: ${method}`));
        }, action === 'dashboard' ? 20000 : 10000);
        pending.set(id, {resolve, reject, timeout});
        socket.send(JSON.stringify({id, method, params}));
    });
}

function event(method, matches) {
    return new Promise((resolve, reject) => {
        const listener = message => {
            if (message.method === method && matches(message.params)) {
                clearTimeout(timeout);
                listeners.delete(listener);
                resolve(message.params);
            }
        };
        const timeout = setTimeout(() => {
            listeners.delete(listener);
            reject(new Error(`Timed out waiting for ${method}`));
        }, 10000);
        listeners.add(listener);
    });
}

socket.addEventListener('message', message => {
    const data = JSON.parse(message.data);
    if (data.id) {
        const request = pending.get(data.id);
        if (!request) return;
        pending.delete(data.id);
        clearTimeout(request.timeout);
        if (data.error) request.reject(new Error(JSON.stringify(data.error)));
        else request.resolve(data.result);
    } else {
        for (const listener of listeners) listener(data);
    }
});

try {
    await new Promise((resolve, reject) => {
        socket.addEventListener('open', resolve, {once: true});
        socket.addEventListener('error', reject, {once: true});
    });
    await command('Page.enable');
    await command('Network.enable');
    await command('Network.setCacheDisabled', {cacheDisabled: true});
    await command('Network.setExtraHTTPHeaders', {headers: {
        'X-Botwarden-Fixture-Peer': '173.245.48.1',
        'CF-Connecting-IP': '8.8.8.8',
    }});
    // Block unexpected page requests rather than allowing outside traffic.
    await command('Fetch.enable', {patterns: [{urlPattern: '*'}]});
    listeners.add(message => {
        if (message.method !== 'Fetch.requestPaused') return;
        const {requestId, request} = message.params;
        const allowed = ['http://127.0.0.1:8777', 'http://localhost:8777', 'http://127.0.0.1:8778'];
        const method = allowed.includes(new URL(request.url).origin) ? 'Fetch.continueRequest' : 'Fetch.failRequest';
        void command(method, {requestId, ...(method === 'Fetch.failRequest' ? {errorReason: 'BlockedByClient'} : {})}).catch(() => { /* Navigating away may cancel an intercepted request. */ });
    });
    if (source === 'dashboard') {
        const privateFixture = JSON.parse(readFileSync(new URL('../../vendor/.botwarden-migration/security/credentials.json', import.meta.url)));
        await command('Storage.clearDataForOrigin', {origin:'http://127.0.0.1:8777', storageTypes:'all'});
        await command('Network.clearBrowserCookies');
        const errors = [];
        listeners.add(message => { if (message.method === 'Runtime.exceptionThrown') errors.push('Uncaught browser exception'); });
        await command('Runtime.enable');
        await command('Page.navigate', {url:target});
        const login = await command('Runtime.evaluate', {awaitPromise:true, returnByValue:true, expression:`
          (async () => {
            const account=${JSON.stringify(privateFixture.accounts.super)};
            const deadline=Date.now()+10000;
            while(Date.now()<deadline) {
              const password=document.querySelector('input[type="password"]');
              const username=document.querySelector('input[autocomplete="username"], input[type="text"]');
              if(password && username) {
                for(const [element,value] of [[username,account.username],[password,account.password]]) {
                  Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(element,value);
                  element.dispatchEvent(new Event('input',{bubbles:true}));
                }
                const button=Array.from(document.querySelectorAll('button')).find(b=>b.textContent.trim()==='Sign In');
                if(!button) return {error:'Sign-in control missing'};
                button.click(); return {submitted:true};
              }
              if(location.pathname==='/admin/plugin/botwarden' && document.querySelector('nav')) return {session:true};
              await new Promise(r=>setTimeout(r,100));
            }
            return {error:'Login form did not become ready'};
          })()`});
        assert.equal(login.exceptionDetails, undefined, 'Login form evaluation failed');
        assert.equal(login.result.value.error, undefined, login.result.value.error);
        const result = await command('Runtime.evaluate', {awaitPromise:true, returnByValue:true, expression:`
          (async () => {
            function root(container=document) {
              for (const element of container.querySelectorAll('*')) {
                if (element.shadowRoot?.getElementById('stats')) return element.shadowRoot;
                if (element.shadowRoot) { const found=root(element.shadowRoot); if(found) return found; }
                if (element.tagName==='IFRAME' && element.contentDocument) { const found=root(element.contentDocument); if(found) return found; }
              }
            }
            const deadline=Date.now()+15000;
            while(Date.now()<deadline) {
              const view=root();
              if(view?.getElementById('stats').textContent.startsWith('Local:')) {
                const history=view.getElementById('history');
                const first=history.textContent;
                const next=view.getElementById('history-next');
                if(next.disabled) return {error:'Seeded history should have another page'};
                next.click();
                while(Date.now()<deadline && history.textContent===first) await new Promise(r=>setTimeout(r,50));
                if(history.textContent===first) return {error:'History navigation did not load the next page'};
                view.getElementById('search').value='11.7.0.1';
                view.getElementById('find').click();
                while(Date.now()<deadline && !view.getElementById('blocks').textContent.includes('11.7.0.1')) await new Promise(r=>setTimeout(r,50));
                return {rendered:true, historyPaged:true, searchFound:view.getElementById('blocks').textContent.includes('11.7.0.1'),
                  hasType:view.getElementById('blocks').textContent.includes('Manual'), hasStatus:view.getElementById('blocks').textContent.includes('Active'),
                  message:view.getElementById('message').textContent};
              }
              await new Promise(r=>setTimeout(r,100));
            }
            return {error:'Authenticated botWarden dashboard did not render', url:location.pathname, title:document.title, text:document.body.innerText.slice(0,300)};
          })()`});
        assert.equal(result.exceptionDetails, undefined, 'Dashboard evaluation failed');
        assert.deepEqual(errors, []);
        const observed=result.result.value;
        assert.equal(observed.error, undefined, JSON.stringify(observed));
        assert.equal(observed.rendered, true);
        assert.equal(observed.historyPaged, true);
        assert.equal(observed.searchFound, true);
        assert.equal(observed.hasType, true);
        assert.equal(observed.hasStatus, true);
        assert.equal(observed.message, '');
        console.log(JSON.stringify({dashboard:true, historyPaged:true, searchFound:true, browser:(await command('Browser.getVersion')).product}));
    } else {
    if (sources[source]) {
        const loaded = event('Page.loadEventFired', () => true);
        await command('Page.navigate', {url: sources[source]});
        await loaded;
    }
    const extras = new Map();
    listeners.add(message => {
        if (message.method === 'Network.requestWillBeSentExtraInfo') {
            extras.set(message.params.requestId, message.params.headers);
        }
    });
    const received = event('Network.responseReceived', params => params.response.url === target);
    if (action === 'navigate') {
        await command('Page.navigate', {url: target});
    } else {
        const expression = action === 'image'
            ? `document.body.appendChild(Object.assign(document.createElement('img'), {src: ${JSON.stringify(target)}}))`
            : `document.body.appendChild(Object.assign(document.createElement('a'), {href: ${JSON.stringify(target)}, textContent: 'probe'})).click()`;
        const evaluated = await command('Runtime.evaluate', {expression});
        assert.equal(evaluated.exceptionDetails, undefined);
    }
    const response = await received;
    const headers = extras.get(response.requestId);
    assert.ok(headers, 'Missing actual outgoing browser request headers');
    const lower = Object.fromEntries(Object.entries(headers).map(([key, value]) => [key.toLowerCase(), value]));
    console.log(JSON.stringify({
        status: response.response.status,
        url: response.response.url,
        fetchSite: lower['sec-fetch-site'],
        fetchMode: lower['sec-fetch-mode'],
        fetchDest: lower['sec-fetch-dest'],
        browser: (await command('Browser.getVersion')).product,
    }));
    }
} finally {
    if(source === 'dashboard') {
        await command('Storage.clearDataForOrigin', {origin:'http://127.0.0.1:8777', storageTypes:'all'});
        await command('Network.clearBrowserCookies');
    }
    closing = true;
    for(const request of pending.values()) clearTimeout(request.timeout);
    pending.clear();
    socket.close();
    await fetch(`${endpoint}/json/close/${page.id}`);
}
