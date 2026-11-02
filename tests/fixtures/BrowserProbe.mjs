// Real browser metadata; only the disposable loopback origins are permitted.
import assert from 'node:assert/strict';

const [source, action] = process.argv.slice(2);
const sources = {
    'cross-site': 'http://localhost:8777/',
    'same-site': 'http://127.0.0.1:8778/',
    'same-origin': 'http://127.0.0.1:8777/',
    none: null,
};
assert.ok(Object.hasOwn(sources, source));
assert.ok(['image', 'link', 'navigate'].includes(action));
assert.equal(action === 'navigate', source === 'none');
assert.equal(process.env.BOTWARDEN_BROWSER_URL, 'http://127.0.0.1:8877');

const endpoint = process.env.BOTWARDEN_BROWSER_URL;
const target = 'http://127.0.0.1:8777/.env';
const page = await (await fetch(`${endpoint}/json/new?about:blank`, {method: 'PUT'})).json();
assert.ok(page.webSocketDebuggerUrl.startsWith('ws://127.0.0.1:8877/'));
const socket = new WebSocket(page.webSocketDebuggerUrl);
const pending = new Map();
const listeners = new Set();
let sequence = 0;

function command(method, params = {}) {
    return new Promise((resolve, reject) => {
        const id = ++sequence;
        const timeout = setTimeout(() => {
            pending.delete(id);
            reject(new Error(`Timed out: ${method}`));
        }, 10000);
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
        void command(method, {requestId, ...(method === 'Fetch.failRequest' ? {errorReason: 'BlockedByClient'} : {})});
    });
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
} finally {
    socket.close();
    await fetch(`${endpoint}/json/close/${page.id}`);
}
