import { expect, test } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const appUrl = 'http://documentator.test/docs';

function spec(count = 650) {
  const methods = ['get', 'post', 'put', 'patch', 'delete'];
  const groups = ['Zulu', 'Alpha', 'Reports', 'Billing', 'Group 10', 'Group 2', 'Users', 'Admin'];
  const paths = {};

  for (let i = 1; i <= count; i += 1) {
    const method = methods[i % methods.length];
    const group = groups[(Math.ceil(i / 25) - 1) % groups.length];
    paths[`/api/items/${i}`] = {
      [method]: {
        operationId: `items${i}`,
        tags: [group],
        summary: `Endpoint ${i}`,
        description: 'Generated endpoint for visual sidebar coverage.',
        responses: {
          200: {
            description: 'Successful response',
            content: {
              'application/json': {
                schema: {
                  type: 'object',
                  properties: {
                    id: { type: 'integer' },
                    name: { type: 'string' },
                  },
                },
              },
            },
          },
        },
      },
    };
  }

  return {
    openapi: '3.1.0',
    info: { title: 'Visual API', version: '1.0.0' },
    servers: [{ url: 'https://api.example.test' }],
    paths,
  };
}

function multipartSpec() {
  return {
    openapi: '3.1.0',
    info: { title: 'Upload API', version: '1.0.0' },
    servers: [{ url: 'http://documentator.test' }],
    paths: {
      '/api/uploads': {
        post: {
          operationId: 'uploadAvatar',
          tags: ['Uploads'],
          summary: 'Upload avatar',
          requestBody: {
            content: {
              'multipart/form-data': {
                schema: {
                  type: 'object',
                  properties: {
                    avatar: { type: 'string', format: 'binary' },
                    items: {
                      type: 'array',
                      items: {
                        type: 'object',
                        properties: {
                          sku: { type: 'string' },
                          qty: { type: 'integer' },
                        },
                      },
                    },
                  },
                },
              },
            },
          },
          responses: { 200: { description: 'OK' } },
        },
      },
    },
  };
}

function cookieSpec() {
  return {
    openapi: '3.1.0',
    info: { title: 'Cookie API', version: '1.0.0' },
    servers: [{ url: 'http://documentator.test' }],
    paths: {
      '/api/cookie': {
        get: {
          operationId: 'cookieCheck',
          tags: ['Auth'],
          summary: 'Cookie check',
          parameters: [
            { name: 'session_id', in: 'cookie', schema: { type: 'string' } },
          ],
          responses: { 200: { description: 'OK' } },
        },
      },
    },
  };
}

async function mount(page, openapi = spec()) {
  const [css, js, core, snippets] = await Promise.all([
    readFile(resolve(root, 'resources/ui/app.css'), 'utf8'),
    readFile(resolve(root, 'resources/ui/app.js'), 'utf8'),
    readFile(resolve(root, 'resources/ui/core.js'), 'utf8'),
    readFile(resolve(root, 'resources/ui/snippets.js'), 'utf8'),
  ]);

  await page.route('http://documentator.test/docs/openapi.json', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify(openapi),
  }));
  await page.route('http://documentator.test/docs/assets/app.css', route => route.fulfill({
    contentType: 'text/css',
    body: css,
  }));
  await page.route('http://documentator.test/docs/assets/app.js', route => route.fulfill({
    contentType: 'text/javascript',
    body: js,
  }));
  await page.route('http://documentator.test/docs/assets/core.js*', route => route.fulfill({
    contentType: 'text/javascript',
    body: core,
  }));
  await page.route('http://documentator.test/docs/assets/snippets.js*', route => route.fulfill({
    contentType: 'text/javascript',
    body: snippets,
  }));
  await page.route(appUrl, route => route.fulfill({
    contentType: 'text/html',
    body: `<!doctype html>
      <html lang="en">
      <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="/docs/assets/app.css">
      </head>
      <body>
        <div id="app" data-state="booting">
          <div class="boot"><div class="boot__mark">{ }</div><p class="boot__msg">Loading API spec...</p></div>
        </div>
        <script>
          window.__DOCUMENTATOR__ = {
            specUrl: '/docs/openapi.json',
            title: 'Visual API',
            authStorage: 'memory'
          };
        </script>
        <script type="module" src="/docs/assets/app.js"></script>
      </body>
      </html>`,
  }));

  await page.goto(appUrl);
  await expect(page.locator('#app[data-state="ready"]')).toBeVisible();
}

test('desktop sidebar stays virtualized with hundreds of endpoints', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 960 });
  await mount(page);

  await expect(page.locator('.topbar__brand')).toContainText('Visual API');
  await expect(page.locator('.topbar__meta')).toContainText('650 endpoints');

  await expect.poll(() => page.locator('.nav-item').count()).toBeGreaterThan(20);
  const mountedItems = await page.locator('.nav-item').count();
  expect(mountedItems).toBeLessThan(80);
  const firstGroup = await page.locator('#nav').evaluate(nav =>
    nav.querySelector('[data-group-toggle] .nav-group__label span:nth-child(2)')?.textContent,
  );
  expect(firstGroup).toBe('Admin');

  const firstPath = await page.locator('.nav-item__path').first().textContent();
  await page.locator('#nav').evaluate(el => { el.scrollTop = el.scrollHeight; el.dispatchEvent(new Event('scroll')); });
  await expect.poll(() => page.locator('.nav-item__path').last().textContent()).not.toBe(firstPath);
  expect(await page.locator('.nav-item').count()).toBeLessThan(80);

  const box = await page.locator('#app').boundingBox();
  expect(box.width).toBe(1440);
  expect(box.height).toBe(960);
  expect((await page.locator('#app').screenshot()).length).toBeGreaterThan(30000);
});

test('method filter and collapsed sidebar groups persist across reloads', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 860 });
  await mount(page);

  await page.locator('[data-method-filter="post"]').click();
  await page.locator('[data-group-toggle]').first().click();
  const collapsedKey = await page.locator('[data-group-toggle]').first().getAttribute('data-group-toggle');

  await page.reload();
  await expect(page.locator('#app[data-state="ready"]')).toBeVisible();
  await expect(page.locator('[data-method-filter="post"]')).toHaveClass(/is-active/);
  await expect(page.locator(`[data-group-toggle="${collapsedKey}"]`)).toHaveAttribute('aria-expanded', 'false');
});

test('mobile layout exposes the sidebar without overlapping the document', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mount(page);

  await page.locator('#menuBtn').click();
  await expect(page.locator('#sidebar[data-open]')).toBeVisible();

  const sidebar = await page.locator('#sidebar').boundingBox();
  const doc = await page.locator('#doc').boundingBox();
  expect(sidebar.width).toBeGreaterThan(250);
  expect(doc.width).toBeGreaterThan(0);
  expect((await page.locator('#app').screenshot()).length).toBeGreaterThan(20000);
});

test('multipart snippets use Laravel-compatible bracket field names for nested values', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 860 });
  await mount(page, multipartSpec());
  await page.locator('.nav-item').first().click();

  await expect(page.locator('#snippetCode')).toContainText('items[0][sku]=string');
  await expect(page.locator('#snippetCode')).toContainText('items[0][qty]=0');
});

test('cookie parameters are applied through browser cookies on same-origin requests', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 860 });
  let cookieHeader = '';

  await page.route('http://documentator.test/api/cookie', route => {
    cookieHeader = route.request().headers().cookie || '';
    return route.fulfill({ contentType: 'application/json', body: '{"ok":true}' });
  });

  await mount(page, cookieSpec());
  await page.locator('.nav-item').first().click();
  await page.locator('[data-kind="cookie"][data-name="session_id"]').fill('abc123');
  await page.locator('#send').click();

  await expect.poll(() => cookieHeader).toContain('session_id=abc123');
});
