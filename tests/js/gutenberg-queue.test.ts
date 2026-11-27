import { expect, test } from 'bun:test';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const php = readFileSync(new URL('../../includes/gutenberg-finalizer-admin.php', import.meta.url), 'utf8');
const script = php.split('function gutenberg_finalizer_script(): string')[1].split("<<<'JS'\n")[1].split('\n        JS;')[0];
const relogin = 'Your WordPress session expired or access was denied. Log in again, then reload this page.';

type Request = { path: string; parse?: boolean; data?: any; headers?: Record<string, string> };

function queue(handler: (request: Request) => unknown, refresh: () => Promise<Response> = async () => new Response('Denied', { status: 403 })) {
    let now = 0;
    let timerId = 0;
    const timers = new Map<number, { at: number; callback: () => void }>();
    const calls: (Request & { at: number })[] = [];
    const refreshes: number[] = [];
    const progress = { textContent: '' };
    const paragraph = { textContent: '' };
    const notice = { hidden: true, querySelector: () => paragraph };
    const middleware: { nonce: string }[] = [];
    // Match core: parse:false rejects the untouched Response; parsed mode exposes
    // JSON errors and lets core's code-based nonce refresh run.
    const apiFetch = Object.assign(async (request: Request): Promise<unknown> => {
        const headers = { ...request.headers };
        for (const item of middleware) headers['X-WP-Nonce'] = item.nonce;
        calls.push({ ...request, headers, at: now });
        try {
            const response = await handler({ ...request, headers }) as Response;
            if (request.parse === false) {
                if (!response.ok) throw response;
                return response;
            }
            if (response.status === 204) return null;
            let body;
            try { body = JSON.parse(await response.text()); }
            catch { throw { code: 'invalid_json', message: 'Invalid JSON response.', data: { status: response.status } }; }
            if (!response.ok) throw { ...body, data: body.data ?? { status: response.status } };
            return body;
        } catch (error: any) {
            if (error.code !== 'rest_cookie_invalid_nonce') throw error;
            const response = await refreshNonce();
            if (!response.ok) throw error;
            apiFetch.nonceMiddleware.nonce = await response.text();
            return apiFetch(request);
        }
    }, {
        use: (item: { nonce: string }) => { middleware.push(item); },
        createNonceMiddleware: (nonce: string) => ({ nonce }),
        nonceMiddleware: { nonce: 'old' },
        nonceEndpoint: '/nonce',
    });
    const refreshNonce = async () => { refreshes.push(now); return refresh(); };
    const wp = { apiFetch };
    runInNewContext(script, {
        wp,
        Date: { now: () => now },
        document: { getElementById: (id: string) => ({
            'novamira-gb-finalizer': {},
            'novamira-gb-progress': progress,
            'novamira-gb-notice': notice,
        })[id] ?? null },
        window: {
            wp,
            novamiraGutenbergFinalizer: { nonce: 'old' },
            fetch: refreshNonce,
            addEventListener: () => {},
            setTimeout: (callback: () => void, delay: number) => {
                timers.set(++timerId, { callback, at: now + delay });
                return timerId;
            },
            clearTimeout: (id: number) => timers.delete(id),
        },
    });
    const flush = async () => { for (let i = 0; i < 200; i++) await Promise.resolve(); };
    const advance = async (milliseconds: number) => {
        await flush();
        const end = now + milliseconds;
        while (true) {
            const next = [...timers.entries()].filter(([, timer]) => timer.at <= end)
                .sort((a, b) => a[1].at - b[1].at)[0];
            if (!next) break;
            now = next[1].at;
            timers.delete(next[0]);
            next[1].callback();
            await flush();
        }
        now = end;
        await flush();
    };
    return { calls, refreshes, apiFetch, progress, paragraph, notice, advance, flush, timers };
}

const success = (data: unknown = { done: true }) => Response.json(data);
const failure = (body: unknown, status = 403) => Response.json(body, { status });

const authErrors = [
    { data: { status: 401 } },
    { data: { status: 403 }, code: 'rest_cookie_invalid_nonce' },
    { code: 'rest_forbidden' },
    { code: 'rest_not_logged_in' },
    { code: 'incorrect_password' },
    { code: 'invalid_username' },
    new Response('<html>Denied</html>', { status: 401 }),
    new Response('<html>Denied</html>', { status: 403 }),
];

for (const [index, error] of authErrors.entries()) {
    test(`heartbeat authentication failure ${index + 1} stops every timer`, async () => {
        const page = queue(() => error instanceof Response ? error.clone() : failure(error, error.data?.status ?? 403));
        await page.advance(600000);
        expect(page.calls).toHaveLength(1);
        expect(page.progress.textContent).toBe(relogin);
        expect(page.paragraph.textContent).toBe(relogin);
        expect(page.notice.hidden).toBe(false);
        expect(page.timers.size).toBe(0);
    });
}

for (const status of [401, 403]) {
    test(`HTML HTTP ${status} response stops polling before JSON parsing loses its status`, async () => {
        const page = queue(() => new Response('<html>Denied</html>', { status }));
        await page.advance(600000);
        expect(page.calls).toHaveLength(1);
        expect(page.progress.textContent).toBe(relogin);
    });
}

test('claim authentication failure stops polling', async () => {
    const page = queue(({ path }) => path.endsWith('/heartbeat') ? success() : failure({ code: 'rest_forbidden' }));
    await page.advance(600000);
    expect(page.calls).toHaveLength(3);
    expect(page.progress.textContent).toBe(relogin);
});

test('a stalled heartbeat is shared by every source and never overlaps', async () => {
    let resolve!: (value: Response) => void;
    const stalled = new Promise<Response>((done) => { resolve = done; });
    const page = queue(({ path }) => path.endsWith('/heartbeat') ? stalled : success());
    await page.advance(120000);
    expect(page.calls).toHaveLength(1);
    resolve(success());
    await page.flush();
    expect(page.calls.filter(({ path }) => path.endsWith('/claim-next'))).toHaveLength(1);
});

for (const failingPath of ['/heartbeat', '/batches/claim-next']) {
    test(`non-auth ${failingPath} failures back off independently and reset on success`, async () => {
        let failing = true;
        const page = queue(({ path }) => path.endsWith(failingPath) && failing
            ? failure({ data: { status: 503 } }, 503) : success());
        await page.advance(1000000);
        const failures = page.calls.filter(({ path }) => path.endsWith(failingPath));
        const gaps = failures.slice(1).map((call, index) => call.at - failures[index].at);
        expect(gaps.slice(0, 4)).toEqual([failingPath === '/heartbeat' ? 5250 : 5000, 10000, 20000, 40000]);
        expect(gaps.slice(-2)).toEqual(failingPath === '/heartbeat' ? [60000, 60000] : [300000, 300000]);
        expect(page.progress.textContent).toBe('Queue disconnected. Reload this page.');
        if (failingPath !== '/heartbeat') {
            const heartbeats = page.calls.filter(({ path }) => path.endsWith('/heartbeat'));
            expect(Math.max(...heartbeats.slice(1).map((call, index) => call.at - heartbeats[index].at))).toBeLessThan(150000);
        }
        failing = false;
        await page.advance(300000);
        const claims = page.calls.filter(({ path }) => path.endsWith('/batches/claim-next'));
        expect(claims.at(-1)!.at - claims.at(-2)!.at).toBe(5000);
        expect(page.progress.textContent).toBe('Nothing to do. The queue is ready.');
    });
}

test('mid-batch auth failure retains the fail path then stops polling', async () => {
    const page = queue(({ path }) => {
        if (path.endsWith('/batches/claim-next')) return success({ claim: { batch: { batch_id: 1 } } });
        if (path.endsWith('/items/claim-next')) return success({ item: { item_id: 2 } });
        if (path.includes('/spec?')) return failure({ code: 'rest_cookie_invalid_nonce' });
        return success();
    });
    await page.advance(600000);
    expect(page.calls.at(-1)!.path).toEndWith('/items/2/fail');
    expect(page.calls).toHaveLength(6);
    expect(page.progress.textContent).toBe(relogin);
    expect(page.timers.size).toBe(0);
});

test('heartbeat auth failure during an active batch blocks subsequent batch requests', async () => {
    let release!: (value: Response) => void;
    const stalled = new Promise<Response>((resolve) => { release = resolve; });
    let heartbeats = 0;
    const page = queue(({ path }) => {
        if (path.endsWith('/heartbeat') && ++heartbeats === 3) return failure({ data: { status: 401 } }, 401);
        if (path.endsWith('/batches/claim-next')) return success({ claim: { batch: { batch_id: 1 } } });
        if (path.endsWith('/items/claim-next')) return stalled;
        return success();
    });
    await page.advance(15000);
    const count = page.calls.length;
    expect(page.progress.textContent).toBe(relogin);
    release(success({ item: { item_id: 2 } }));
    await page.advance(600000);
    expect(page.calls).toHaveLength(count);
    expect(page.progress.textContent).toBe(relogin);
    expect(page.timers.size).toBe(0);
});

test('a rejected JSON 409 preserves its code and message in the item failure report', async () => {
    const page = queue(({ path }) => {
        if (path.endsWith('/batches/claim-next')) return success({ claim: { batch: { batch_id: 1 } } });
        if (path.endsWith('/items/claim-next')) return success({ item: { item_id: 2 } });
        if (path.includes('/spec?')) return failure({ code: 'lease_conflict', message: 'The lease belongs to another browser.' }, 409);
        return success();
    });
    await page.advance(250);
    const report = page.calls.find(({ path }) => path.endsWith('/items/2/fail'))!;
    expect(report.data.message).toBe('The lease belongs to another browser.');
    expect(report.data.errors[0].code).toBe('lease_conflict');
    expect(page.calls.filter(({ path }) => path.endsWith('/items/2/fail'))).toHaveLength(1);
});

test('expired nonce refreshes once, updates both middleware instances, and keeps polling', async () => {
    const page = queue(({ headers }) => headers?.['X-WP-Nonce'] === 'new-nonce'
        ? success() : failure({ code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed.' }),
    async () => new Response('new-nonce'));
    await page.advance(30000);
    expect(page.refreshes).toHaveLength(1);
    expect(page.apiFetch.nonceMiddleware.nonce).toBe('new-nonce');
    expect(page.calls[0].headers?.['X-WP-Nonce']).toBe('old');
    expect(page.calls.slice(1).every(({ headers }) => headers?.['X-WP-Nonce'] === 'new-nonce')).toBe(true);
    expect(page.calls.filter(({ path }) => path.endsWith('/batches/claim-next')).length).toBeGreaterThan(1);
    expect(page.progress.textContent).toBe('Nothing to do. The queue is ready.');
});

for (const refresh of [
    async () => new Response('Denied', { status: 403 }),
    async () => { throw new Error('Network unavailable'); },
    async () => new Response('still-invalid'),
]) {
    test('nonce refresh failure or a rejected retry stops without a refresh loop', async () => {
        const page = queue(() => failure({ code: 'rest_cookie_invalid_nonce' }), refresh);
        await page.advance(600000);
        expect(page.refreshes).toHaveLength(1);
        expect(page.calls.length).toBeLessThanOrEqual(2);
        expect(page.progress.textContent).toBe(relogin);
        expect(page.timers.size).toBe(0);
    });
}

test('204 heartbeat succeeds with an empty body', async () => {
    const page = queue(({ path }) => path.endsWith('/heartbeat') ? new Response(null, { status: 204 }) : success());
    await page.advance(20000);
    expect(page.calls.filter(({ path }) => path.endsWith('/batches/claim-next')).length).toBeGreaterThan(1);
    expect(page.progress.textContent).toBe('Nothing to do. The queue is ready.');
});

test('auth failure in the inner item fail report is sent only once', async () => {
    const page = queue(({ path }) => {
        if (path.endsWith('/batches/claim-next')) return success({ claim: { batch: { batch_id: 1 } } });
        if (path.endsWith('/items/claim-next')) return success({ item: { item_id: 2 } });
        if (path.endsWith('/items/2/fail')) return failure({ code: 'rest_forbidden' });
        if (path.includes('/spec?')) return success({ blocks: [] });
        return success();
    });
    await page.advance(600000);
    expect(page.calls.filter(({ path }) => path.endsWith('/items/2/fail'))).toHaveLength(1);
    expect(page.progress.textContent).toBe(relogin);
    expect(page.timers.size).toBe(0);
});

test('transient heartbeat failure during a batch uses only progress and recovers', async () => {
    let release!: (value: Response) => void;
    const stalled = new Promise<Response>((resolve) => { release = resolve; });
    let heartbeats = 0;
    const page = queue(({ path }) => {
        if (path.endsWith('/heartbeat') && ++heartbeats === 3) return failure({ message: 'Unavailable' }, 503);
        if (path.endsWith('/batches/claim-next')) return success({ claim: { batch: { batch_id: 1 } } });
        if (path.endsWith('/items/claim-next')) return stalled;
        return success();
    });
    await page.advance(15000);
    expect(page.progress.textContent).toBe('Queue disconnected. Reload this page.');
    expect(page.notice.hidden).toBe(true);
    await page.advance(15000);
    expect(page.progress.textContent).toBe('Working on queued Gutenberg changes...');
    expect(page.notice.hidden).toBe(true);
    release(success({ done: true, batch: { status: 'finalized' } }));
    await page.flush();
});
