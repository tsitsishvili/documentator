/*
 * Documentator — built-in explorer.
 * Fetches the OpenAPI document and renders the reading surface + try-it console.
 * No framework, no build step.
 */
(function () {
    'use strict';

    var METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];
    var BODY_METHODS = { post: 1, put: 1, patch: 1, delete: 1 };

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
                '<div class="topbar__actions">' + authBtn +
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
                    '<div class="console__head" id="consoleHead"><span class="console__dot"></span>Request' +
                        '<button class="console__close" id="consoleClose" aria-label="Close console">&#10005;</button></div>' +
                    '<div class="console__body" id="consoleBody">' +
                        '<div id="consoleForm"></div><div id="responseMount"></div>' +
                    '</div>' +
                    '<div class="console__foot"><button class="send" id="send">Send request</button></div>' +
                '</aside>' +
            '</div>'
        ));
        app.appendChild(el('<div class="scrim" id="scrim"></div>'));
        if (hasSecuritySchemes()) app.appendChild(el(authModalHtml()));

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

        var sidebar = document.getElementById('sidebar');
        var consoleEl = document.getElementById('console');
        var scrim = document.getElementById('scrim');
        document.getElementById('menuBtn').addEventListener('click', function () { toggle(sidebar, scrim); });
        document.getElementById('consoleClose').addEventListener('click', function () { close(consoleEl, scrim); });
        scrim.addEventListener('click', function () { close(sidebar, scrim); close(consoleEl, scrim); });

        var form = document.getElementById('consoleForm');
        form.addEventListener('input', onFormInput);
        form.addEventListener('change', updateSnippet); // selects + file pickers

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
        if (open) { close(panel, scrim); } else { panel.setAttribute('data-open', ''); scrim.setAttribute('data-show', ''); }
    }
    function close(panel, scrim) {
        panel.removeAttribute('data-open');
        if (!document.querySelector('[data-open]')) scrim.removeAttribute('data-show');
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
            nav.innerHTML = '<p class="console__empty" style="color:var(--mist)">No matching endpoints.</p>';
            return;
        }
        nav.innerHTML = groups.map(function (group) {
            var items = group.items.map(function (entry) {
                var current = entry.id === state.currentId ? ' aria-current="true"' : '';
                var dep = entry.op.deprecated ? ' is-deprecated' : '';
                return '<li><button class="nav-item m-' + entry.method + dep + '" data-id="' + esc(entry.id) + '"' + current + '>' +
                    '<span class="method m-' + entry.method + '">' + entry.method + '</span>' +
                    '<span class="nav-item__path">' + esc(entry.path) + '</span></button></li>';
            }).join('');
            return '<div class="nav-group"><h2 class="nav-group__title">' + esc(group.tag) + '</h2><ul class="nav-list">' + items + '</ul></div>';
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
            '<span class="path">' + pathSegments(entry.path) + '</span>' + deprecated + '</div>';
        html += '<h1 class="endpoint__summary">' + esc(op.summary || entry.path) + '</h1>';
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
        var tabs = LANG_ORDER.map(function (lang) {
            var on = lang === active;
            return '<button class="snippet__lang' + (on ? ' is-active' : '') + '" type="button" data-lang="' + lang +
                '" aria-pressed="' + (on ? 'true' : 'false') + '">' + esc(GENERATORS[lang].label) + '</button>';
        }).join('');
        html += '<div class="snippet">' +
            '<div class="snippet__bar"><div class="snippet__langs" id="snippetLangs">' + tabs + '</div>' +
            '<button class="snippet__copy" id="snippetCopy" type="button">Copy</button></div>' +
            '<pre class="snippet__code" id="snippetCode"></pre></div>';

        form.innerHTML = html;
        var consoleAuthBtn = document.getElementById('consoleAuthBtn');
        if (consoleAuthBtn) consoleAuthBtn.addEventListener('click', openAuth);
        document.getElementById('snippetCopy').addEventListener('click', copySnippet);
        document.getElementById('snippetLangs').addEventListener('click', onLangClick);
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

    var GENERATORS = {
        curl: { label: 'cURL', build: buildCurl },
        laravel: { label: 'Laravel', build: buildLaravel },
        js: { label: 'JavaScript', build: buildJs },
        python: { label: 'Python', build: buildPython },
    };
    var LANG_ORDER = ['curl', 'laravel', 'js', 'python'];

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
        updateSnippet();
    }

    /* Lightweight, language-agnostic syntax colouring for the request snippets
       (curl / PHP / JS / Python). Escape-first like highlightJson: it runs on
       already-escaped text and wraps tokens in the fixed tok-* span set, so
       textContent (what Copy reads) still returns the verbatim source. */
    function highlightCode(escaped) {
        var re = /(#[^\n]*)|(&quot;(?:\\.|(?!&quot;).)*&quot;|&#39;(?:\\.|(?!&#39;).)*&#39;)|\b(true|false|null|None|True|False|const|let|var|await|async|function|return|use|import|from|new|print|def|class)\b|(\b\d+(?:\.\d+)?\b)/g;
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
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function () {
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = 'Copy'; }, 1400);
            });
        }
    }

    /* ---------- send ---------- */

    function send() {
        var req = readForm();
        var btn = document.getElementById('send');
        var head = document.getElementById('consoleHead');
        var mount = document.getElementById('responseMount');

        btn.disabled = true;
        head.setAttribute('data-busy', '');
        mount.innerHTML = '';

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

    function renderResponse(res, text, ms) {
        var isJson = false;
        var body = text;
        try { body = JSON.stringify(JSON.parse(text), null, 2); isJson = true; } catch (e) { /* keep raw */ }

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
            : '<pre class="response__body">' + (isJson ? highlightJson(esc(body)) : esc(body)) + '</pre>';
        var copyBtn = text === '' ? '' : '<button class="response__copy" type="button">Copy</button>';

        document.getElementById('responseMount').innerHTML =
            '<div class="response">' +
                '<div class="response__status">' +
                    '<span class="response__code" data-class="' + statusClass(res.status) + '">' + esc(res.status) + '</span>' +
                    '<span class="response__text">' + esc(res.statusText || '') + '</span>' +
                    ctypeTag +
                    '<span class="response__meta">' + esc(size) + ' · ' + ms + ' ms</span>' +
                '</div>' +
                '<details open><summary>Body' + copyBtn + '</summary>' + bodyHtml + '</details>' +
                '<details><summary>Headers <span class="summary__count">' + headers.length + '</span></summary>' +
                    '<div class="response__headers">' + headerRows + '</div></details>' +
            '</div>';

        var btn = document.querySelector('.response__copy');
        if (btn) btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation(); // don't toggle the <details>
            if (!navigator.clipboard) return;
            navigator.clipboard.writeText(body).then(function () {
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = 'Copy'; }, 1400);
            });
        });
    }

    function renderError(err, url) {
        document.getElementById('responseMount').innerHTML =
            '<div class="response"><div class="response__status"><span class="response__code" data-class="x">—</span>' +
            '<span>Request failed</span></div><div class="response__hint">Couldn’t reach <b>' + esc(url) + '</b>. ' +
            'This is usually CORS: the API must allow requests from this docs origin (' + esc(location.origin) +
            '), or the server URL is wrong. Browser detail: ' + esc(err && err.message) + '</div></div>';
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
