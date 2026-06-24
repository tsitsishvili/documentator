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

    function schemaType(schema) {
        if (!schema) return 'any';
        if (schema.type === 'array') return (schema.items && schema.items.type ? schema.items.type : 'any') + '[]';
        return schema.type || 'object';
    }

    /* Remember the auth token + server per docs page. */
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
        switch (schema.type) {
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
                return schema.format === 'date-time' ? '2026-01-01T00:00:00Z' : 'string';
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

    function authForOp(op) {
        if (!op.security || !op.security.length) return null;
        var key = Object.keys(op.security[0] || {})[0];
        if (!key) return null;
        var schemes = (state.spec.components && state.spec.components.securitySchemes) || {};
        return { key: key, scheme: schemes[key] || { type: 'http', scheme: 'bearer' } };
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
        app.appendChild(el(
            '<header class="topbar">' +
                '<button class="topbar__menu" id="menuBtn" aria-label="Toggle navigation">&#9776;</button>' +
                '<div class="topbar__brand"><b>{ }</b>' + esc(info.title || cfg.title || 'API') + '</div>' +
                version +
                '<a class="topbar__spec" href="' + esc(cfg.specUrl) + '" target="_blank" rel="noopener">openapi.json &#8599;</a>' +
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

        wireShell();
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

        document.getElementById('consoleForm').addEventListener('input', onFormInput);

        window.addEventListener('hashchange', applyHash);
        document.addEventListener('keydown', onKeydown);
    }

    function onFormInput(e) {
        var t = e.target;
        if (t && t.dataset) {
            if (t.dataset.kind === 'auth') store.set('auth', t.value);
            if (t.dataset.kind === 'server') store.set('server', t.value);
        }
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

    function rowsFromSchema(schema, nested) {
        if (!schema || !schema.properties) return '';
        var required = schema.required || [];
        return Object.keys(schema.properties).map(function (name) {
            var prop = schema.properties[name];
            var req = required.indexOf(name) !== -1 ? '<span class="row__req">required</span>' : '';
            var desc = prop.description ? '<div class="row__desc">' + inline(prop.description) + '</div>' : '';
            var children = (prop.type === 'object' && prop.properties) ? rowsFromSchema(prop, true)
                : (prop.type === 'array' && prop.items && prop.items.properties) ? rowsFromSchema(prop.items, true) : '';
            var childWrap = children ? '<div class="row--nested">' + children + '</div>' : '';
            var enumNote = prop.enum ? ' · enum' : '';
            return '<div class="row"><div class="row__name">' + esc(name) + req + '</div>' +
                '<div class="row__type"><b>' + esc(schemaType(prop)) + '</b>' + enumNote + '</div>' + desc + '</div>' + childWrap;
        }).join('');
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
                var loc = '<b>' + esc(schemaType(p.schema)) + '</b> · ' + esc(p.in);
                var desc = p.description ? '<div class="row__desc">' + inline(p.description) + '</div>' : '';
                return '<div class="row"><div class="row__name">' + esc(p.name) + req + '</div><div class="row__type">' + loc + '</div>' + desc + '</div>';
            }).join('');
            html += '</section>';
        }

        var bodySchema = op.requestBody && op.requestBody.content && op.requestBody.content['application/json'] &&
            op.requestBody.content['application/json'].schema;
        if (bodySchema) {
            html += '<section class="spec-section"><h2 class="spec-section__title">Request body</h2>' +
                (rowsFromSchema(bodySchema) || '<p class="row__desc">' + esc(schemaType(bodySchema)) + '</p>') + '</section>';
        }

        var responses = op.responses || {};
        var codes = Object.keys(responses);
        if (codes.length) {
            html += '<section class="spec-section"><h2 class="spec-section__title">Responses</h2>';
            html += codes.map(function (code) {
                var r = responses[code] || {};
                return '<div class="response-row"><span class="status-pill" data-class="' + statusClass(+code) + '">' + esc(code) +
                    '</span><span class="response-row__desc">' + inline(r.description || '') + '</span></div>';
            }).join('');
            html += '</section>';
        }

        html += '</article>';
        document.getElementById('doc').innerHTML = html;
        document.getElementById('doc').scrollTop = 0;
    }

    /* ---------- console ---------- */

    function field(label, controlHtml, required) {
        var req = required ? '<span class="req">required</span>' : '';
        return '<div class="field"><label class="field__label">' + esc(label) + req + '</label>' + controlHtml + '</div>';
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
            var s = auth.scheme || {};
            var label = s.type === 'apiKey' ? (s.name || 'API key') : (s.scheme === 'basic' ? 'Basic credentials' : 'Bearer token');
            html += field(label, '<input class="input" type="text" data-kind="auth" value="' + esc(store.get('auth') || '') +
                '" placeholder="' + esc(label) + '…" autocomplete="off">');
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

        var bodySchema = op.requestBody && op.requestBody.content && op.requestBody.content['application/json'] &&
            op.requestBody.content['application/json'].schema;
        if (bodySchema && BODY_METHODS[entry.method]) {
            var skeleton = JSON.stringify(sample(bodySchema), null, 2);
            html += '<div class="subhead">Body · JSON</div>';
            html += '<div class="field"><textarea class="textarea" data-kind="body" spellcheck="false">' + esc(skeleton) + '</textarea></div>';
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
        document.getElementById('snippetCopy').addEventListener('click', copySnippet);
        document.getElementById('snippetLangs').addEventListener('click', onLangClick);
        updateSnippet();
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

        var url = server.replace(/\/$/, '') + path + (query.length ? '?' + query.join('&') : '');

        var headers = { Accept: 'application/json' };
        var bodyInput = form.querySelector('[data-kind="body"]');
        if (bodyInput) headers['Content-Type'] = 'application/json';

        var authInput = form.querySelector('[data-kind="auth"]');
        if (authInput && authInput.value) {
            var auth = authForOp(entry.op);
            var s = (auth && auth.scheme) || {};
            if (s.type === 'apiKey' && s.in === 'header') headers[s.name || 'X-API-Key'] = authInput.value;
            else if (s.scheme === 'basic') headers.Authorization = 'Basic ' + authInput.value;
            else headers.Authorization = 'Bearer ' + authInput.value;
        }

        return { method: entry.method, url: url, headers: headers, body: bodyInput ? bodyInput.value : null };
    }

    /* req.body is a JSON string; return the parsed value, the raw string when it
       isn't JSON, or undefined when there's no body. */
    function parsedBody(req) {
        if (!req.body) return undefined;
        try { return JSON.parse(req.body); } catch (e) { return req.body; }
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
        if (req.body) parts.push("  --data '" + req.body.replace(/\n\s*/g, '') + "'");
        return parts.join(' \\\n');
    }

    function buildLaravel(req) {
        var headers = {};
        Object.keys(req.headers).forEach(function (k) { headers[k] = req.headers[k]; });
        var method = req.method.toLowerCase();
        var body = parsedBody(req);
        var url = req.url.replace(/'/g, "\\'");
        var segments = [];

        if (headers.Authorization && headers.Authorization.indexOf('Bearer ') === 0) {
            segments.push("withToken('" + headers.Authorization.slice(7).replace(/'/g, "\\'") + "')");
            delete headers.Authorization;
        }
        // Passing an array body sets the JSON content type for us.
        if (body !== undefined && typeof body !== 'string') delete headers['Content-Type'];
        if (Object.keys(headers).length) {
            segments.push('withHeaders(' + indentLines(toPhp(headers), '    ') + ')');
        }

        if (body === undefined) {
            segments.push(method + "('" + url + "')");
        } else if (typeof body === 'string') {
            segments.push("withBody('" + body.replace(/'/g, "\\'") + "', 'application/json')");
            segments.push(method + "('" + url + "')");
        } else {
            segments.push(method + "('" + url + "', " + indentLines(toPhp(body), '    ') + ')');
        }
        return 'use Illuminate\\Support\\Facades\\Http;\n\n$response = Http::' + segments.join('\n    ->') + ';';
    }

    function buildJs(req) {
        var lines = ['const response = await fetch("' + req.url.replace(/"/g, '\\"') + '", {'];
        lines.push('  method: "' + req.method.toUpperCase() + '",');
        var hkeys = Object.keys(req.headers);
        if (hkeys.length) {
            lines.push('  headers: {');
            lines.push(hkeys.map(function (k) {
                return '    "' + k.replace(/"/g, '\\"') + '": "' + String(req.headers[k]).replace(/"/g, '\\"') + '"';
            }).join(',\n'));
            lines.push('  },');
        }
        var body = parsedBody(req);
        if (body !== undefined) {
            lines.push('  body: ' + (typeof body === 'string'
                ? JSON.stringify(body)
                : 'JSON.stringify(' + indentLines(JSON.stringify(body, null, 2), '  ') + ')') + ',');
        }
        lines.push('});');
        lines.push('const data = await response.json();');
        return lines.join('\n');
    }

    function buildPython(req) {
        var args = ['    "' + req.url.replace(/"/g, '\\"') + '",'];
        if (Object.keys(req.headers).length) {
            args.push('    headers=' + indentLines(toPython(req.headers), '    ') + ',');
        }
        var body = parsedBody(req);
        if (body !== undefined) {
            args.push(typeof body === 'string'
                ? '    data=' + JSON.stringify(body) + ','
                : '    json=' + indentLines(toPython(body), '    ') + ',');
        }
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

    function updateSnippet() {
        var code = document.getElementById('snippetCode');
        if (code) code.textContent = GENERATORS[activeLang()].build(readForm());
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

        var options = { method: req.method.toUpperCase(), headers: req.headers };
        if (req.body && BODY_METHODS[req.method]) options.body = req.body;

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

    function renderResponse(res, text, ms) {
        var body = text;
        try { body = JSON.stringify(JSON.parse(text), null, 2); } catch (e) { /* keep raw */ }

        var headerRows = '';
        res.headers.forEach(function (value, key) { headerRows += key + ': ' + value + '\n'; });

        document.getElementById('responseMount').innerHTML =
            '<div class="response">' +
                '<div class="response__status"><span class="response__code" data-class="' + statusClass(res.status) + '">' +
                    esc(res.status) + '</span><span>' + esc(res.statusText || '') + '</span>' +
                    '<span class="response__time">' + ms + ' ms</span></div>' +
                '<details><summary>Headers</summary><pre class="response__body">' + esc(headerRows.trim()) + '</pre></details>' +
                '<details open><summary>Body</summary><pre class="response__body">' + esc(body) + '</pre></details>' +
            '</div>';
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
