/*
 * Documentator — built-in explorer.
 * Fetches the OpenAPI document and renders the reading surface + try-it console.
 * No framework, no build step.
 */
(function () {
    'use strict';

    var METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];
    var BODY_METHODS = { post: 1, put: 1, patch: 1, delete: 1 };
    var CONSOLE_MIN = 340;
    var CONSOLE_MAX = 760;

    var cfg = window.__DOCUMENTATOR__ || {};
    var app = document.getElementById('app');

    var state = {
        spec: null,
        operations: [],
        servers: [],
        currentId: null,
        slugToId: {},
    };

    /* ---------- helpers ---------- */

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function el(html) {
        var t = document.createElement('template');
        t.innerHTML = html.trim();
        return t.content.firstElementChild;
    }

    function statusClass(code) {
        if (!code || code < 100) return 'x';
        return String(Math.floor(code / 100));
    }

    /* The non-null members of an OpenAPI 3.1 type (which may be a union array). */
    function typesOf(schema) {
        if (!schema) return [];
        var t = schema.type;
        if (Array.isArray(t)) return t.filter(function (x) { return x !== 'null'; });
        return t ? [t] : [];
    }

    function isNullable(schema) {
        if (!schema) return false;
        if (schema.nullable) return true;
        return Array.isArray(schema.type) && schema.type.indexOf('null') !== -1;
    }

    /* A human label covering every shape OpenAPI can describe. */
    function schemaType(schema) {
        if (!schema) return 'any';
        if (schema.oneOf || schema.anyOf) {
            return (schema.oneOf || schema.anyOf).map(schemaType).join(' | ') || 'any';
        }
        if (schema.allOf) return 'object';
        var types = typesOf(schema);
        if (types.length > 1) return types.map(function (t) { return one(t, schema); }).join(' | ');
        return one(types[0], schema);
    }

    function one(type, schema) {
        if (type === 'array') {
            return (schema.items ? schemaType(schema.items) : 'any') + '[]';
        }
        var base = type || (schema.properties ? 'object' : (schema.enum ? 'string' : 'any'));
        if (schema.format) base += '<' + schema.format + '>';
        return base;
    }

    function isFileSchema(schema) {
        if (!schema) return false;
        if (schema.format === 'binary') return true;
        return schema.type === 'array' && schema.items && schema.items.format === 'binary';
    }

    /* The request body media type + schema we should drive the console from. */
    function requestBodyContent(op) {
        var rb = op && op.requestBody;
        if (!rb || !rb.content) return null;
        var c = rb.content;
        var mediaType = c['multipart/form-data'] ? 'multipart/form-data'
            : c['application/json'] ? 'application/json'
            : Object.keys(c)[0];
        if (!mediaType) return null;
        return { mediaType: mediaType, schema: c[mediaType].schema, required: !!rb.required };
    }

    function responseMedia(response) {
        if (!response || !response.content) return null;
        var c = response.content;
        return c['application/json'] || c[Object.keys(c)[0]] || null;
    }

    function responseSchema(response) {
        var media = responseMedia(response);
        return media && media.schema ? media.schema : null;
    }

    /* A concrete example for a response, from `example`, the first of `examples`,
       or the schema's own `example`. Returns undefined when there is none. */
    function responseExample(response) {
        var media = responseMedia(response);
        if (!media) return undefined;
        if (media.example !== undefined) return media.example;
        if (media.examples) {
            var first = media.examples[Object.keys(media.examples)[0]];
            if (first && first.value !== undefined) return first.value;
        }
        if (media.schema && media.schema.example !== undefined) return media.schema.example;
        return undefined;
    }

    /* Remember the server (+ auth tokens, keyed by scheme) per docs page. */
    var store = {
        k: function (name) { return 'documentator:' + location.pathname + ':' + name; },
        get: function (name) { try { return localStorage.getItem(this.k(name)); } catch (e) { return null; } },
        set: function (name, value) { try { localStorage.setItem(this.k(name), value); } catch (e) { /* ignore */ } },
    };

    function slugFor(entry) {
        return (entry.method + '-' + entry.path).toLowerCase()
            .replace(/[{}]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }

    function plural(n, one, many) {
        return n + ' ' + (n === 1 ? one : many);
    }

    function operationLabel(entry) {
        return (entry.op && entry.op.summary) || entry.path;
    }

    function methodStats() {
        var counts = {};
        state.operations.forEach(function (entry) {
            counts[entry.method] = (counts[entry.method] || 0) + 1;
        });
        return METHODS.filter(function (method) { return counts[method]; }).map(function (method) {
            return '<span class="topbar__method m-' + method + '">' + method.toUpperCase() + ' ' + counts[method] + '</span>';
        }).join('');
    }

    function isConsoleDocked() {
        return !window.matchMedia('(max-width: 1180px)').matches;
    }

    function consoleMaxWidth() {
        return Math.max(CONSOLE_MIN, Math.min(CONSOLE_MAX, window.innerWidth - 560));
    }

    function clampConsoleWidth(width) {
        return Math.min(Math.max(Math.round(width), CONSOLE_MIN), consoleMaxWidth());
    }

    function updateConsoleResizeHandle(width) {
        var handle = document.getElementById('consoleResize');
        if (!handle) return;
        handle.setAttribute('aria-valuemin', String(CONSOLE_MIN));
        handle.setAttribute('aria-valuemax', String(consoleMaxWidth()));
        handle.setAttribute('aria-valuenow', String(width || clampConsoleWidth(parseInt(getComputedStyle(document.documentElement).getPropertyValue('--console'), 10) || 440)));
    }

    function applyConsoleWidth(width, persist) {
        var clamped = clampConsoleWidth(width);
        document.documentElement.style.setProperty('--console', clamped + 'px');
        updateConsoleResizeHandle(clamped);
        if (persist) store.set('consoleWidth', String(clamped));
    }

    function restoreConsoleWidth() {
        var saved = parseInt(store.get('consoleWidth') || '', 10);
        applyConsoleWidth(isNaN(saved) ? 440 : saved, false);
    }

    function applyHash() {
        var id = state.slugToId[decodeURIComponent((location.hash || '').slice(1))];
        if (id) { if (id !== state.currentId) select(id); } else { renderEmpty(); }
    }

    /* Minimal, XSS-safe markdown: input is escaped first; output is a fixed tag set. */
    function mdInline(escaped) {
        return escaped
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    }
    function inline(text) { return mdInline(esc(text)); }
    function block(text) {
        return esc(text).split(/\n{2,}/).map(function (b) {
            var lines = b.split('\n');
            if (lines.every(function (l) { return /^\s*-\s+/.test(l); })) {
                return '<ul>' + lines.map(function (l) { return '<li>' + mdInline(l.replace(/^\s*-\s+/, '')) + '</li>'; }).join('') + '</ul>';
            }
            return '<p>' + lines.map(mdInline).join('<br>') + '</p>';
        }).join('');
    }

    /* Build a sample JSON value from a schema (for the request body skeleton). */
    function sample(schema, depth) {
        depth = depth || 0;
        if (!schema || depth > 6) return null;
        if (schema.example !== undefined) return schema.example;
        if (schema.enum && schema.enum.length) return schema.enum[0];
        if (schema.oneOf || schema.anyOf) return sample((schema.oneOf || schema.anyOf)[0], depth);
        var type = typesOf(schema)[0] || (schema.properties ? 'object' : null);
        switch (type) {
            case 'object':
                var obj = {};
                var props = schema.properties || {};
                Object.keys(props).forEach(function (k) { obj[k] = sample(props[k], depth + 1); });
                return obj;
            case 'array':
                return [sample(schema.items, depth + 1)];
            case 'integer':
            case 'number':
                return 0;
            case 'boolean':
                return true;
            case 'string':
                switch (schema.format) {
                    case 'date-time': return '2026-01-01T00:00:00Z';
                    case 'date': return '2026-01-01';
                    case 'email': return 'user@example.com';
                    case 'uuid': return '00000000-0000-0000-0000-000000000000';
                    case 'uri': return 'https://example.com';
                    case 'binary': return '';
                    default: return 'string';
                }
            default:
                return null;
        }
    }

    /* ---------- data ---------- */

    function buildOperations() {
        var paths = state.spec.paths || {};
        var ops = [];
        Object.keys(paths).forEach(function (path) {
            METHODS.forEach(function (method) {
                var op = paths[path][method];
                if (!op) return;
                ops.push({
                    id: method + ' ' + path,
                    method: method,
                    path: path,
                    op: op,
                    tag: (op.tags && op.tags[0]) || 'Endpoints',
                });
            });
        });
        state.operations = ops;
        state.slugToId = {};
        ops.forEach(function (entry) { entry.slug = slugFor(entry); state.slugToId[entry.slug] = entry.id; });

        var servers = state.spec.servers || [];
        state.servers = servers.length ? servers.map(function (s) { return s.url; }) : [location.origin];
    }

    function groupsFor(filter) {
        var q = (filter || '').trim().toLowerCase();
        var order = [];
        var byTag = {};
        state.operations.forEach(function (entry) {
            if (q && (entry.method + ' ' + entry.path + ' ' + (entry.op.summary || '')).toLowerCase().indexOf(q) === -1) return;
            if (!byTag[entry.tag]) { byTag[entry.tag] = []; order.push(entry.tag); }
            byTag[entry.tag].push(entry);
        });
        return order.map(function (tag) { return { tag: tag, items: byTag[tag] }; });
    }

    /* ---------- security ---------- */

    function securitySchemes() {
        return (state.spec.components && state.spec.components.securitySchemes) || {};
    }
    function hasSecuritySchemes() {
        return Object.keys(securitySchemes()).length > 0;
    }

    function authForOp(op) {
        if (!op.security || !op.security.length) return null;
        var key = Object.keys(op.security[0] || {})[0];
        if (!key) return null;
        return { key: key, scheme: securitySchemes()[key] || { type: 'http', scheme: 'bearer' } };
    }

    /* Tokens are stored per scheme; fall back to the legacy single-token key. */
    function authToken(key) { return store.get('auth:' + key) || store.get('auth') || ''; }
    function setAuthToken(key, value) { store.set('auth:' + key, value || ''); }
    function anyAuthorized() {
        return Object.keys(securitySchemes()).some(function (k) { return !!authToken(k); });
    }

    function authLabel(scheme) {
        scheme = scheme || {};
        if (scheme.type === 'apiKey') return scheme.name || 'API key';
        if (scheme.scheme === 'basic') return 'base64(user:password)';
        return 'Bearer token';
    }
    function authHint(scheme) {
        scheme = scheme || {};
        if (scheme.type === 'apiKey') return 'apiKey · ' + (scheme.in || 'header') + (scheme.name ? ' · ' + scheme.name : '');
        if (scheme.type === 'http') return 'http · ' + (scheme.scheme || 'bearer');
        if (scheme.type === 'oauth2') return 'oauth2';
        return scheme.type || 'http';
    }

    /* Put the token where its scheme says it belongs (query is handled by the caller). */
    function applyAuthHeader(headers, scheme, token) {
        scheme = scheme || {};
        if (scheme.type === 'apiKey' && scheme.in === 'header') {
            headers[scheme.name || 'X-API-Key'] = token;
        } else if (scheme.scheme === 'basic') {
            headers.Authorization = 'Basic ' + token;
        } else {
            headers.Authorization = 'Bearer ' + token;
        }
    }

    function pathSegments(path) {
        return path.split('/').filter(Boolean).map(function (seg) {
            var isParam = /^\{.*\}$/.test(seg);
            return '<span class="seg">/</span><span class="' + (isParam ? 'seg--param' : 'seg') + '">' + esc(seg) + '</span>';
        }).join('');
    }

    function pathParamNames(path) {
        return (path.match(/\{(\w+)\}/g) || []).map(function (m) { return m.slice(1, -1); });
    }

    /* ---------- shell ---------- */

    function renderShell() {
        var info = state.spec.info || {};
        app.dataset.state = 'ready';
        app.innerHTML = '';

        var version = info.version ? '<span class="topbar__version">v' + esc(info.version) + '</span>' : '';
        var authBtn = hasSecuritySchemes()
            ? '<button class="topbar__auth" id="authBtn" type="button">&#128275; Authorize</button>'
            : '';
        app.appendChild(el(
            '<header class="topbar">' +
                '<button class="topbar__menu" id="menuBtn" aria-label="Toggle navigation">&#9776;</button>' +
                '<div class="topbar__brand"><b>{ }</b>' + esc(info.title || cfg.title || 'API') + '</div>' +
                version +
                '<div class="topbar__meta" aria-label="API overview">' +
                    '<span>' + esc(plural(state.operations.length, 'endpoint', 'endpoints')) + '</span>' +
                    methodStats() +
                '</div>' +
                '<div class="topbar__actions">' + authBtn +
                    '<button class="topbar__try" id="topbarTry" type="button" hidden>Try it</button>' +
                    '<a class="topbar__spec" href="' + esc(cfg.specUrl) + '" target="_blank" rel="noopener">openapi.json &#8599;</a>' +
                '</div>' +
            '</header>'
        ));

        app.appendChild(el(
            '<div class="layout">' +
                '<aside class="sidebar" id="sidebar">' +
                    '<div class="sidebar__search"><input id="search" type="search" placeholder="Search endpoints  ( / )" autocomplete="off"></div>' +
                    '<nav class="nav" id="nav"></nav>' +
                '</aside>' +
                '<main class="doc" id="doc"></main>' +
                '<aside class="console" id="console">' +
                    '<div class="console__resize" id="consoleResize" role="separator" aria-label="Resize request panel" aria-orientation="vertical" tabindex="0"></div>' +
                    '<div class="console__head" id="consoleHead"><span class="console__dot"></span>Request' +
                        '<button class="console__close" id="consoleClose" aria-label="Close console">&#10005;</button></div>' +
                    '<div class="console__body" id="consoleBody">' +
                        '<div id="consoleForm"></div><div id="responseMount"></div>' +
                    '</div>' +
                    '<div class="console__foot">' +
                        '<button class="clear-request" id="clearRequest" type="button">Clear</button>' +
                        '<button class="send" id="send">Send request</button>' +
                    '</div>' +
                '</aside>' +
            '</div>'
        ));
        app.appendChild(el('<div class="scrim" id="scrim"></div>'));
        if (hasSecuritySchemes()) app.appendChild(el(authModalHtml()));

        restoreConsoleWidth();
        wireShell();
        updateAuthButton();
        renderNav('');
        applyHash();
    }

    function wireShell() {
        document.getElementById('nav').addEventListener('click', function (e) {
            var btn = e.target.closest('.nav-item');
            if (btn) select(btn.dataset.id);
        });
        document.getElementById('search').addEventListener('input', function (e) {
            renderNav(e.target.value);
        });
        document.getElementById('send').addEventListener('click', send);
        document.getElementById('clearRequest').addEventListener('click', clearRequestInputs);
        document.getElementById('topbarTry').addEventListener('click', openConsole);

        var sidebar = document.getElementById('sidebar');
        var consoleEl = document.getElementById('console');
        var scrim = document.getElementById('scrim');
        document.getElementById('menuBtn').addEventListener('click', function () { toggle(sidebar, scrim); });
        document.getElementById('consoleClose').addEventListener('click', function () { close(consoleEl, scrim); });
        scrim.addEventListener('click', function () { close(sidebar, scrim); close(consoleEl, scrim); });

        var form = document.getElementById('consoleForm');
        form.addEventListener('input', onFormInput);
        form.addEventListener('change', updateSnippet); // selects + file pickers
        wireConsoleResize();

        var authBtn = document.getElementById('authBtn');
        if (authBtn) authBtn.addEventListener('click', openAuth);
        var authModal = document.getElementById('authModal');
        if (authModal) {
            document.getElementById('authModalClose').addEventListener('click', closeAuth);
            document.getElementById('authSave').addEventListener('click', saveAuth);
            document.getElementById('authClear').addEventListener('click', clearAuth);
            authModal.addEventListener('click', function (e) { if (e.target === authModal) closeAuth(); });
        }

        window.addEventListener('hashchange', applyHash);
        document.addEventListener('keydown', onKeydown);
        window.addEventListener('resize', function () {
            if (isConsoleDocked()) {
                applyConsoleWidth(parseInt(getComputedStyle(document.documentElement).getPropertyValue('--console'), 10) || 440, false);
            }
        });
    }

    function wireConsoleResize() {
        var handle = document.getElementById('consoleResize');
        var consoleEl = document.getElementById('console');
        if (!handle || !consoleEl) return;

        handle.addEventListener('pointerdown', function (e) {
            if (!isConsoleDocked()) return;
            e.preventDefault();

            var startX = e.clientX;
            var startWidth = consoleEl.getBoundingClientRect().width;
            document.body.classList.add('is-resizing-console');
            try { handle.setPointerCapture(e.pointerId); } catch (err) { /* pointer may already be captured */ }
            var active = true;

            function move(ev) {
                applyConsoleWidth(startWidth - (ev.clientX - startX), false);
            }

            function done(ev) {
                if (!active) return;
                active = false;
                document.removeEventListener('pointermove', move);
                document.removeEventListener('pointerup', done);
                document.removeEventListener('pointercancel', done);
                handle.removeEventListener('lostpointercapture', done);
                try { handle.releasePointerCapture(ev.pointerId); } catch (err) { /* capture may have ended */ }
                document.body.classList.remove('is-resizing-console');
                applyConsoleWidth(parseInt(getComputedStyle(document.documentElement).getPropertyValue('--console'), 10) || startWidth, true);
            }

            document.addEventListener('pointermove', move);
            document.addEventListener('pointerup', done);
            document.addEventListener('pointercancel', done);
            handle.addEventListener('lostpointercapture', done);
        });

        handle.addEventListener('keydown', function (e) {
            if (!isConsoleDocked()) return;
            var current = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--console'), 10) || 440;
            var step = e.shiftKey ? 48 : 16;
            if (e.key === 'ArrowLeft') {
                e.preventDefault();
                applyConsoleWidth(current + step, true);
            } else if (e.key === 'ArrowRight') {
                e.preventDefault();
                applyConsoleWidth(current - step, true);
            } else if (e.key === 'Home') {
                e.preventDefault();
                applyConsoleWidth(CONSOLE_MIN, true);
            } else if (e.key === 'End') {
                e.preventDefault();
                applyConsoleWidth(consoleMaxWidth(), true);
            }
        });
    }

    function onFormInput(e) {
        var t = e.target;
        if (t && t.dataset && t.dataset.kind === 'server') store.set('server', t.value);
        updateSnippet();
    }

    function onKeydown(e) {
        var typing = e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName);
        if (e.key === '/' && !typing) {
            e.preventDefault();
            document.getElementById('search').focus();
        } else if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
            e.preventDefault();
            if (state.currentId) send();
        } else if (e.key === 'Escape') {
            var scrim = document.getElementById('scrim');
            closeAuth();
            close(document.getElementById('sidebar'), scrim);
            close(document.getElementById('console'), scrim);
        }
    }

    function toggle(panel, scrim) {
        var open = panel.hasAttribute('data-open');
        if (open) { close(panel, scrim); } else { openPanel(panel, scrim); }
    }
    function openPanel(panel, scrim) {
        panel.setAttribute('data-open', '');
        scrim.setAttribute('data-show', '');
    }
    function close(panel, scrim) {
        panel.removeAttribute('data-open');
        if (!document.querySelector('[data-open]')) scrim.removeAttribute('data-show');
    }
    function openConsole() {
        var consoleEl = document.getElementById('console');
        var scrim = document.getElementById('scrim');
        if (!consoleEl || !scrim) return;
        if (window.matchMedia('(max-width: 1180px)').matches) {
            openPanel(consoleEl, scrim);
            close(document.getElementById('sidebar'), scrim);
        } else {
            consoleEl.removeAttribute('data-open');
            close(document.getElementById('sidebar'), scrim);
            scrim.removeAttribute('data-show');
        }
        var first = consoleEl.querySelector('#consoleForm .input, #consoleForm .select, #consoleForm .textarea, #consoleForm button, .send');
        if (first) first.focus();
    }

    /* ---------- authorize modal ---------- */

    function authModalHtml() {
        var schemes = securitySchemes();
        var rows = Object.keys(schemes).map(function (key) {
            var s = schemes[key] || {};
            return '<div class="authmodal__scheme">' +
                '<div class="authmodal__name">' + esc(key) +
                    '<span class="authmodal__kind">' + esc(authHint(s)) + '</span></div>' +
                '<input class="input" type="text" data-auth-scheme="' + esc(key) + '" ' +
                    'value="' + esc(authToken(key)) + '" placeholder="' + esc(authLabel(s)) + '" autocomplete="off">' +
            '</div>';
        }).join('');

        return '<div class="modal" id="authModal" hidden>' +
            '<div class="modal__card" role="dialog" aria-modal="true" aria-label="Authorize">' +
                '<div class="modal__head"><span>&#128274; Authorize</span>' +
                    '<button class="modal__close" id="authModalClose" type="button" aria-label="Close">&#10005;</button></div>' +
                '<div class="modal__body">' + rows + '</div>' +
                '<div class="modal__foot">' +
                    '<button class="btn-ghost" id="authClear" type="button">Clear all</button>' +
                    '<button class="btn-primary" id="authSave" type="button">Save</button>' +
                '</div>' +
            '</div></div>';
    }

    function openAuth() {
        var m = document.getElementById('authModal');
        if (!m) return;
        m.querySelectorAll('[data-auth-scheme]').forEach(function (i) { i.value = authToken(i.dataset.authScheme); });
        m.removeAttribute('hidden');
        var first = m.querySelector('[data-auth-scheme]');
        if (first) first.focus();
    }
    function closeAuth() {
        var m = document.getElementById('authModal');
        if (m) m.setAttribute('hidden', '');
    }
    function saveAuth() {
        var m = document.getElementById('authModal');
        if (!m) return;
        m.querySelectorAll('[data-auth-scheme]').forEach(function (i) { setAuthToken(i.dataset.authScheme, i.value); });
        afterAuthChange();
        closeAuth();
    }
    function clearAuth() {
        var m = document.getElementById('authModal');
        if (!m) return;
        m.querySelectorAll('[data-auth-scheme]').forEach(function (i) { i.value = ''; setAuthToken(i.dataset.authScheme, ''); });
        afterAuthChange();
    }
    function afterAuthChange() {
        updateAuthButton();
        if (state.currentId) renderConsole(entryById(state.currentId));
    }
    function updateAuthButton() {
        var btn = document.getElementById('authBtn');
        if (!btn) return;
        var on = anyAuthorized();
        btn.classList.toggle('is-on', on);
        btn.innerHTML = on ? '&#128274; Authorized' : '&#128275; Authorize';
    }

    /* ---------- navigation ---------- */

    function renderNav(filter) {
        var nav = document.getElementById('nav');
        var groups = groupsFor(filter);
        if (!groups.length) {
            nav.innerHTML = '<p class="nav__empty">No matching endpoints.</p>';
            return;
        }
        nav.innerHTML = groups.map(function (group) {
            var items = group.items.map(function (entry) {
                var current = entry.id === state.currentId ? ' aria-current="true"' : '';
                var dep = entry.op.deprecated ? ' is-deprecated' : '';
                var summary = operationLabel(entry);
                var summaryHtml = summary && summary !== entry.path
                    ? '<span class="nav-item__summary">' + esc(summary) + '</span>' : '';
                return '<li><button class="nav-item m-' + entry.method + dep + '" data-id="' + esc(entry.id) + '"' + current + '>' +
                    '<span class="method m-' + entry.method + '">' + entry.method + '</span>' +
                    '<span class="nav-item__main"><span class="nav-item__path">' + esc(entry.path) + '</span>' +
                    summaryHtml + '</span></button></li>';
            }).join('');
            return '<div class="nav-group"><h2 class="nav-group__title"><span>' + esc(group.tag) + '</span>' +
                '<span class="nav-group__count">' + group.items.length + '</span></h2><ul class="nav-list">' + items + '</ul></div>';
        }).join('');
    }

    /* ---------- documentation surface ---------- */

    function renderEmpty() {
        document.getElementById('doc').innerHTML =
            '<div class="doc__empty"><h2>{ } Pick an endpoint</h2>' +
            '<p>Choose a request on the left to read its contract, then try it live from the console.</p></div>';
    }

    function entryById(id) {
        return state.operations.filter(function (e) { return e.id === id; })[0];
    }

    function select(id) {
        state.currentId = id;
        var entry = entryById(id);
        if (!entry) return;

        document.querySelectorAll('.nav-item').forEach(function (n) {
            if (n.dataset.id === id) { n.setAttribute('aria-current', 'true'); } else { n.removeAttribute('aria-current'); }
        });

        renderDoc(entry);
        renderConsole(entry);
        var tryBtn = document.getElementById('topbarTry');
        if (tryBtn) tryBtn.removeAttribute('hidden');

        document.getElementById('responseMount').innerHTML = '';

        if (decodeURIComponent((location.hash || '').slice(1)) !== (entry.slug || '')) {
            history.pushState(null, '', '#' + entry.slug);
        }

        if (window.matchMedia('(max-width: 820px)').matches) {
            close(document.getElementById('sidebar'), document.getElementById('scrim'));
        }
    }

    /* A field table for any object/array schema; recurses into nested shapes. */
    function rowsFromSchema(schema) {
        if (!schema) return '';
        if (schema.type === 'array' && schema.items) {
            if (schema.items.properties) return rowsFromSchema(schema.items);
            return '<div class="row"><div class="row__name">items</div>' +
                '<div class="row__type"><b>' + esc(schemaType(schema.items)) + '</b></div></div>';
        }
        if (!schema.properties) return '';
        var required = schema.required || [];
        return Object.keys(schema.properties).map(function (name) {
            var prop = schema.properties[name] || {};
            var req = required.indexOf(name) !== -1 ? '<span class="row__req">required</span>' : '';

            var meta = [];
            if (prop.enum) meta.push('enum');
            if (isNullable(prop)) meta.push('nullable');
            var metaNote = meta.length ? ' · ' + meta.join(' · ') : '';

            var desc = prop.description ? '<div class="row__desc">' + inline(prop.description) + '</div>' : '';
            var enumList = prop.enum
                ? '<div class="row__enum">' + prop.enum.map(function (v) { return '<code>' + esc(v) + '</code>'; }).join(' ') + '</div>'
                : '';

            var children = (prop.type === 'object' && prop.properties) ? rowsFromSchema(prop)
                : (prop.type === 'array' && prop.items && prop.items.properties) ? rowsFromSchema(prop.items) : '';
            var childWrap = children ? '<div class="row--nested">' + children + '</div>' : '';

            return '<div class="row"><div class="row__name">' + esc(name) + req + '</div>' +
                '<div class="row__type"><b>' + esc(schemaType(prop)) + '</b>' + metaNote + '</div>' +
                desc + enumList + '</div>' + childWrap;
        }).join('');
    }

    function schemaSection(title, schema, mediaNote) {
        var rows = rowsFromSchema(schema);
        var body = rows || '<p class="row__desc">' + esc(schemaType(schema)) + '</p>';
        var note = mediaNote ? '<span class="spec-section__media">' + esc(mediaNote) + '</span>' : '';
        return '<section class="spec-section"><h2 class="spec-section__title">' + esc(title) + note + '</h2>' + body + '</section>';
    }

    /* A pretty-printed, syntax-highlighted example value (escape-first, like the
       try-it response pane). Used when a response has no schema to tabulate. */
    function exampleBlock(value) {
        var json;
        try { json = JSON.stringify(value, null, 2); } catch (e) { /* circular, fall through */ }
        if (json === undefined) json = String(value);
        return '<div class="response-block__caption">Example</div>' +
            '<pre class="example-code">' + highlightJson(esc(json)) + '</pre>';
    }

    function renderDoc(entry) {
        var op = entry.op;
        var auth = authForOp(op);
        var html = '<article class="endpoint">';

        var deprecated = op.deprecated ? '<span class="badge-deprecated">Deprecated</span>' : '';
        html += '<div class="request-line"><span class="method m-' + entry.method + '">' + entry.method + '</span>' +
            '<span class="path">' + pathSegments(entry.path) + '</span>' + deprecated +
            '<button class="endpoint__link" id="endpointLink" type="button" title="Copy endpoint link">Link</button>' +
            '<button class="endpoint__try" id="endpointTry" type="button">Try it</button></div>';
        html += '<h1 class="endpoint__summary">' + esc(op.summary || entry.path) + '</h1>';
        html += '<div class="endpoint__meta">' +
            '<span>' + esc(entry.tag) + '</span>' +
            (op.operationId ? '<span>' + esc(op.operationId) + '</span>' : '') +
            '<span>' + esc(entry.method.toUpperCase()) + '</span>' +
            '</div>';
        if (auth) html += '<div class="endpoint__auth">Requires authentication · ' + esc(auth.key) + '</div>';
        if (op.description) html += '<div class="endpoint__desc">' + block(op.description) + '</div>';

        var params = (op.parameters || []).filter(function (p) { return p.in === 'path' || p.in === 'query'; });
        if (params.length) {
            html += '<section class="spec-section"><h2 class="spec-section__title">Parameters</h2>';
            html += params.map(function (p) {
                var req = p.required ? '<span class="row__req">required</span>' : '';
                var meta = [];
                if (p.schema && p.schema.enum) meta.push('enum');
                if (isNullable(p.schema)) meta.push('nullable');
                var loc = '<b>' + esc(schemaType(p.schema)) + '</b> · ' + esc(p.in) + (meta.length ? ' · ' + meta.join(' · ') : '');
                var desc = p.description ? '<div class="row__desc">' + inline(p.description) + '</div>' : '';
                var enumList = (p.schema && p.schema.enum)
                    ? '<div class="row__enum">' + p.schema.enum.map(function (v) { return '<code>' + esc(v) + '</code>'; }).join(' ') + '</div>'
                    : '';
                return '<div class="row"><div class="row__name">' + esc(p.name) + req + '</div>' +
                    '<div class="row__type">' + loc + '</div>' + desc + enumList + '</div>';
            }).join('');
            html += '</section>';
        }

        var content = requestBodyContent(op);
        if (content && content.schema) {
            var mediaNote = content.mediaType === 'application/json' ? null : content.mediaType;
            html += schemaSection('Request body', content.schema, mediaNote);
        }

        var responses = op.responses || {};
        var codes = Object.keys(responses);
        if (codes.length) {
            html += '<section class="spec-section"><h2 class="spec-section__title">Responses</h2>';
            html += codes.map(function (code) {
                var r = responses[code] || {};
                var schema = responseSchema(r);
                var rows = schema ? rowsFromSchema(schema) : '';
                var example = responseExample(r);
                var detail = '';
                if (rows) {
                    detail = '<div class="response-block__schema">' + rows + '</div>';
                } else if (example !== undefined) {
                    detail = '<div class="response-block__schema response-block__example">' + exampleBlock(example) + '</div>';
                } else if (schema) {
                    detail = '<div class="response-block__schema"><p class="row__desc">' + esc(schemaType(schema)) + '</p></div>';
                }
                return '<div class="response-block">' +
                    '<div class="response-row"><span class="status-pill" data-class="' + statusClass(+code) + '">' + esc(code) +
                    '</span><span class="response-row__desc">' + inline(r.description || '') + '</span></div>' +
                    detail + '</div>';
            }).join('');
            html += '</section>';
        }

        html += '</article>';
        document.getElementById('doc').innerHTML = html;
        document.getElementById('doc').scrollTop = 0;
        document.getElementById('endpointTry').addEventListener('click', openConsole);
        document.getElementById('endpointLink').addEventListener('click', function () { copyEndpointLink(entry); });
    }

    function copyEndpointLink(entry) {
        var btn = document.getElementById('endpointLink');
        var url = location.origin + location.pathname + '#' + encodeURIComponent(entry.slug || slugFor(entry));

        copyText(url, function () {
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = 'Link'; }, 1400);
        });
    }

    /* ---------- console ---------- */

    function field(label, controlHtml, required, hint) {
        var req = required ? '<span class="req">required</span>' : '';
        var note = hint ? '<span class="field__hint">' + esc(hint) + '</span>' : '';
        return '<div class="field"><label class="field__label">' + esc(label) + req + note + '</label>' + controlHtml + '</div>';
    }

    /* The right control for one top-level body property, driven by its schema. */
    function bodyFieldControl(name, schema, required) {
        var attrs = 'data-kind="body-field" data-name="' + esc(name) + '"';
        var typeLbl = schemaType(schema);
        var control;

        if (isFileSchema(schema)) {
            var multiple = schema.type === 'array' ? ' multiple' : '';
            control = '<input class="input input--file" type="file" ' + attrs + ' data-ftype="file"' + multiple + '>';
        } else if (schema.enum) {
            var opts = ['<option value="">—</option>'].concat(schema.enum.map(function (v) {
                return '<option value="' + esc(v) + '">' + esc(v) + '</option>';
            }));
            control = '<select class="select" ' + attrs + ' data-ftype="enum">' + opts.join('') + '</select>';
        } else if (typesOf(schema)[0] === 'boolean') {
            control = '<select class="select" ' + attrs + ' data-ftype="boolean">' +
                '<option value="">—</option><option value="true">true</option><option value="false">false</option></select>';
        } else if (typesOf(schema)[0] === 'integer' || typesOf(schema)[0] === 'number') {
            control = '<input class="input" type="number" ' + attrs + ' data-ftype="number" placeholder="' + esc(typeLbl) + '">';
        } else if (typesOf(schema)[0] === 'object' || (schema.type === 'array' && schema.items && (schema.items.properties || schema.items.type === 'object'))) {
            var skeleton = JSON.stringify(sample(schema), null, 2);
            control = '<textarea class="textarea textarea--sm" ' + attrs + ' data-ftype="json" spellcheck="false">' + esc(skeleton) + '</textarea>';
        } else if (schema.type === 'array') {
            control = '<input class="input" ' + attrs + ' data-ftype="csv" placeholder="comma, separated, values">';
        } else {
            control = '<input class="input" type="text" ' + attrs + ' data-ftype="text" placeholder="' + esc(typeLbl) + '">';
        }

        return field(name, control, required, typeLbl);
    }

    function clearRequestInputs() {
        var form = document.getElementById('consoleForm');
        if (!form) return;

        form.querySelectorAll('[data-kind="path"], [data-kind="query"], [data-kind="body-field"], [data-kind="body-raw"]').forEach(function (control) {
            if (control.type === 'file') {
                control.value = '';
            } else if (control.tagName === 'SELECT') {
                control.selectedIndex = 0;
            } else {
                control.value = '';
            }
        });

        document.getElementById('responseMount').innerHTML = '';
        updateSnippet();

        var first = form.querySelector('[data-kind="path"], [data-kind="query"], [data-kind="body-field"], [data-kind="body-raw"]');
        if (first) first.focus();
    }

    function renderConsole(entry) {
        var op = entry.op;
        var form = document.getElementById('consoleForm');
        var html = '';

        var savedServer = store.get('server');
        var serverOptions = state.servers.map(function (url) {
            return '<option value="' + esc(url) + '"' + (url === savedServer ? ' selected' : '') + '>' + esc(url) + '</option>';
        }).join('');
        html += field('Server', '<select class="select" data-kind="server">' + serverOptions + '</select>');

        var auth = authForOp(op);
        if (auth) {
            var authed = !!authToken(auth.key);
            html += '<div class="authstate' + (authed ? ' is-on' : '') + '">' +
                '<span class="authstate__label">' + (authed ? 'Authorized' : 'Not authorized') + ' · ' + esc(auth.key) + '</span>' +
                '<button type="button" class="authstate__btn" id="consoleAuthBtn">' + (authed ? 'Edit' : 'Authorize') + '</button>' +
            '</div>';
        }

        var pathParams = pathParamNames(entry.path);
        if (pathParams.length) {
            html += '<div class="subhead">Path</div>';
            html += pathParams.map(function (name) {
                return field(name, '<input class="input" type="text" data-kind="path" data-name="' + esc(name) + '" placeholder="' + esc(name) + '">', true);
            }).join('');
        }

        var queryParams = (op.parameters || []).filter(function (p) { return p.in === 'query'; });
        if (queryParams.length) {
            html += '<div class="subhead">Query</div>';
            html += queryParams.map(function (p) {
                return field(p.name, '<input class="input" type="text" data-kind="query" data-name="' + esc(p.name) + '" placeholder="' +
                    esc(schemaType(p.schema)) + '">', p.required);
            }).join('');
        }

        var content = requestBodyContent(op);
        if (content && content.schema && BODY_METHODS[entry.method]) {
            var label = content.mediaType === 'multipart/form-data' ? 'Body · form-data' : 'Body · JSON';
            html += '<div class="subhead">' + label + '</div>';
            var props = content.schema.properties || {};
            var keys = Object.keys(props);
            if (keys.length) {
                var reqd = content.schema.required || [];
                html += keys.map(function (name) { return bodyFieldControl(name, props[name] || {}, reqd.indexOf(name) !== -1); }).join('');
            } else {
                var skeleton = JSON.stringify(sample(content.schema), null, 2);
                html += '<div class="field"><textarea class="textarea" data-kind="body-raw" spellcheck="false">' + esc(skeleton) + '</textarea></div>';
            }
        }

        var active = activeLang();
        var tabs = PRIMARY_LANGS.map(function (lang) {
            var on = lang === active;
            return '<button class="snippet__lang' + (on ? ' is-active' : '') + '" type="button" data-lang="' + lang +
                '" aria-pressed="' + (on ? 'true' : 'false') + '">' + esc(GENERATORS[lang].label) + '</button>';
        }).join('');
        var otherActive = OTHER_LANGS.indexOf(active) !== -1;
        var otherOpts = ['<option value="" disabled' + (otherActive ? '' : ' selected') + '>Other</option>']
            .concat(OTHER_LANGS.map(function (lang) {
                return '<option value="' + lang + '"' + (lang === active ? ' selected' : '') + '>' + esc(GENERATORS[lang].label) + '</option>';
            })).join('');
        var otherSelect = '<select class="snippet__other' + (otherActive ? ' is-active' : '') +
            '" id="snippetOther" aria-label="Other languages">' + otherOpts + '</select>';
        html += '<div class="snippet">' +
            '<div class="snippet__bar"><div class="snippet__langs" id="snippetLangs">' + tabs + otherSelect + '</div>' +
            '<button class="snippet__copy" id="snippetCopy" type="button">Copy</button></div>' +
            '<pre class="snippet__code" id="snippetCode"></pre></div>';

        form.innerHTML = html;
        var consoleAuthBtn = document.getElementById('consoleAuthBtn');
        if (consoleAuthBtn) consoleAuthBtn.addEventListener('click', openAuth);
        document.getElementById('snippetCopy').addEventListener('click', copySnippet);
        document.getElementById('snippetLangs').addEventListener('click', onLangClick);
        document.getElementById('snippetOther').addEventListener('change', onOtherChange);
        updateSnippet();
    }

    /* Coerce a raw input value into the JS value its schema type implies. */
    function coerce(ftype, raw) {
        switch (ftype) {
            case 'number': var n = Number(raw); return isNaN(n) ? raw : n;
            case 'boolean': return raw === 'true';
            case 'csv': return raw.split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
            case 'json': try { return JSON.parse(raw); } catch (e) { return raw; }
            default: return raw;
        }
    }

    /* Read the body fields into a normalized shape the senders/snippets understand:
       { mode: 'none' | 'json' | 'raw' | 'multipart', ... }. */
    function readBody(form, content) {
        var raw = form.querySelector('[data-kind="body-raw"]');
        if (raw) {
            var txt = (raw.value || '').trim();
            if (!txt) return { mode: 'none' };
            try { return { mode: 'json', value: JSON.parse(txt) }; }
            catch (e) { return { mode: 'raw', value: raw.value }; }
        }

        var inputs = form.querySelectorAll('[data-kind="body-field"]');
        if (!inputs.length) return { mode: 'none' };

        var multipart = content && content.mediaType === 'multipart/form-data';
        var json = {};
        var hasJson = false;
        var fields = [];
        var files = [];

        Array.prototype.forEach.call(inputs, function (input) {
            var name = input.dataset.name;
            if (input.dataset.ftype === 'file') {
                var names = input.files ? Array.prototype.map.call(input.files, function (f) { return f.name; }) : [];
                files.push({ name: name, filenames: names });
                return;
            }
            if (input.value === '') return;
            var val = coerce(input.dataset.ftype, input.value);
            json[name] = val;
            hasJson = true;
            fields.push({ name: name, value: typeof val === 'string' ? val : JSON.stringify(val) });
        });

        if (multipart) return { mode: 'multipart', fields: fields, files: files };
        if (!hasJson) return { mode: 'none' };
        return { mode: 'json', value: json };
    }

    function readForm() {
        var form = document.getElementById('consoleForm');
        var entry = entryById(state.currentId);
        var server = (form.querySelector('[data-kind="server"]') || {}).value || state.servers[0] || location.origin;

        var path = entry.path;
        form.querySelectorAll('[data-kind="path"]').forEach(function (input) {
            var v = input.value || '{' + input.dataset.name + '}';
            path = path.replace('{' + input.dataset.name + '}', encodeURIComponent(v));
        });

        var query = [];
        form.querySelectorAll('[data-kind="query"]').forEach(function (input) {
            if (input.value !== '') query.push(encodeURIComponent(input.dataset.name) + '=' + encodeURIComponent(input.value));
        });

        var headers = { Accept: 'application/json' };
        var content = requestBodyContent(entry.op);
        var body = BODY_METHODS[entry.method] ? readBody(form, content) : { mode: 'none' };
        if (body.mode === 'json' || body.mode === 'raw') headers['Content-Type'] = 'application/json';

        var auth = authForOp(entry.op);
        if (auth) {
            var token = authToken(auth.key);
            var s = auth.scheme || {};
            if (token) {
                if (s.type === 'apiKey' && s.in === 'query') {
                    query.push(encodeURIComponent(s.name || 'api_key') + '=' + encodeURIComponent(token));
                } else {
                    applyAuthHeader(headers, s, token);
                }
            }
        }

        var url = server.replace(/\/$/, '') + path + (query.length ? '?' + query.join('&') : '');
        return { method: entry.method, url: url, headers: headers, body: body };
    }

    /* ---------- snippet generation ---------- */

    function fieldsToObject(fields) {
        var o = {};
        fields.forEach(function (f) { o[f.name] = f.value; });
        return o;
    }
    function fileNames(file) {
        return file.filenames.length ? file.filenames : ['path/to/file'];
    }

    /* Indent every line after the first (a literal block sits inside a call). */
    function indentLines(text, prefix) {
        return text.split('\n').map(function (line, i) { return i === 0 ? line : prefix + line; }).join('\n');
    }
    function pad(depth) { var s = ''; while (depth-- > 0) s += '  '; return s; }

    /* Render a value as a PHP array / Python literal (2 spaces per level). */
    function toPhp(value, depth) {
        depth = depth || 1;
        if (value === null) return 'null';
        if (typeof value === 'boolean') return value ? 'true' : 'false';
        if (typeof value === 'number') return String(value);
        if (typeof value === 'string') return "'" + value.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
        if (Array.isArray(value)) {
            if (!value.length) return '[]';
            return '[\n' + value.map(function (v) { return pad(depth) + toPhp(v, depth + 1) + ','; }).join('\n') + '\n' + pad(depth - 1) + ']';
        }
        var keys = Object.keys(value);
        if (!keys.length) return '[]';
        return '[\n' + keys.map(function (k) {
            return pad(depth) + "'" + k.replace(/'/g, "\\'") + "' => " + toPhp(value[k], depth + 1) + ',';
        }).join('\n') + '\n' + pad(depth - 1) + ']';
    }
    function toPython(value, depth) {
        depth = depth || 1;
        if (value === null) return 'None';
        if (typeof value === 'boolean') return value ? 'True' : 'False';
        if (typeof value === 'number') return String(value);
        if (typeof value === 'string') return '"' + value.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"';
        if (Array.isArray(value)) {
            if (!value.length) return '[]';
            return '[\n' + value.map(function (v) { return pad(depth) + toPython(v, depth + 1) + ','; }).join('\n') + '\n' + pad(depth - 1) + ']';
        }
        var keys = Object.keys(value);
        if (!keys.length) return '{}';
        return '{\n' + keys.map(function (k) {
            return pad(depth) + '"' + k.replace(/"/g, '\\"') + '": ' + toPython(value[k], depth + 1) + ',';
        }).join('\n') + '\n' + pad(depth - 1) + '}';
    }
    function jsonLiteral(value) {
        return JSON.stringify(value, null, 2);
    }
    function sq(value) {
        return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    }
    function dq(value) {
        return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    }
    function tick(value) {
        return String(value).replace(/`/g, '\\`');
    }
    function javaTextBlock(value, indent) {
        indent = indent || '';
        return '"""\n' + String(value).split('\n').map(function (line) {
            return indent + line;
        }).join('\n') + '\n' + indent.replace(/  $/, '') + '"""';
    }

    function buildCurl(req) {
        var parts = ["curl -X " + req.method.toUpperCase() + " '" + req.url + "'"];
        Object.keys(req.headers).forEach(function (k) { parts.push("  -H '" + k + ': ' + req.headers[k] + "'"); });
        var b = req.body;
        if (b.mode === 'json') {
            parts.push("  --data '" + JSON.stringify(b.value) + "'");
        } else if (b.mode === 'raw') {
            parts.push("  --data '" + b.value.replace(/\n\s*/g, '') + "'");
        } else if (b.mode === 'multipart') {
            b.fields.forEach(function (f) { parts.push("  -F '" + f.name + '=' + String(f.value).replace(/\n\s*/g, '') + "'"); });
            b.files.forEach(function (f) { fileNames(f).forEach(function (fn) { parts.push("  -F '" + f.name + '=@' + fn + "'"); }); });
        }
        return parts.join(' \\\n');
    }

    function buildLaravel(req) {
        var headers = {};
        Object.keys(req.headers).forEach(function (k) { headers[k] = req.headers[k]; });
        var method = req.method.toLowerCase();
        var b = req.body;
        var url = req.url.replace(/'/g, "\\'");
        var segments = [];

        if (headers.Authorization && headers.Authorization.indexOf('Bearer ') === 0) {
            segments.push("withToken('" + headers.Authorization.slice(7).replace(/'/g, "\\'") + "')");
            delete headers.Authorization;
        }

        if (b.mode === 'multipart') {
            delete headers['Content-Type'];
            b.files.forEach(function (f) {
                var fn = fileNames(f)[0];
                segments.push("attach('" + f.name + "', file_get_contents('" + fn + "'), '" + fn + "')");
            });
            if (Object.keys(headers).length) segments.push('withHeaders(' + indentLines(toPhp(headers), '    ') + ')');
            segments.push('asMultipart()');
            segments.push(method + "('" + url + "', " + indentLines(toPhp(fieldsToObject(b.fields)), '    ') + ')');
        } else {
            // Passing an array body sets the JSON content type for us.
            if (b.mode === 'json') delete headers['Content-Type'];
            if (Object.keys(headers).length) segments.push('withHeaders(' + indentLines(toPhp(headers), '    ') + ')');
            if (b.mode === 'json') {
                segments.push(method + "('" + url + "', " + indentLines(toPhp(b.value), '    ') + ')');
            } else if (b.mode === 'raw') {
                segments.push("withBody('" + b.value.replace(/'/g, "\\'") + "', 'application/json')");
                segments.push(method + "('" + url + "')");
            } else {
                segments.push(method + "('" + url + "')");
            }
        }
        return 'use Illuminate\\Support\\Facades\\Http;\n\n$response = Http::' + segments.join('\n    ->') + ';';
    }

    function buildJs(req) {
        var b = req.body;
        var headers = {};
        Object.keys(req.headers).forEach(function (k) { headers[k] = req.headers[k]; });
        var pre = '';
        var bodyLine = '';

        if (b.mode === 'multipart') {
            delete headers['Content-Type'];
            var fd = ['const form = new FormData();'];
            b.fields.forEach(function (f) { fd.push('form.append("' + f.name.replace(/"/g, '\\"') + '", ' + JSON.stringify(String(f.value)) + ');'); });
            b.files.forEach(function (f) { fd.push('form.append("' + f.name.replace(/"/g, '\\"') + '", fileInput.files[0]); // ' + fileNames(f)[0]); });
            pre = fd.join('\n') + '\n\n';
            bodyLine = '  body: form,';
        } else if (b.mode === 'json') {
            bodyLine = '  body: JSON.stringify(' + indentLines(JSON.stringify(b.value, null, 2), '  ') + '),';
        } else if (b.mode === 'raw') {
            bodyLine = '  body: ' + JSON.stringify(b.value) + ',';
        }

        var lines = [pre + 'const response = await fetch("' + req.url.replace(/"/g, '\\"') + '", {'];
        lines.push('  method: "' + req.method.toUpperCase() + '",');
        var hkeys = Object.keys(headers);
        if (hkeys.length) {
            lines.push('  headers: {');
            lines.push(hkeys.map(function (k) {
                return '    "' + k.replace(/"/g, '\\"') + '": "' + String(headers[k]).replace(/"/g, '\\"') + '"';
            }).join(',\n'));
            lines.push('  },');
        }
        if (bodyLine) lines.push(bodyLine);
        lines.push('});');
        lines.push('const data = await response.json();');
        return lines.join('\n');
    }

    function buildPython(req) {
        var b = req.body;
        var headers = {};
        Object.keys(req.headers).forEach(function (k) { headers[k] = req.headers[k]; });
        var args = ['    "' + req.url.replace(/"/g, '\\"') + '",'];

        if (b.mode === 'multipart') {
            delete headers['Content-Type'];
            if (b.fields.length) args.push('    data=' + indentLines(toPython(fieldsToObject(b.fields)), '    ') + ',');
            var files = b.files.map(function (f) { return '"' + f.name + '": open("' + fileNames(f)[0] + '", "rb")'; });
            if (files.length) args.push('    files={' + files.join(', ') + '},');
        } else if (b.mode === 'json') {
            args.push('    json=' + indentLines(toPython(b.value), '    ') + ',');
        } else if (b.mode === 'raw') {
            args.push('    data=' + JSON.stringify(b.value) + ',');
        }
        if (Object.keys(headers).length) args.push('    headers=' + indentLines(toPython(headers), '    ') + ',');

        return 'import requests\n\nresponse = requests.' + req.method.toLowerCase() +
            '(\n' + args.join('\n') + '\n)\nprint(response.json())';
    }

    function buildHttpie(req) {
        var parts = ['http ' + req.method.toUpperCase() + ' "' + req.url.replace(/"/g, '\\"') + '"'];
        Object.keys(req.headers).forEach(function (k) {
            parts.push('  "' + k.replace(/"/g, '\\"') + ':' + String(req.headers[k]).replace(/"/g, '\\"') + '"');
        });

        var b = req.body;
        if (b.mode === 'json') {
            Object.keys(b.value).forEach(function (k) {
                var v = b.value[k];
                parts.push('  ' + k + ':=' + JSON.stringify(v));
            });
        } else if (b.mode === 'raw') {
            parts.push("  <<< '" + b.value.replace(/\n\s*/g, '') + "'");
        } else if (b.mode === 'multipart') {
            b.fields.forEach(function (f) { parts.push('  ' + f.name + '=' + JSON.stringify(String(f.value))); });
            b.files.forEach(function (f) { fileNames(f).forEach(function (fn) { parts.push('  ' + f.name + '@' + fn); }); });
        }

        return parts.join(' \\\n');
    }

    function buildRuby(req) {
        var b = req.body;
        var headers = {};
        Object.keys(req.headers).forEach(function (k) { headers[k] = req.headers[k]; });
        var lines = ["require 'net/http'", "require 'json'", '', "uri = URI('" + sq(req.url) + "')"];

        if (b.mode === 'multipart') {
            lines.push("request = Net::HTTP::" + req.method.charAt(0).toUpperCase() + req.method.slice(1).toLowerCase() + '.new(uri)');
            Object.keys(headers).forEach(function (k) { lines.push("request['" + sq(k) + "'] = '" + sq(headers[k]) + "'"); });
            lines.push('# Multipart file uploads are usually easier with a client gem such as Faraday.');
            lines.push('# Fields: ' + JSON.stringify(fieldsToObject(b.fields)));
            b.files.forEach(function (f) { lines.push("# Attach " + f.name + ': ' + fileNames(f).join(', ')); });
        } else {
            lines.push("request = Net::HTTP::" + req.method.charAt(0).toUpperCase() + req.method.slice(1).toLowerCase() + '.new(uri)');
            Object.keys(headers).forEach(function (k) { lines.push("request['" + sq(k) + "'] = '" + sq(headers[k]) + "'"); });
            if (b.mode === 'json') lines.push('request.body = JSON.generate(' + JSON.stringify(b.value) + ')');
            else if (b.mode === 'raw') lines.push('request.body = ' + JSON.stringify(b.value));
        }

        lines.push('');
        lines.push('response = Net::HTTP.start(uri.hostname, uri.port, use_ssl: uri.scheme == "https") do |http|');
        lines.push('  http.request(request)');
        lines.push('end');
        lines.push('');
        lines.push('puts response.body');
        return lines.join('\n');
    }

    function buildGo(req) {
        var b = req.body;
        var headers = {};
        Object.keys(req.headers).forEach(function (k) { headers[k] = req.headers[k]; });
        var imports = ['"fmt"', '"io"', '"net/http"'];
        var setup = [];
        var bodyExpr = 'nil';

        if (b.mode === 'json' || b.mode === 'raw') {
            imports.push('"bytes"');
            var payload = b.mode === 'json' ? jsonLiteral(b.value) : b.value;
            setup.push('payload := []byte(`' + tick(payload) + '`)');
            bodyExpr = 'bytes.NewBuffer(payload)';
        } else if (b.mode === 'multipart') {
            imports = ['"bytes"', '"fmt"', '"io"', '"mime/multipart"', '"net/http"', '"os"'];
            setup.push('body := &bytes.Buffer{}');
            setup.push('writer := multipart.NewWriter(body)');
            b.fields.forEach(function (f) { setup.push('writer.WriteField("' + dq(f.name) + '", "' + dq(f.value) + '")'); });
            b.files.forEach(function (f) {
                fileNames(f).forEach(function (fn) {
                    setup.push('file, _ := os.Open("' + dq(fn) + '")');
                    setup.push('defer file.Close()');
                    setup.push('part, _ := writer.CreateFormFile("' + dq(f.name) + '", "' + dq(fn.split('/').pop()) + '")');
                    setup.push('io.Copy(part, file)');
                });
            });
            setup.push('writer.Close()');
            headers['Content-Type'] = 'writer.FormDataContentType()';
            bodyExpr = 'body';
        }

        var lines = ['package main', '', 'import (']
            .concat(imports.sort().map(function (i) { return '  ' + i; }))
            .concat([')', '', 'func main() {']);
        setup.forEach(function (line) { lines.push('  ' + line); });
        if (setup.length) lines.push('');
        lines.push('  req, _ := http.NewRequest("' + req.method.toUpperCase() + '", "' + dq(req.url) + '", ' + bodyExpr + ')');
        Object.keys(headers).forEach(function (k) {
            var v = headers[k];
            if (v === 'writer.FormDataContentType()') lines.push('  req.Header.Set("' + dq(k) + '", writer.FormDataContentType())');
            else lines.push('  req.Header.Set("' + dq(k) + '", "' + dq(v) + '")');
        });
        lines.push('');
        lines.push('  resp, _ := http.DefaultClient.Do(req)');
        lines.push('  defer resp.Body.Close()');
        lines.push('  data, _ := io.ReadAll(resp.Body)');
        lines.push('  fmt.Println(string(data))');
        lines.push('}');
        return lines.join('\n');
    }

    function buildJava(req) {
        var b = req.body;
        var headers = {};
        Object.keys(req.headers).forEach(function (k) { headers[k] = req.headers[k]; });
        var lines = [
            'import java.net.URI;',
            'import java.net.http.HttpClient;',
            'import java.net.http.HttpRequest;',
            'import java.net.http.HttpResponse;',
            '',
            'var client = HttpClient.newHttpClient();',
        ];
        var publisher = 'HttpRequest.BodyPublishers.noBody()';

        if (b.mode === 'json' || b.mode === 'raw') {
            var payload = b.mode === 'json' ? jsonLiteral(b.value) : b.value;
            lines.push('var body = ' + javaTextBlock(payload, '  ') + ';');
            publisher = 'HttpRequest.BodyPublishers.ofString(body)';
        } else if (b.mode === 'multipart') {
            lines.push('// Build multipart/form-data with your preferred encoder, then pass it as the body publisher.');
            lines.push('// Fields: ' + JSON.stringify(fieldsToObject(b.fields)));
            b.files.forEach(function (f) { lines.push('// Attach ' + f.name + ': ' + fileNames(f).join(', ')); });
        }

        lines.push('');
        lines.push('var request = HttpRequest.newBuilder()');
        lines.push('  .uri(URI.create("' + dq(req.url) + '"))');
        Object.keys(headers).forEach(function (k) { lines.push('  .header("' + dq(k) + '", "' + dq(headers[k]) + '")'); });
        lines.push('  .method("' + req.method.toUpperCase() + '", ' + publisher + ')');
        lines.push('  .build();');
        lines.push('');
        lines.push('var response = client.send(request, HttpResponse.BodyHandlers.ofString());');
        lines.push('System.out.println(response.body());');
        return lines.join('\n');
    }

    function buildCsharp(req) {
        var b = req.body;
        var headers = {};
        Object.keys(req.headers).forEach(function (k) { headers[k] = req.headers[k]; });
        var lines = ['using System.Net.Http;', 'using System.Text;', '', 'using var client = new HttpClient();'];
        var content = 'null';

        if (b.mode === 'json' || b.mode === 'raw') {
            var payload = b.mode === 'json' ? jsonLiteral(b.value) : b.value;
            lines.push('var body = """');
            lines = lines.concat(String(payload).split('\n'));
            lines.push('""";');
            content = 'new StringContent(body, Encoding.UTF8, "application/json")';
        } else if (b.mode === 'multipart') {
            lines.push('using var form = new MultipartFormDataContent();');
            b.fields.forEach(function (f) { lines.push('form.Add(new StringContent("' + dq(f.value) + '"), "' + dq(f.name) + '");'); });
            b.files.forEach(function (f) { fileNames(f).forEach(function (fn) { lines.push('// form.Add(new StreamContent(File.OpenRead("' + dq(fn) + '")), "' + dq(f.name) + '", "' + dq(fn.split('/').pop()) + '");'); }); });
            content = 'form';
            delete headers['Content-Type'];
        }

        Object.keys(headers).forEach(function (k) {
            if (k.toLowerCase() !== 'content-type') lines.push('client.DefaultRequestHeaders.Add("' + dq(k) + '", "' + dq(headers[k]) + '");');
        });
        lines.push('');
        lines.push('var response = await client.SendAsync(new HttpRequestMessage(HttpMethod.' +
            req.method.charAt(0).toUpperCase() + req.method.slice(1).toLowerCase() + ', "' + dq(req.url) + '") { Content = ' + content + ' });');
        lines.push('Console.WriteLine(await response.Content.ReadAsStringAsync());');
        return lines.join('\n');
    }

    /* ---------- TypeScript types ---------- */

    function tsKey(k) {
        return /^[A-Za-z_$][A-Za-z0-9_$]*$/.test(k) ? k : JSON.stringify(k);
    }

    /* `requireAll` drops the `?` modifier: responses are guaranteed by the server
       (an absent OpenAPI `required` list doesn't mean "sometimes missing" — that's
       what `| null` is for), so their fields stay required. Requests honour the
       `required` list, where omission is meaningful. */
    function tsObject(schema, depth, requireAll) {
        var props = schema.properties || {};
        var keys = Object.keys(props);
        if (!keys.length) return 'Record<string, unknown>';
        var reqd = schema.required || [];
        var lines = keys.map(function (k) {
            var opt = requireAll || reqd.indexOf(k) !== -1 ? '' : '?';
            return pad(depth + 1) + tsKey(k) + opt + ': ' + tsType(props[k], depth + 1, requireAll) + ';';
        });
        return '{\n' + lines.join('\n') + '\n' + pad(depth) + '}';
    }

    /* An OpenAPI schema as a TypeScript type literal (2 spaces per level). */
    function tsType(schema, depth, requireAll) {
        depth = depth || 0;
        if (!schema) return 'unknown';
        var nul = isNullable(schema) ? ' | null' : '';
        if (schema.oneOf || schema.anyOf) {
            return ((schema.oneOf || schema.anyOf).map(function (s) { return tsType(s, depth, requireAll); }).join(' | ') || 'unknown') + nul;
        }
        if (schema.enum) {
            return schema.enum.map(function (v) {
                return typeof v === 'string' ? JSON.stringify(v) : String(v);
            }).join(' | ') + nul;
        }
        var t = typesOf(schema)[0];
        if (t === 'array') {
            var inner = schema.items ? tsType(schema.items, depth, requireAll) : 'unknown';
            var wrapped = /[ |]/.test(inner) && inner.charAt(0) !== '{' ? '(' + inner + ')' : inner;
            return wrapped + '[]' + nul;
        }
        if (t === 'object' || schema.properties) return tsObject(schema, depth, requireAll) + nul;
        if (t === 'integer' || t === 'number') return 'number' + nul;
        if (t === 'boolean') return 'boolean' + nul;
        if (t === 'string') {
            if (schema.format === 'date' || schema.format === 'date-time') return 'Date' + nul;
            return 'string' + nul;
        }
        return 'unknown' + nul;
    }

    /* Does the schema carry a date/date-time anywhere? Drives whether the fetch
       wrapper needs the date-reviving JSON parse. */
    function schemaHasDate(schema, seen) {
        if (!schema || typeof schema !== 'object') return false;
        if (schema.format === 'date' || schema.format === 'date-time') return true;
        seen = seen || [];
        if (seen.indexOf(schema) !== -1) return false;
        seen.push(schema);
        if (schema.items && schemaHasDate(schema.items, seen)) return true;
        if (schema.properties) {
            var keys = Object.keys(schema.properties);
            for (var i = 0; i < keys.length; i++) {
                if (schemaHasDate(schema.properties[keys[i]], seen)) return true;
            }
        }
        var unions = schema.oneOf || schema.anyOf || schema.allOf;
        if (unions) {
            for (var j = 0; j < unions.length; j++) {
                if (schemaHasDate(unions[j], seen)) return true;
            }
        }
        return false;
    }

    function isDateField(schema) {
        return typesOf(schema)[0] === 'string' && (schema.format === 'date' || schema.format === 'date-time');
    }

    /* `new Date(x)`, guarding null when the field allows it. */
    function dateFieldExpr(schema, acc) {
        return isNullable(schema) ? acc + ' ? new Date(' + acc + ') : null' : 'new Date(' + acc + ')';
    }

    /* `{ ...src, <date overrides> }` — only the date-bearing props are rewritten. */
    function spreadLiteral(schema, src, pad, paren, depth) {
        var inner = pad + '  ';
        var props = schema.properties || {};
        var parts = ['...' + src + ','];
        Object.keys(props).forEach(function (k) {
            if (schemaHasDate(props[k])) parts.push(k + ': ' + convertExpr(props[k], src + '.' + k, inner, depth) + ',');
        });
        return (paren ? '({' : '{') + '\n' + inner + parts.join('\n' + inner) + '\n' + pad + (paren ? '})' : '}');
    }

    /* An expression that yields `acc` with its dates converted. Assumes the
       subtree actually contains a date (callers gate on `schemaHasDate`). */
    function convertExpr(schema, acc, pad, depth) {
        if (isDateField(schema)) return dateFieldExpr(schema, acc);
        var t = typesOf(schema)[0];
        if (t === 'array' && schema.items && schemaHasDate(schema.items)) {
            var item = schema.items;
            var v = depth ? 'item' + depth : 'item';
            if (typesOf(item)[0] === 'object' || item.properties) {
                return acc + '.map((' + v + ': any) => ' + spreadLiteral(item, v, pad, true, depth + 1) + ')';
            }
            return acc + '.map((' + v + ': any) => ' + dateFieldExpr(item, v) + ')';
        }
        var lit = spreadLiteral(schema, acc, pad, false, depth);
        return isNullable(schema) ? acc + ' ? ' + lit + ' : ' + acc : lit;
    }

    /* The wrapper lines that parse the body and hydrate its dates in place. */
    function hydrationLines(schema, resType) {
        if (typesOf(schema)[0] === 'array' && schema.items && schemaHasDate(schema.items)) {
            return ['  const data = (await response.json()) as ' + resType + ';',
                '  return ' + convertExpr(schema, 'data', '  ', 0) + ';'];
        }
        var lines = ['  const data = (await response.json()) as ' + resType + ';'];
        var props = schema.properties || {};
        Object.keys(props).forEach(function (k) {
            if (schemaHasDate(props[k])) lines.push('  data.' + k + ' = ' + convertExpr(props[k], 'data.' + k, '  ', 0) + ';');
        });
        lines.push('  return data;');
        return lines;
    }

    /* A named declaration: `interface` for plain objects, `type` for everything else. */
    function tsDeclaration(name, schema, requireAll) {
        var t = typesOf(schema)[0];
        if ((t === 'object' || schema.properties) && !isNullable(schema) && !schema.oneOf && !schema.anyOf) {
            return 'interface ' + name + ' ' + tsObject(schema, 0, requireAll);
        }
        return 'type ' + name + ' = ' + tsType(schema, 0, requireAll) + ';';
    }

    /* First 2xx response carrying a schema. */
    function successSchema(op) {
        var responses = op.responses || {};
        var codes = Object.keys(responses);
        for (var i = 0; i < codes.length; i++) {
            if (codes[i].charAt(0) === '2') {
                var s = responseSchema(responses[codes[i]]);
                if (s) return s;
            }
        }
        return null;
    }

    function pascalCase(s) {
        var parts = String(s || 'Endpoint').replace(/[^A-Za-z0-9]+/g, ' ').trim().split(' ');
        return parts.map(function (w) { return w.charAt(0).toUpperCase() + w.slice(1); }).join('') || 'Endpoint';
    }

    function buildTypeScript(req) {
        var entry = entryById(state.currentId);
        var op = entry.op;
        // Drop the noise-word "Controller" so names read as ProductIndex, not
        // ProductControllerIndex. Guard against emptying a controller literally
        // named "Controller".
        var base = pascalCase(op.operationId || (entry.method + ' ' + entry.path));
        var trimmed = base.replace(/Controller/g, '');
        if (trimmed) base = trimmed;
        var fnName = base.charAt(0).toLowerCase() + base.slice(1);
        var blocks = [];

        var content = requestBodyContent(op);
        var hasBody = !!(content && content.schema && BODY_METHODS[entry.method]);
        var multipart = hasBody && content.mediaType === 'multipart/form-data';
        var reqType = base + 'Request';
        if (hasBody) blocks.push(tsDeclaration(reqType, content.schema, false));

        var resSchema = successSchema(op);
        var resType = base + 'Response';
        if (resSchema) blocks.push(tsDeclaration(resType, resSchema, true));

        // Hydrate dates only when the response actually carries one.
        var hydrate = resSchema && schemaHasDate(resSchema);

        var headers = {};
        Object.keys(req.headers).forEach(function (k) { headers[k] = req.headers[k]; });
        if (multipart) delete headers['Content-Type'];

        var ret = resSchema ? 'Promise<' + resType + '>' : 'Promise<void>';
        var lines = ['async function ' + fnName + '(' + (hasBody ? 'body: ' + reqType : '') + '): ' + ret + ' {'];

        if (multipart) {
            lines.push('  const form = new FormData();');
            lines.push('  Object.entries(body).forEach(([k, v]) => form.append(k, v as string | Blob));');
            lines.push('');
        }

        lines.push('  const response = await fetch("' + req.url.replace(/"/g, '\\"') + '", {');
        lines.push('    method: "' + req.method.toUpperCase() + '",');
        var hkeys = Object.keys(headers);
        if (hkeys.length) {
            lines.push('    headers: {');
            lines.push(hkeys.map(function (k) {
                return '      "' + k.replace(/"/g, '\\"') + '": "' + String(headers[k]).replace(/"/g, '\\"') + '"';
            }).join(',\n'));
            lines.push('    },');
        }
        if (multipart) lines.push('    body: form,');
        else if (hasBody) lines.push('    body: JSON.stringify(body),');
        lines.push('  });');
        lines.push('');
        lines.push('  if (!response.ok) throw new Error(`HTTP ${response.status} ${response.statusText}`);');
        if (resSchema && hydrate) {
            lines.push('');
            lines = lines.concat(hydrationLines(resSchema, resType));
        } else if (resSchema) {
            lines.push('  return response.json();');
        }
        lines.push('}');

        blocks.push(lines.join('\n'));
        return blocks.join('\n\n');
    }

    var GENERATORS = {
        curl: { label: 'cURL', build: buildCurl },
        php: { label: 'PHP', build: buildLaravel },
        js: { label: 'JS', build: buildJs },
        typescript: { label: 'TypeScript', build: buildTypeScript },
        python: { label: 'Python', build: buildPython },
        go: { label: 'Go', build: buildGo },
        ruby: { label: 'Ruby', build: buildRuby },
        java: { label: 'Java', build: buildJava },
        csharp: { label: 'C#', build: buildCsharp },
        httpie: { label: 'HTTPie', build: buildHttpie },
    };
    /* Languages shown as tabs; the rest live in the "Other" dropdown. */
    var PRIMARY_LANGS = ['curl', 'php', 'js', 'typescript'];
    var OTHER_LANGS = ['python', 'go', 'ruby', 'java', 'csharp', 'httpie'];
    var LANG_ORDER = PRIMARY_LANGS.concat(OTHER_LANGS);

    function activeLang() {
        var saved = store.get('lang');
        return GENERATORS[saved] ? saved : 'curl';
    }

    function onLangClick(e) {
        var btn = e.target.closest('.snippet__lang');
        if (!btn) return;
        store.set('lang', btn.dataset.lang);
        document.querySelectorAll('.snippet__lang').forEach(function (b) {
            var on = b === btn;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        var other = document.getElementById('snippetOther');
        if (other) { other.classList.remove('is-active'); other.value = ''; }
        updateSnippet();
    }

    function onOtherChange(e) {
        var lang = e.target.value;
        if (!GENERATORS[lang]) return;
        store.set('lang', lang);
        document.querySelectorAll('.snippet__lang').forEach(function (b) {
            b.classList.remove('is-active');
            b.setAttribute('aria-pressed', 'false');
        });
        e.target.classList.add('is-active');
        updateSnippet();
    }

    /* Lightweight, language-agnostic syntax colouring for the request snippets.
       Escape-first like highlightJson: it runs on
       already-escaped text and wraps tokens in the fixed tok-* span set, so
       textContent (what Copy reads) still returns the verbatim source. */
    function highlightCode(escaped) {
        var re = /(\/\/[^\n]*|#[^\n]*)|(&quot;(?:\\.|(?!&quot;).)*&quot;|&#39;(?:\\.|(?!&#39;).)*&#39;)|\b(true|false|null|None|True|False|nil|const|let|var|await|async|function|return|use|using|import|from|new|print|puts|def|class|interface|type|Promise|throw|void|extends|as|package|func|defer|public|static|final)\b|(\b\d+(?:\.\d+)?\b)/g;
        return escaped.replace(re, function (m, comment, str, kw, num) {
            if (comment) return '<span class="tok-comment">' + comment + '</span>';
            if (str) return '<span class="tok-str">' + str + '</span>';
            if (kw) return '<span class="tok-kw">' + kw + '</span>';
            if (num) return '<span class="tok-num">' + num + '</span>';
            return m;
        });
    }

    function updateSnippet() {
        var code = document.getElementById('snippetCode');
        if (code) code.innerHTML = highlightCode(esc(GENERATORS[activeLang()].build(readForm())));
    }

    function copySnippet() {
        var text = document.getElementById('snippetCode').textContent;
        var btn = document.getElementById('snippetCopy');
        copyText(text, function () {
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = 'Copy'; }, 1400);
        });
    }

    function copyText(text, done) {
        fallbackCopy(text, done);
    }

    function fallbackCopy(text, done) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            document.execCommand('copy');
            done();
        } catch (e) {
            // Ignore: the button simply keeps its original label.
        }

        document.body.removeChild(textarea);
    }

    /* ---------- send ---------- */

    function send() {
        var req = readForm();
        var btn = document.getElementById('send');
        var head = document.getElementById('consoleHead');
        var mount = document.getElementById('responseMount');

        btn.disabled = true;
        head.setAttribute('data-busy', '');
        mount.innerHTML =
            '<div class="response response--pending" aria-live="polite">' +
                '<div class="response__status">' +
                    '<span class="response__label">Response</span>' +
                    '<span class="response__spinner"></span>' +
                    '<span class="response__text">Waiting for ' + esc(req.method.toUpperCase()) + '</span>' +
                '</div>' +
            '</div>';
        revealResponse();

        var options = { method: req.method.toUpperCase(), headers: {} };
        Object.keys(req.headers).forEach(function (k) { options.headers[k] = req.headers[k]; });

        var b = req.body;
        if (b.mode === 'json') {
            options.body = JSON.stringify(b.value);
        } else if (b.mode === 'raw') {
            options.body = b.value;
        } else if (b.mode === 'multipart') {
            var fd = new FormData();
            b.fields.forEach(function (f) { fd.append(f.name, f.value); });
            document.getElementById('consoleForm')
                .querySelectorAll('[data-kind="body-field"][data-ftype="file"]')
                .forEach(function (input) {
                    if (input.files) Array.prototype.forEach.call(input.files, function (file) { fd.append(input.dataset.name, file); });
                });
            delete options.headers['Content-Type']; // let the browser set the multipart boundary
            options.body = fd;
        }

        var started = performance.now();
        fetch(req.url, options).then(function (res) {
            return res.text().then(function (text) {
                renderResponse(res, text, Math.round(performance.now() - started));
            });
        }).catch(function (err) {
            renderError(err, req.url);
        }).then(function () {
            btn.disabled = false;
            head.removeAttribute('data-busy');
        });
    }

    function formatBytes(n) {
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / (1024 * 1024)).toFixed(2) + ' MB';
    }

    /* Colourise pretty-printed JSON. Operates on already-escaped text (so the
       string delimiter is &quot;, not "), then wraps tokens in a fixed safe tag
       set — same escape-first contract as the markdown helpers. */
    function highlightJson(escaped) {
        var re = /&quot;(?:\\.|(?!&quot;).)*&quot;(\s*:)?|\b(?:true|false)\b|\bnull\b|-?\d+(?:\.\d+)?(?:[eE][+\-]?\d+)?/g;
        return escaped.replace(re, function (m) {
            if (m.charAt(0) === '&') {
                if (/:\s*$/.test(m)) {
                    var end = m.lastIndexOf('&quot;') + 6; // wrap the key, leave the colon bare
                    return '<span class="tok-key">' + m.slice(0, end) + '</span>' + m.slice(end);
                }
                return '<span class="tok-str">' + m + '</span>';
            }
            if (m === 'true' || m === 'false') return '<span class="tok-bool">' + m + '</span>';
            if (m === 'null') return '<span class="tok-null">' + m + '</span>';
            return '<span class="tok-num">' + m + '</span>';
        });
    }

    function revealResponse() {
        var mount = document.getElementById('responseMount');
        var body = document.getElementById('consoleBody');
        if (!mount || !body) return;

        requestAnimationFrame(function () {
            var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            body.scrollTo({
                top: Math.max(mount.offsetTop - 12, 0),
                behavior: reduced ? 'auto' : 'smooth',
            });
        });
    }

    function jsonType(value) {
        if (value === null) return 'null';
        if (Array.isArray(value)) return 'array';
        return typeof value;
    }

    function jsonScalar(value) {
        var type = jsonType(value);
        if (type === 'string') return '<span class="tok-str">' + esc(JSON.stringify(value)) + '</span>';
        if (type === 'number') return '<span class="tok-num">' + esc(value) + '</span>';
        if (type === 'boolean') return '<span class="tok-bool">' + esc(value) + '</span>';
        if (type === 'null') return '<span class="tok-null">null</span>';
        return '<span class="tok-null">' + esc(String(value)) + '</span>';
    }

    function jsonKeyLabel(key) {
        if (key === null || key === undefined) return '';
        return typeof key === 'number' ? '[' + key + ']' : key;
    }

    function jsonNode(value, key, depth) {
        var type = jsonType(value);
        var keyLabel = jsonKeyLabel(key);
        var keyHtml = keyLabel !== ''
            ? '<span class="response-json__key">' + esc(keyLabel) + '</span><span class="response-json__colon">:</span>'
            : '<span class="response-json__key">root</span>';

        if (type !== 'object' && type !== 'array') {
            return '<div class="response-json__row">' + keyHtml + ' ' + jsonScalar(value) + '</div>';
        }

        var isArray = type === 'array';
        var items = isArray ? value : Object.keys(value);
        var count = isArray ? value.length : items.length;
        var summary = count + ' ' + (isArray ? (count === 1 ? 'item' : 'items') : (count === 1 ? 'field' : 'fields'));
        var empty = count === 0;
        var open = depth < 2 && !empty ? ' open' : '';
        var children = '';

        if (!empty) {
            children = (isArray ? value.map(function (item, i) {
                return jsonNode(item, i, depth + 1);
            }) : Object.keys(value).map(function (name) {
                return jsonNode(value[name], name, depth + 1);
            })).join('');
        }

        return '<details class="response-json response-json--' + type + '"' + open + '>' +
            '<summary>' + keyHtml +
                ' <span class="response-json__brace">' + esc(isArray ? '[' : '{') + '</span>' +
                ' <span class="response-json__meta">' + esc(summary) + '</span>' +
                (empty ? '<span class="response-json__empty">empty</span>' : '') +
            '</summary>' +
            (empty ? '' : '<div class="response-json__children">' + children + '</div>') +
            '<div class="response-json__end">' + esc(isArray ? ']' : '}') + '</div>' +
        '</details>';
    }

    function jsonTree(value) {
        return '<div class="response__tree">' + jsonNode(value, null, 0) + '</div>';
    }

    function renderResponse(res, text, ms) {
        var isJson = false;
        var parsedJson = null;
        var body = text;
        try { parsedJson = JSON.parse(text); body = JSON.stringify(parsedJson, null, 2); isJson = true; } catch (e) { /* keep raw */ }

        var size = formatBytes(new Blob([text]).size);
        var ctype = (res.headers.get('content-type') || '').split(';')[0].trim();
        var ctypeTag = ctype ? '<span class="response__ctype">' + esc(ctype) + '</span>' : '';

        var headers = [];
        res.headers.forEach(function (value, key) { headers.push({ key: key, value: value }); });
        var headerRows = headers.length
            ? headers.map(function (h) {
                return '<div class="hrow"><span class="hrow__key">' + esc(h.key) + '</span>' +
                    '<span class="hrow__val">' + esc(h.value) + '</span></div>';
            }).join('')
            : '<div class="response__empty">No headers</div>';

        var bodyHtml = text === ''
            ? '<div class="response__empty">No content</div>'
            : (isJson ? jsonTree(parsedJson) : '<pre class="response__body">' + esc(body) + '</pre>');
        var copyBtn = text === '' ? '' : '<button class="response__copy" type="button">Copy</button>';
        var treeControls = isJson
            ? '<span class="response__tree-controls">' +
                '<button class="response__tree-action" type="button" data-json-action="expand">Expand all</button>' +
                '<button class="response__tree-action" type="button" data-json-action="collapse">Collapse all</button>' +
            '</span>'
            : '';

        document.getElementById('responseMount').innerHTML =
            '<div class="response" aria-live="polite">' +
                '<div class="response__status">' +
                    '<span class="response__label">Response</span>' +
                    '<span class="response__code" data-class="' + statusClass(res.status) + '">' + esc(res.status) + '</span>' +
                    '<span class="response__text">' + esc(res.statusText || '') + '</span>' +
                    ctypeTag +
                    '<span class="response__meta">' + esc(size) + ' · ' + ms + ' ms</span>' +
                '</div>' +
                '<details open><summary>Body' + treeControls + copyBtn + '</summary>' + bodyHtml + '</details>' +
                '<details><summary>Headers <span class="summary__count">' + headers.length + '</span></summary>' +
                    '<div class="response__headers">' + headerRows + '</div></details>' +
            '</div>';
        revealResponse();

        var btn = document.querySelector('.response__copy');
        if (btn) btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation(); // don't toggle the <details>
            copyText(body, function () {
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = 'Copy'; }, 1400);
            });
        });

        document.querySelectorAll('.response__tree-action').forEach(function (control) {
            control.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var open = control.dataset.jsonAction === 'expand';
                document.querySelectorAll('.response-json').forEach(function (node) {
                    node.open = open;
                });
            });
        });
    }

    function renderError(err, url) {
        document.getElementById('responseMount').innerHTML =
            '<div class="response" aria-live="polite"><div class="response__status"><span class="response__label">Response</span><span class="response__code" data-class="x">—</span>' +
            '<span>Request failed</span></div><div class="response__hint">Couldn’t reach <b>' + esc(url) + '</b>. ' +
            'This is usually CORS: the API must allow requests from this docs origin (' + esc(location.origin) +
            '), or the server URL is wrong. Browser detail: ' + esc(err && err.message) + '</div></div>';
        revealResponse();
    }

    /* ---------- boot ---------- */

    function fail(message) {
        app.dataset.state = 'error';
        var msg = app.querySelector('.boot__msg');
        if (msg) msg.textContent = message;
    }

    fetch(cfg.specUrl, { headers: { Accept: 'application/json' } })
        .then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function (spec) {
            state.spec = spec;
            buildOperations();
            renderShell();
        })
        .catch(function (err) {
            fail('Could not load the API spec (' + (err && err.message) + ').');
        });
})();
