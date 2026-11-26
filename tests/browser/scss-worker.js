// Smoke-test the actual production worker against a local WordPress installation.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');

(async () => {
    const site = new URL(process.env.FSNIP_TEST_URL || 'https://fluent.test');
    if (!site.hostname.endsWith('.test') && site.hostname !== 'localhost') throw new Error('Local site required');
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage({ignoreHTTPSErrors: true});
        await page.goto(site.origin);
        const result = await page.evaluate(async () => {
            const worker = new Worker('/wp-content/plugins/easy-code-manager/dist/scss-compiler.js');
            function compile(source) {
                return new Promise((resolve, reject) => {
                    const timer = setTimeout(() => reject(new Error('Compiler timed out')), 15000);
                    worker.onmessage = event => { clearTimeout(timer); resolve(event.data); };
                    worker.onerror = event => { clearTimeout(timer); reject(new Error(event.message)); };
                    worker.postMessage(source);
                });
            }
            try {
                const good = await compile('@use "sass:math"; $size: 4px; @mixin sized { padding: math.div($size, 2) } .card { .title { @include sized } }');
                return {good, bad: await compile('.card { color: $missing; }')};
            } finally {
                worker.terminate();
            }
        });
        assert.match(result.good.css, /\.card \.title/);
        assert.match(result.good.css, /padding: 2px/);
        assert.equal(result.bad.line, 1);
        assert.ok(result.bad.column > 1);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
