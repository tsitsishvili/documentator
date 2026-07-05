/*
 * Documentator — built-in explorer.
 * Fetches the OpenAPI document and renders the reading surface + try-it console.
 * No framework, no build step.
 */
export function start(deps) {
    'use strict';

    var createSnippetController = deps.createSnippetController;

    var METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];
    var BODY_METHODS = { post: 1, put: 1, patch: 1, delete: 1 };
    var CONSOLE_MIN = 340;
    var CONSOLE_MAX = 760;
    var NAV_HEIGHTS = { section: 34, group: 39, item: 74 };
    var NAV_OVERSCAN = 10;
    var navFrame = 0;
    var navObserver = null;

    var cfg = window.__DOCUMENTATOR__ || {};
    var app = document.getElementById('app');

    var state = {
        spec: null,
        operations: [],
        servers: [],
        sections: [],
        sectionLinks: [],
        sectionFilter: '',
        methodFilter: '',
        collapsedGroups: {},
        currentId: null,
        slugToId: {},
        navRows: [],
    };

    /* ---------- helpers ---------- */

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function resolvePointer(ref) {
        if (!ref || ref.indexOf('#/') !== 0 || !state.spec) return null;

        return ref.slice(2).split('/').reduce(function (node, part) {
            if (node == null) return null;
            var key = part.replace(/~1/g, '/').replace(/~0/g, '~');
            return node[key];
        }, state.spec);
    }

    function resolveSchema(schema, seen) {
        if (!schema || typeof schema !== 'object' || !schema.$ref) return schema;

        seen = seen || [];
        if (seen.indexOf(schema.$ref) !== -1) return schema;

        var target = resolvePointer(schema.$ref);
        if (!target) return schema;

        var resolved = resolveSchema(target, seen.concat(schema.$ref));
        var merged = {};
        Object.keys(resolved || {}).forEach(function (key) { merged[key] = resolved[key]; });
        Object.keys(schema).forEach(function (key) {
            if (key !== '$ref') merged[key] = schema[key];
        });

        return merged;
    }

    function el(html) {
        var t = document.createElement('template');
        t.innerHTML = html.trim();
        return t.content.firstElementChild;
    }

    function replaceHtml(node, html) {
        var t = document.createElement('template');
        t.innerHTML = html.trim();
        node.textContent = '';
        node.appendChild(t.content);
    }

    function statusClass(code) {
        if (!code || code < 100) return 'x';
        return String(Math.floor(code / 100));
    }

    /* The non-null members of an OpenAPI 3.1 type (which may be a union array). */
    function typesOf(schema) {
        schema = resolveSchema(schema);
        if (!schema) return [];
        var t = schema.type;
        if (Array.isArray(t)) return t.filter(function (x) { return x !== 'null'; });
        return t ? [t] : [];
    }

    function isNullable(schema) {
        schema = resolveSchema(schema);
        if (!schema) return false;
        if (schema.nullable) return true;
        return Array.isArray(schema.type) && schema.type.indexOf('null') !== -1;
    }

    /* A human label covering every shape OpenAPI can describe. */
    function schemaType(schema) {
        schema = resolveSchema(schema);
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
        schema = resolveSchema(schema);
        if (!schema) return false;
        if (schema.format === 'binary') return true;
        var items = resolveSchema(schema.items);
        return typesOf(schema)[0] === 'array' && items && items.format === 'binary';
    }

    function arrayItemIsObject(schema) {
        schema = resolveSchema(schema);

        return !!(schema && (schema.properties || schema.type === 'object'));
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
        return { mediaType: mediaType, schema: resolveSchema(c[mediaType].schema), required: !!rb.required };
    }

    function responseMedia(response) {
        if (!response || !response.content) return null;
        var c = response.content;
        return c['application/json'] || c[Object.keys(c)[0]] || null;
    }

    function responseSchema(response) {
        var media = responseMedia(response);
        return media && media.schema ? resolveSchema(media.schema) : null;
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
        var schema = resolveSchema(media.schema);
        if (schema && schema.example !== undefined) return schema.example;
        return undefined;
    }

    /* Remember the server (+ auth tokens, keyed by scheme) per docs page. */
    var store = {
        k: function (name) { return 'documentator:' + location.pathname + ':' + name; },
        get: function (name) { try { return localStorage.getItem(this.k(name)); } catch (e) { return null; } },
        set: function (name, value) { try { localStorage.setItem(this.k(name), value); } catch (e) { /* ignore */ } },
    };

    function storeJson(name, fallback) {
        try {
            var raw = store.get(name);
            return raw ? JSON.parse(raw) : fallback;
        } catch (e) {
            return fallback;
        }
    }

    function persistJson(name, value) {
        try { store.set(name, JSON.stringify(value || {})); } catch (e) { /* ignore */ }
    }
    var authMemory = {};
    var authStore = {
        backend: function () {
            if (cfg.authStorage === 'session') return sessionStorage;
            if (cfg.authStorage === 'memory') return null;
            return localStorage;
        },
        k: function (name) { return 'documentator:' + location.pathname + ':' + name; },
        get: function (name) {
            if (cfg.authStorage === 'memory') return authMemory[name] || '';
            try { return this.backend().getItem(this.k(name)); } catch (e) { return ''; }
        },
        set: function (name, value) {
            if (cfg.authStorage === 'memory') { authMemory[name] = value || ''; return; }
            try {
                if (value) this.backend().setItem(this.k(name), value);
                else this.backend().removeItem(this.k(name));
            } catch (e) { /* ignore */ }
        },
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

    function configuredSections() {
        return Array.isArray(cfg.sections) ? cfg.sections.filter(function (section) {
            return section && section.label;
        }) : [];
    }

    function sectionByLabel(label) {
        for (var i = 0; i < state.sectionLinks.length; i++) {
            if (state.sectionLinks[i].label === label) return state.sectionLinks[i];
        }

        return null;
    }

    function methodStats() {
        var counts = {};
        state.operations.forEach(function (entry) {
            counts[entry.method] = (counts[entry.method] || 0) + 1;
        });
        return METHODS.filter(function (method) { return counts[method]; }).map(function (method) {
            var active = state.methodFilter === method ? ' is-active' : '';
            return '<button class="topbar__method m-' + method + active + '" type="button" data-method-filter="' + method +
                '" aria-pressed="' + (state.methodFilter === method ? 'true' : 'false') + '">' + method.toUpperCase() + ' ' + counts[method] + '</button>';
        }).join('');
    }

    function renderMethodStats() {
        var mount = document.getElementById('methodStats');
        if (!mount) return;

        var counts = {};
        state.operations.forEach(function (entry) {
            counts[entry.method] = (counts[entry.method] || 0) + 1;
        });

        mount.textContent = '';
        mount.appendChild(tag('span', null, plural(state.operations.length, 'endpoint', 'endpoints')));
        METHODS.forEach(function (method) {
            if (!counts[method]) return;
            var active = state.methodFilter === method;
            var btn = tag('button', 'topbar__method m-' + method + (active ? ' is-active' : ''), method.toUpperCase() + ' ' + counts[method]);
            btn.type = 'button';
            btn.dataset.methodFilter = method;
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            mount.appendChild(btn);
        });
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
        if (id) {
            var entry = entryById(id);
            if (entry && state.sectionFilter && entry.section !== state.sectionFilter) {
                state.sectionFilter = entry.section || '';
                store.set('section', state.sectionFilter);
                var sectionFilter = document.getElementById('sectionFilter');
                if (sectionFilter) sectionFilter.value = state.sectionFilter;
                renderNav(document.getElementById('search').value);
            }
            if (id !== state.currentId) select(id);
        } else {
            renderEmpty();
        }
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
        schema = resolveSchema(schema);
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
                    section: op['x-documentator-section'] || '',
                    tag: (op.tags && op.tags[0]) || 'Endpoints',
                    tagVersion: op['x-documentator-group-version'] || '',
                });
            });
        });
        state.operations = ops;
        state.sectionLinks = configuredSections();
        state.sections = state.sectionLinks.length
            ? state.sectionLinks.map(function (section) { return section.label; })
            : sectionNames(ops);
        var currentSection = cfg.currentSection && cfg.currentSection.label ? cfg.currentSection.label : '';
        var savedSection = store.get('section') || '';
        state.sectionFilter = state.sections.indexOf(currentSection) !== -1
            ? currentSection
            : (state.sections.indexOf(savedSection) !== -1 ? savedSection : (state.sections[0] || ''));
        var savedMethod = store.get('method') || '';
        state.methodFilter = METHODS.indexOf(savedMethod) !== -1 ? savedMethod : '';
        state.collapsedGroups = storeJson('collapsedGroups', {});
        state.slugToId = {};
        ops.forEach(function (entry) { entry.slug = slugFor(entry); state.slugToId[entry.slug] = entry.id; });

        var servers = state.spec.servers || [];
        state.servers = servers.length ? servers.map(function (s) { return s.url; }) : [location.origin];
    }

    function sectionNames(ops) {
        var seen = {};
        var names = [];
        ops.forEach(function (entry) {
            if (!entry.section || seen[entry.section]) return;
            seen[entry.section] = true;
            names.push(entry.section);
        });

        return names;
    }

    function groupsFor(filter) {
        var q = (filter || '').trim().toLowerCase();
        var order = [];
        var byGroup = {};
        state.operations.forEach(function (entry) {
            if (state.sectionFilter && entry.section !== state.sectionFilter) return;
            if (state.methodFilter && entry.method !== state.methodFilter) return;
            var searchable = entry.method + ' ' + entry.path + ' ' + (entry.op.summary || '') + ' ' + entry.section + ' ' + entry.tag + ' ' + entry.tagVersion;
            if (q && searchable.toLowerCase().indexOf(q) === -1) return;
            var key = entry.section + '\u0000' + entry.tag + '\u0000' + entry.tagVersion;
            if (!byGroup[key]) {
                byGroup[key] = { section: entry.section, tag: entry.tag, version: entry.tagVersion, items: [] };
                order.push(key);
            }
            byGroup[key].items.push(entry);
        });
        return order.map(function (key) { return byGroup[key]; }).sort(compareGroups);
    }

    function compareGroups(a, b) {
        var sectionA = state.sections.indexOf(a.section);
        var sectionB = state.sections.indexOf(b.section);
        if (sectionA !== sectionB) return sectionA - sectionB;

        var tag = a.tag.localeCompare(b.tag, undefined, { sensitivity: 'base', numeric: true });
        if (tag !== 0) return tag;

        return a.version.localeCompare(b.version, undefined, { sensitivity: 'base', numeric: true });
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
    function authToken(key) { return authStore.get('auth:' + key) || authStore.get('auth') || ''; }
    function setAuthToken(key, value) { authStore.set('auth:' + key, value || ''); }
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

    function globalPathParameters() {
        return state.spec['x-documentator-global-path-parameters'] || {};
    }

    function globalPathParameter(name) {
        var params = globalPathParameters();

        return Object.prototype.hasOwnProperty.call(params, name) ? (params[name] || {}) : null;
    }

    function globalPathInputValue(name) {
        var form = document.getElementById('consoleForm');
        var inputValue = null;

        if (form) {
            form.querySelectorAll('[data-kind="global-path"]').forEach(function (input) {
                if (input.dataset.name === name) inputValue = input.value;
            });
        }

        if (inputValue) return inputValue;

        var saved = store.get('path:' + name);
        if (saved) return saved;

        var meta = globalPathParameter(name) || {};
        if (meta.example !== undefined && meta.example !== null && meta.example !== '') return String(meta.example);

        var schema = resolveSchema(meta.schema) || {};
        var sampled = sample(schema);

        return sampled === undefined || sampled === null ? '' : String(sampled);
    }

    function storeGlobalPathInput(input) {
        if (!input || !input.dataset || input.dataset.kind !== 'global-path') return;
        store.set('path:' + input.dataset.name, input.value || '');
    }

    function sectionFilterHtml() {
        if (!state.sections.length) return '';

        var options = state.sections.map(function (section) {
            return '<option value="' + esc(section) + '"' + (section === state.sectionFilter ? ' selected' : '') + '>' + esc(section) + '</option>';
        });

        return '<select id="sectionFilter" class="sidebar__section" aria-label="Filter section">' + options.join('') + '</select>';
    }

    function healthMetrics() {
        var tags = {};
        var missingSummaries = 0;
        var genericSummaries = 0;
        var missingDescriptions = 0;
        var genericSuccesses = 0;
        var secured = 0;
        var rootSecurity = !!(state.spec.security && state.spec.security.length);

        state.operations.forEach(function (entry) {
            var op = entry.op || {};
            var summary = (op.summary || '').trim();
            if (!summary) missingSummaries++;
            else if (/^(Index|Show|Store|Update|Destroy|Create|Get|Post|Put|Patch|Delete|Handle|Invoke|Emit)$/i.test(summary)) genericSummaries++;
            if (!(op.description || '').trim()) missingDescriptions++;
            tags[entry.tag] = (tags[entry.tag] || 0) + 1;
            if ((op.security && op.security.length) || (!Object.prototype.hasOwnProperty.call(op, 'security') && rootSecurity)) secured++;
            Object.keys(op.responses || {}).forEach(function (code) {
                var response = op.responses[code] || {};
                if (code === '200' && response.description === 'Successful response' && !response.content) genericSuccesses++;
            });
        });

        return {
            operations: state.operations.length,
            tags: Object.keys(tags).length,
            singletonTags: Object.keys(tags).filter(function (tag) { return tags[tag] === 1; }).length,
            missingSummaries: missingSummaries,
            genericSummaries: genericSummaries,
            missingDescriptions: missingDescriptions,
            genericSuccesses: genericSuccesses,
            securitySchemes: Object.keys(securitySchemes()).length,
            secured: secured,
            rootSecurity: rootSecurity,
        };
    }

    /* ---------- shell ---------- */

    function renderShell() {
        var info = state.spec.info || {};
        app.dataset.state = 'ready';
        app.textContent = '';

        var version = info.version ? '<span class="topbar__version">v' + esc(info.version) + '</span>' : '';
        var authBtn = hasSecuritySchemes()
            ? '<button class="topbar__auth" id="authBtn" type="button">&#128275; Authorize</button>'
            : '';
        app.appendChild(el(
            '<header class="topbar">' +
                '<button class="topbar__menu" id="menuBtn" aria-label="Toggle navigation">&#9776;</button>' +
                '<div class="topbar__brand"><b>{ }</b>' + esc(info.title || cfg.title || 'API') + '</div>' +
                version +
                '<div class="topbar__meta" id="methodStats" aria-label="API overview">' +
                    '<span>' + esc(plural(state.operations.length, 'endpoint', 'endpoints')) + '</span>' +
                    methodStats() +
                '</div>' +
                '<div class="topbar__actions">' + authBtn +
                    '<button class="topbar__health" id="healthBtn" type="button">Health</button>' +
                    '<button class="topbar__try" id="topbarTry" type="button" hidden>Try it</button>' +
                    '<a class="topbar__spec" href="' + esc(cfg.specUrl) + '" target="_blank" rel="noopener">openapi.json &#8599;</a>' +
                '</div>' +
            '</header>'
        ));

        app.appendChild(el(
            '<div class="layout">' +
                '<aside class="sidebar" id="sidebar">' +
                    '<div class="sidebar__filters"><input id="search" type="search" placeholder="Search endpoints  ( / )" autocomplete="off">' +
                        sectionFilterHtml() + '</div>' +
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
        app.appendChild(el(healthModalHtml()));

        restoreConsoleWidth();
        wireShell();
        updateAuthButton();
        renderNav('');
        applyHash();
    }

    function wireShell() {
        document.getElementById('nav').addEventListener('click', function (e) {
            var groupToggle = e.target.closest('[data-group-toggle]');
            if (groupToggle) {
                var key = groupToggle.dataset.groupToggle;
                state.collapsedGroups[key] = !state.collapsedGroups[key];
                persistJson('collapsedGroups', state.collapsedGroups);
                renderNav(document.getElementById('search').value);
                return;
            }

            var btn = e.target.closest('.nav-item');
            if (btn) select(btn.dataset.id);
        });
        document.getElementById('methodStats').addEventListener('click', function (e) {
            var btn = e.target.closest('[data-method-filter]');
            if (!btn) return;
            state.methodFilter = state.methodFilter === btn.dataset.methodFilter ? '' : btn.dataset.methodFilter;
            store.set('method', state.methodFilter);
            renderMethodStats();
            renderNav(document.getElementById('search').value);
        });
        document.getElementById('search').addEventListener('input', function (e) {
            renderNav(e.target.value);
        });
        document.getElementById('nav').addEventListener('scroll', renderVirtualNav);
        if (window.ResizeObserver) {
            if (navObserver) navObserver.disconnect();
            navObserver = new ResizeObserver(scheduleVirtualNav);
            navObserver.observe(document.getElementById('nav'));
        } else {
            window.addEventListener('resize', scheduleVirtualNav);
        }
        var sectionFilter = document.getElementById('sectionFilter');
        if (sectionFilter) {
            sectionFilter.addEventListener('change', function (e) {
                var next = e.target.value || '';
                var linked = sectionByLabel(next);

                if (linked && linked.url) {
                    location.href = linked.url + (location.hash || '');

                    return;
                }

                state.sectionFilter = next || (state.sections[0] || '');
                store.set('section', state.sectionFilter);
                var filter = document.getElementById('search').value;
                renderNav(filter);

                var current = state.currentId ? entryById(state.currentId) : null;
                if (state.sectionFilter && (!current || current.section !== state.sectionFilter)) {
                    var first = firstVisibleEntry(filter);
                    if (first) select(first.id);
                    else renderEmpty();
                }
            });
        }
        document.getElementById('send').addEventListener('click', send);
        document.getElementById('clearRequest').addEventListener('click', clearRequestInputs);
        document.getElementById('topbarTry').addEventListener('click', openConsole);
        document.getElementById('healthBtn').addEventListener('click', openHealth);

        var sidebar = document.getElementById('sidebar');
        var consoleEl = document.getElementById('console');
        var scrim = document.getElementById('scrim');
        document.getElementById('menuBtn').addEventListener('click', function () { toggle(sidebar, scrim); });
        document.getElementById('consoleClose').addEventListener('click', function () { close(consoleEl, scrim); });
        scrim.addEventListener('click', function () { close(sidebar, scrim); close(consoleEl, scrim); });

        var form = document.getElementById('consoleForm');
        form.addEventListener('input', onFormInput);
        form.addEventListener('change', onFormChange); // selects + file pickers
        form.addEventListener('click', onConsoleFormClick);
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
        var healthModal = document.getElementById('healthModal');
        document.getElementById('healthModalClose').addEventListener('click', closeHealth);
        healthModal.addEventListener('click', function (e) { if (e.target === healthModal) closeHealth(); });

        window.addEventListener('hashchange', applyHash);
        document.addEventListener('keydown', onKeydown);
        window.addEventListener('resize', function () {
            if (isConsoleDocked()) {
                applyConsoleWidth(parseInt(getComputedStyle(document.documentElement).getPropertyValue('--console'), 10) || 440, false);
            }
            renderVirtualNav();
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
        storeGlobalPathInput(t);
        snippets.update();
    }

    function onFormChange(e) {
        storeGlobalPathInput(e.target);
        snippets.update();
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
            closeHealth();
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
        btn.textContent = on ? '\uD83D\uDD12 Authorized' : '\uD83D\uDD13 Authorize';
    }

    /* ---------- health modal ---------- */

    function healthModalHtml() {
        return '<div class="modal" id="healthModal" hidden>' +
            '<div class="modal__card healthmodal" role="dialog" aria-modal="true" aria-label="Documentation health">' +
                '<div class="modal__head"><span>Documentation health</span>' +
                    '<button class="modal__close" id="healthModalClose" type="button" aria-label="Close">&#10005;</button></div>' +
                '<div class="modal__body" id="healthModalBody"></div>' +
            '</div></div>';
    }

    function healthRowElement(label, value, warn) {
        var row = tag('div', 'healthrow' + (warn ? ' is-warn' : ''));
        row.appendChild(tag('span', 'healthrow__label', label));
        row.appendChild(tag('span', 'healthrow__value', String(value)));

        return row;
    }

    function openHealth() {
        var m = document.getElementById('healthModal');
        var body = document.getElementById('healthModalBody');
        if (!m || !body) return;

        var h = healthMetrics();
        var grid = tag('div', 'healthgrid');
        grid.appendChild(healthRowElement('Operations', h.operations, false));
        grid.appendChild(healthRowElement('Tags', h.tags + ' · ' + h.singletonTags + ' singletons', h.singletonTags >= 10 && h.singletonTags / Math.max(h.tags, 1) >= .6));
        grid.appendChild(healthRowElement('Secured', h.secured, h.securitySchemes > 0 && h.secured === 0 && !h.rootSecurity));
        grid.appendChild(healthRowElement('Missing summaries', h.missingSummaries, h.missingSummaries > 0));
        grid.appendChild(healthRowElement('Generic summaries', h.genericSummaries, h.genericSummaries > 0));
        grid.appendChild(healthRowElement('Missing descriptions', h.missingDescriptions, h.missingDescriptions > 0));
        grid.appendChild(healthRowElement('Generic 200s', h.genericSuccesses, h.genericSuccesses > 0));
        body.textContent = '';
        body.appendChild(grid);
        m.removeAttribute('hidden');
    }

    function closeHealth() {
        var m = document.getElementById('healthModal');
        if (m) m.setAttribute('hidden', '');
    }

    /* ---------- navigation ---------- */

    function renderNav(filter) {
        var nav = document.getElementById('nav');
        var groups = groupsFor(filter);
        if (!groups.length) {
            state.navRows = [];
            nav.textContent = '';
            nav.appendChild(tag('p', 'nav__empty', 'No matching endpoints' + (state.sectionFilter ? ' in ' + state.sectionFilter : '') + '.'));
            return;
        }
        state.navRows = navRows(groups);
        renderVirtualNav();
        scheduleVirtualNav();
    }

    function navRows(groups) {
        var currentSection = null;
        var top = 0;
        var rows = [];

        function push(row) {
            row.top = top;
            row.height = NAV_HEIGHTS[row.type];
            top += row.height;
            rows.push(row);
        }

        groups.forEach(function (group) {
            var key = group.section + '|' + group.tag + '|' + group.version;
            var collapsed = !!state.collapsedGroups[key];

            if (group.section && group.section !== currentSection) {
                currentSection = group.section;
                push({ type: 'section', section: group.section });
            }

            push({ type: 'group', key: key, group: group, collapsed: collapsed });

            if (!collapsed) {
                group.items.forEach(function (entry) {
                    push({ type: 'item', entry: entry });
                });
            }
        });

        rows.totalHeight = top;

        return rows;
    }

    function renderVirtualNav() {
        var nav = document.getElementById('nav');
        if (!nav || !state.navRows.length) return;

        var scrollTop = nav.scrollTop;
        var viewportHeight = nav.clientHeight || 1;
        var total = state.navRows.totalHeight || 0;
        var maxScroll = Math.max(0, total - viewportHeight);
        if (scrollTop > maxScroll) {
            nav.scrollTop = maxScroll;
            scrollTop = maxScroll;
        }
        var start = Math.max(0, scrollTop - (NAV_OVERSCAN * NAV_HEIGHTS.item));
        var end = scrollTop + viewportHeight + (NAV_OVERSCAN * NAV_HEIGHTS.item);
        var visible = state.navRows.filter(function (row) {
            return row.top + row.height >= start && row.top <= end;
        });

        replaceHtml(nav, '<div class="nav__spacer" style="height:' + total + 'px">' +
            visible.map(renderNavRow).join('') +
        '</div>');
    }

    function scheduleVirtualNav() {
        if (navFrame) cancelAnimationFrame(navFrame);

        navFrame = requestAnimationFrame(function () {
            navFrame = 0;
            renderVirtualNav();
        });
    }

    function renderNavRow(row) {
        var style = 'style="transform:translateY(' + row.top + 'px);height:' + row.height + 'px"';

        if (row.type === 'section') {
            return '<h2 class="nav-section nav__row" ' + style + '>' + esc(row.section) + '</h2>';
        }

        if (row.type === 'group') {
            var group = row.group;
            var version = group.version ? '<span class="nav-group__version">' + esc(group.version) + '</span>' : '';

            return '<div class="nav-group nav__row" ' + style + '>' +
                '<button class="nav-group__title" type="button" data-group-toggle="' + esc(row.key) + '" aria-expanded="' + (row.collapsed ? 'false' : 'true') + '">' +
                    '<span class="nav-group__label"><span class="nav-group__chev">' + (row.collapsed ? '+' : '-') + '</span><span>' + esc(group.tag) + '</span>' + version + '</span>' +
                    '<span class="nav-group__count">' + group.items.length + '</span></button>' +
            '</div>';
        }

        var entry = row.entry;
        var current = entry.id === state.currentId ? ' aria-current="true"' : '';
        var dep = entry.op.deprecated ? ' is-deprecated' : '';
        var summary = operationLabel(entry);
        var summaryHtml = summary && summary !== entry.path
            ? '<span class="nav-item__summary">' + esc(summary) + '</span>' : '';

        return '<div class="nav__row" ' + style + '><button class="nav-item m-' + entry.method + dep + '" data-id="' + esc(entry.id) + '"' + current + '>' +
            '<span class="method m-' + entry.method + '">' + entry.method + '</span>' +
            '<span class="nav-item__main"><span class="nav-item__path">' + esc(entry.path) + '</span>' +
            summaryHtml + '</span></button></div>';
    }

    function firstVisibleEntry(filter) {
        var groups = groupsFor(filter);

        return groups.length && groups[0].items.length ? groups[0].items[0] : null;
    }

    /* ---------- documentation surface ---------- */

    function renderEmpty() {
        var doc = document.getElementById('doc');
        doc.textContent = '';
        doc.appendChild(el(
            '<div class="doc__empty"><h2>{ } Pick an endpoint</h2>' +
            '<p>Choose a request on the left to read its contract, then try it live from the console.</p></div>'
        ));
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

        document.getElementById('responseMount').textContent = '';

        if (decodeURIComponent((location.hash || '').slice(1)) !== (entry.slug || '')) {
            history.pushState(null, '', '#' + entry.slug);
        }

        if (window.matchMedia('(max-width: 820px)').matches) {
            close(document.getElementById('sidebar'), document.getElementById('scrim'));
        }
    }

    /* A field table for any object/array schema; recurses into nested shapes. */
    function rowsFromSchema(schema) {
        schema = resolveSchema(schema);
        if (!schema) return '';
        if (schema.type === 'array' && schema.items) {
            var items = resolveSchema(schema.items);
            if (items && items.properties) return rowsFromSchema(items);
            return '<div class="row"><div class="row__name">items</div>' +
                '<div class="row__type"><b>' + esc(schemaType(items)) + '</b></div></div>';
        }
        if (!schema.properties) return '';
        var required = schema.required || [];
        return Object.keys(schema.properties).map(function (name) {
            var prop = resolveSchema(schema.properties[name] || {});
            var req = required.indexOf(name) !== -1 ? '<span class="row__req">required</span>' : '';

            var meta = [];
            if (prop.enum) meta.push('enum');
            if (isNullable(prop)) meta.push('nullable');
            var metaNote = meta.length ? ' · ' + meta.join(' · ') : '';

            var desc = prop.description ? '<div class="row__desc">' + inline(prop.description) + '</div>' : '';
            var enumList = prop.enum
                ? '<div class="row__enum">' + prop.enum.map(function (v) { return '<code>' + esc(v) + '</code>'; }).join(' ') + '</div>'
                : '';

            var propItems = prop.items ? resolveSchema(prop.items) : null;
            var children = (prop.type === 'object' && prop.properties) ? rowsFromSchema(prop)
                : (prop.type === 'array' && propItems && propItems.properties) ? rowsFromSchema(propItems) : '';
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
            (entry.section ? '<span>' + esc(entry.section) + '</span>' : '') +
            '<span>' + esc(entry.tag) + '</span>' +
            (entry.tagVersion ? '<span>' + esc(entry.tagVersion) + '</span>' : '') +
            (op.operationId ? '<span>' + esc(op.operationId) + '</span>' : '') +
            '<span>' + esc(entry.method.toUpperCase()) + '</span>' +
            '</div>';
        if (auth) html += '<div class="endpoint__auth">Requires authentication · ' + esc(auth.key) + '</div>';
        if (op.description) html += '<div class="endpoint__desc">' + block(op.description) + '</div>';

        var params = (op.parameters || []).filter(function (p) { return p.in === 'path' || p.in === 'query' || p.in === 'header' || p.in === 'cookie'; });
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
                var headers = responseHeadersHtml(r);
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
                    headers + detail + '</div>';
            }).join('');
            html += '</section>';
        }

        html += '</article>';
        var doc = document.getElementById('doc');
        doc.textContent = '';
        doc.appendChild(el(html));
        doc.scrollTop = 0;
        document.getElementById('endpointTry').addEventListener('click', openConsole);
        document.getElementById('endpointLink').addEventListener('click', function () { copyEndpointLink(entry); });
    }

    function responseHeadersHtml(response) {
        var headers = response && response.headers ? response.headers : {};
        var names = Object.keys(headers);

        if (!names.length) return '';

        return '<div class="response-block__headers"><div class="response-block__caption">Headers</div>' +
            names.map(function (name) {
                var header = headers[name] || {};
                var schema = resolveSchema(header.schema) || {};
                var desc = header.description ? '<div class="row__desc">' + inline(header.description) + '</div>' : '';
                return '<div class="row"><div class="row__name">' + esc(name) + '</div>' +
                    '<div class="row__type"><b>' + esc(schemaType(schema)) + '</b></div>' + desc + '</div>';
            }).join('') + '</div>';
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

    function inputSample(schema, required) {
        if (!required) return '';
        var value = sample(schema);
        if (Array.isArray(value)) value = value.length ? value[0] : '';
        if (value && typeof value === 'object') return '';
        return value === undefined || value === null ? '' : String(value);
    }

    function field(label, controlHtml, required, hint) {
        var req = required ? '<span class="req">required</span>' : '';
        var note = hint ? '<span class="field__hint">' + esc(hint) + '</span>' : '';
        return '<div class="field"><label class="field__label"><span class="field__name">' + esc(label) + '</span>' + req + note + '</label>' + controlHtml + '</div>';
    }

    function queryArrayName(name) {
        return /\[\]$/.test(name) ? name : name + '[]';
    }

    function queryInputType(schema) {
        var type = typesOf(resolveSchema(schema))[0];

        return type === 'integer' || type === 'number' ? 'number' : 'text';
    }

    function repeatValueRow(kind, name, ftype, placeholder, removable, value) {
        return '<div class="repeat__row">' +
            '<input class="input" type="' + esc(ftype) + '" data-kind="' + esc(kind) + '" data-array="true" data-name="' + esc(name) +
                '" data-ftype="' + esc(ftype) + '" value="' + esc(value || '') + '" placeholder="' + esc(placeholder) + '">' +
            '<button type="button" class="repeat__remove" data-repeat-remove aria-label="Remove ' + esc(name) + ' value" title="Remove"' +
                (removable ? '' : ' disabled') + '>×</button>' +
        '</div>';
    }

    function repeatValueRowElement(kind, name, ftype, placeholder, removable, value) {
        var row = tag('div', 'repeat__row');
        var input = tag('input', 'input');
        input.type = ftype;
        input.dataset.kind = kind;
        input.dataset.array = 'true';
        input.dataset.name = name;
        input.dataset.ftype = ftype;
        input.value = value || '';
        input.placeholder = placeholder;
        row.appendChild(input);

        var remove = tag('button', 'repeat__remove', '×');
        remove.type = 'button';
        remove.dataset.repeatRemove = '';
        remove.setAttribute('aria-label', 'Remove ' + name + ' value');
        remove.title = 'Remove';
        remove.disabled = !removable;
        row.appendChild(remove);

        return row;
    }

    function updateRepeatButtons(group) {
        var rows = group.querySelectorAll('.repeat__row');
        rows.forEach(function (row) {
            var remove = row.querySelector('[data-repeat-remove]');
            if (remove) remove.disabled = rows.length === 1;
        });
    }

    function onConsoleFormClick(e) {
        var add = e.target.closest('[data-repeat-add]');
        var remove = e.target.closest('[data-repeat-remove]');

        if (add) {
            var group = add.closest('[data-repeat]');
            var items = group ? group.querySelector('.repeat__items') : null;
            if (!group || !items) return;

            items.appendChild(repeatValueRowElement(
                group.dataset.repeatKind || 'query',
                group.dataset.name,
                group.dataset.ftype || 'text',
                group.dataset.placeholder || 'value',
                true,
                ''
            ));
            updateRepeatButtons(group);
            var last = items.querySelector('.repeat__row:last-child [data-array="true"]');
            if (last) last.focus();
            snippets.update();
            return;
        }

        if (remove) {
            var row = remove.closest('.repeat__row');
            var group = remove.closest('[data-repeat]');
            if (!row || !group || group.querySelectorAll('.repeat__row').length === 1) return;

            row.remove();
            updateRepeatButtons(group);
            snippets.update();
        }
    }

    function parameterFieldControl(param, kind) {
        var schema = resolveSchema(param.schema) || {};
        var typeLbl = schemaType(schema);

        if (typesOf(schema)[0] === 'array') {
            var itemSchema = resolveSchema(schema.items) || {};
            var itemType = schema.items ? schemaType(itemSchema) : 'string';
            var ftype = queryInputType(itemSchema);
            var value = inputSample(itemSchema, param.required);
            var control = '<div class="repeat" data-repeat data-repeat-kind="' + esc(kind) + '" data-name="' + esc(param.name) +
                '" data-ftype="' + esc(ftype) + '" data-placeholder="' + esc(itemType) + '">' +
                    '<div class="repeat__items">' + repeatValueRow(kind, param.name, ftype, itemType, false, value) + '</div>' +
                    '<button type="button" class="repeat__add" data-repeat-add aria-label="Add ' + esc(param.name) + ' value" title="Add">+</button>' +
                '</div>';

            return field(param.name, control, param.required, typeLbl);
        }

        var valueAttr = inputSample(schema, param.required);
        return field(param.name, '<input class="input" type="text" data-kind="' + esc(kind) + '" data-name="' + esc(param.name) +
            '" data-ftype="text" value="' + esc(valueAttr) + '" placeholder="' + esc(typeLbl) + '">', param.required);
    }

    function queryFieldControl(param) {
        return parameterFieldControl(param, 'query');
    }

    function headerFieldControl(param) {
        return parameterFieldControl(param, 'header');
    }

    function cookieFieldControl(param) {
        return parameterFieldControl(param, 'cookie');
    }

    function globalPathFieldControl(name) {
        var meta = globalPathParameter(name) || {};
        var schema = resolveSchema(meta.schema) || {};
        var typeLbl = schemaType(schema);
        var value = globalPathInputValue(name);
        var attrs = 'data-kind="global-path" data-name="' + esc(name) + '"';
        var control;

        if (schema.enum) {
            var hasValue = schema.enum.map(String).indexOf(value) !== -1;
            var opts = (hasValue || !value ? [] : ['<option value="' + esc(value) + '" selected>' + esc(value) + '</option>'])
                .concat(schema.enum.map(function (v) {
                    var val = String(v);
                    return '<option value="' + esc(val) + '"' + (val === value ? ' selected' : '') + '>' + esc(val) + '</option>';
                }));
            control = '<select class="select" ' + attrs + ' data-ftype="enum">' + opts.join('') + '</select>';
        } else {
            var inputType = typesOf(schema)[0] === 'integer' || typesOf(schema)[0] === 'number' ? 'number' : 'text';
            control = '<input class="input" type="' + esc(inputType) + '" ' + attrs +
                ' data-ftype="' + esc(inputType) + '" value="' + esc(value) + '" placeholder="' + esc(typeLbl) + '">';
        }

        return field(name, control, true, 'global');
    }

    /* The right control for one top-level body property, driven by its schema. */
    function bodyFieldControl(name, schema, required) {
        schema = resolveSchema(schema) || {};
        var attrs = 'data-kind="body-field" data-name="' + esc(name) + '"';
        var typeLbl = schemaType(schema);
        var control;

        if (isFileSchema(schema)) {
            var multiple = schema.type === 'array' ? ' multiple' : '';
            control = '<input class="input input--file" type="file" ' + attrs + ' data-ftype="file"' + multiple + '>';
        } else if (schema.enum) {
            var enumValue = inputSample(schema, required);
            var opts = ['<option value="">—</option>'].concat(schema.enum.map(function (v) {
                return '<option value="' + esc(v) + '"' + (String(v) === enumValue ? ' selected' : '') + '>' + esc(v) + '</option>';
            }));
            control = '<select class="select" ' + attrs + ' data-ftype="enum">' + opts.join('') + '</select>';
        } else if (typesOf(schema)[0] === 'boolean') {
            var boolValue = inputSample(schema, required);
            control = '<select class="select" ' + attrs + ' data-ftype="boolean">' +
                '<option value="">—</option><option value="true"' + (boolValue === 'true' ? ' selected' : '') + '>true</option>' +
                '<option value="false"' + (boolValue === 'false' ? ' selected' : '') + '>false</option></select>';
        } else if (typesOf(schema)[0] === 'integer' || typesOf(schema)[0] === 'number') {
            var numberValue = inputSample(schema, required);
            control = '<input class="input" type="number" ' + attrs + ' data-ftype="number" placeholder="' + esc(typeLbl) + '">';
            if (numberValue !== '') control = '<input class="input" type="number" ' + attrs + ' data-ftype="number" value="' + esc(numberValue) + '" placeholder="' + esc(typeLbl) + '">';
        } else if (typesOf(schema)[0] === 'object' || (typesOf(schema)[0] === 'array' && schema.items && arrayItemIsObject(schema.items))) {
            var skeleton = JSON.stringify(sample(schema), null, 2);
            control = '<textarea class="textarea textarea--sm" ' + attrs + ' data-ftype="json" spellcheck="false">' + esc(skeleton) + '</textarea>';
        } else if (typesOf(schema)[0] === 'array') {
            var itemSchema = resolveSchema(schema.items) || {};
            var itemType = schema.items ? schemaType(itemSchema) : 'string';
            var ftype = queryInputType(itemSchema);
            var sampleValue = inputSample(itemSchema, required);
            control = '<div class="repeat" data-repeat data-repeat-kind="body-field" data-name="' + esc(name) +
                '" data-ftype="' + esc(ftype) + '" data-placeholder="' + esc(itemType) + '">' +
                    '<div class="repeat__items">' + repeatValueRow('body-field', name, ftype, itemType, false, sampleValue) + '</div>' +
                    '<button type="button" class="repeat__add" data-repeat-add aria-label="Add ' + esc(name) + ' value" title="Add">+</button>' +
                '</div>';
        } else {
            var textValue = inputSample(schema, required);
            control = '<input class="input" type="text" ' + attrs + ' data-ftype="text" placeholder="' + esc(typeLbl) + '">';
            if (textValue !== '') control = '<input class="input" type="text" ' + attrs + ' data-ftype="text" value="' + esc(textValue) + '" placeholder="' + esc(typeLbl) + '">';
        }

        return field(name, control, required, typeLbl);
    }

    function clearRequestInputs() {
        var form = document.getElementById('consoleForm');
        if (!form) return;

        form.querySelectorAll('[data-kind="path"], [data-kind="query"], [data-kind="header"], [data-kind="cookie"], [data-kind="body-field"], [data-kind="body-raw"]').forEach(function (control) {
            if (control.type === 'file') {
                control.value = '';
            } else if (control.tagName === 'SELECT') {
                control.selectedIndex = 0;
            } else {
                control.value = '';
            }
        });
        form.querySelectorAll('[data-repeat]').forEach(function (group) {
            var rows = group.querySelectorAll('.repeat__row');
            rows.forEach(function (row, index) {
                if (index > 0) row.remove();
            });
            updateRepeatButtons(group);
        });

        document.getElementById('responseMount').textContent = '';
        snippets.update();

        var first = form.querySelector('[data-kind="path"], [data-kind="query"], [data-kind="header"], [data-kind="cookie"], [data-kind="body-field"], [data-kind="body-raw"]');
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
        var globalPathParams = pathParams.filter(function (name) { return !!globalPathParameter(name); });
        var localPathParams = pathParams.filter(function (name) { return !globalPathParameter(name); });

        if (globalPathParams.length) {
            html += '<div class="subhead">Globals</div>';
            html += globalPathParams.map(globalPathFieldControl).join('');
        }

        if (localPathParams.length) {
            html += '<div class="subhead">Path</div>';
            html += localPathParams.map(function (name) {
                return field(name, '<input class="input" type="text" data-kind="path" data-name="' + esc(name) + '" placeholder="' + esc(name) + '">', true);
            }).join('');
        }

        var queryParams = (op.parameters || []).filter(function (p) { return p.in === 'query'; });
        if (queryParams.length) {
            html += '<div class="subhead">Query</div>';
            html += queryParams.map(queryFieldControl).join('');
        }

        var headerParams = (op.parameters || []).filter(function (p) { return p.in === 'header'; });
        if (headerParams.length) {
            html += '<div class="subhead">Headers</div>';
            html += headerParams.map(headerFieldControl).join('');
        }

        var cookieParams = (op.parameters || []).filter(function (p) { return p.in === 'cookie'; });
        if (cookieParams.length) {
            html += '<div class="subhead">Cookies</div>';
            html += cookieParams.map(cookieFieldControl).join('');
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

        html += snippets.html();

        replaceHtml(form, html);
        var consoleAuthBtn = document.getElementById('consoleAuthBtn');
        if (consoleAuthBtn) consoleAuthBtn.addEventListener('click', openAuth);
        snippets.wire();
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

    function appendMultipartField(fields, name, value) {
        if (Array.isArray(value)) {
            value.forEach(function (item, index) {
                appendMultipartField(fields, name + '[' + index + ']', item);
            });
            return;
        }
        if (value && typeof value === 'object') {
            Object.keys(value).forEach(function (key) {
                appendMultipartField(fields, name + '[' + key + ']', value[key]);
            });
            return;
        }

        fields.push({ name: name, value: value === null ? '' : String(value) });
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
                files.push({ name: name, filenames: names, multiple: input.multiple });
                return;
            }
            if (input.value === '') return;
            var val = coerce(input.dataset.ftype, input.value);
            if (input.dataset.array === 'true') {
                json[name] ??= [];
                json[name].push(val);
            } else {
                json[name] = val;
            }
            hasJson = true;
            if (multipart) {
                appendMultipartField(fields, input.dataset.array === 'true' ? queryArrayName(name) : name, val);
            } else {
                fields.push({
                    name: input.dataset.array === 'true' ? queryArrayName(name) : name,
                    value: typeof val === 'string' ? val : JSON.stringify(val),
                });
            }
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
        pathParamNames(entry.path).forEach(function (name) {
            if (!globalPathParameter(name)) return;
            var value = globalPathInputValue(name) || '{' + name + '}';
            path = path.replace('{' + name + '}', encodeURIComponent(value));
        });
        form.querySelectorAll('[data-kind="path"]').forEach(function (input) {
            var v = input.value || '{' + input.dataset.name + '}';
            path = path.replace('{' + input.dataset.name + '}', encodeURIComponent(v));
        });

        var query = [];
        form.querySelectorAll('[data-kind="query"]').forEach(function (input) {
            if (input.value === '') return;
            if (input.dataset.array === 'true') {
                var name = queryArrayName(input.dataset.name);
                query.push(encodeURIComponent(name) + '=' + encodeURIComponent(coerce(input.dataset.ftype, input.value)));
                return;
            }
            query.push(encodeURIComponent(input.dataset.name) + '=' + encodeURIComponent(input.value));
        });

        var headers = { Accept: 'application/json' };
        form.querySelectorAll('[data-kind="header"]').forEach(function (input) {
            if (input.value === '') return;
            headers[input.dataset.name] = input.dataset.array === 'true'
                ? (headers[input.dataset.name] ? headers[input.dataset.name] + ',' : '') + coerce(input.dataset.ftype, input.value)
                : String(coerce(input.dataset.ftype, input.value));
        });
        var cookies = [];
        form.querySelectorAll('[data-kind="cookie"]').forEach(function (input) {
            if (input.value === '') return;
            cookies.push(input.dataset.name + '=' + encodeURIComponent(input.value));
        });
        if (cookies.length) headers.Cookie = cookies.join('; ');
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

    /* ---------- clipboard ---------- */

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

    function applyBrowserCookies(url, cookieHeader) {
        if (!cookieHeader) return false;

        var target;
        try { target = new URL(url, location.href); } catch (e) { return false; }
        if (target.origin !== location.origin) return false;

        cookieHeader.split(';').forEach(function (pair) {
            pair = pair.trim();
            if (pair) document.cookie = pair + '; Path=/; SameSite=Lax';
        });

        return true;
    }

    function send() {
        var req = readForm();
        var btn = document.getElementById('send');
        var head = document.getElementById('consoleHead');
        var mount = document.getElementById('responseMount');

        btn.disabled = true;
        head.setAttribute('data-busy', '');
        var pending = tag('div', 'response response--pending');
        pending.setAttribute('aria-live', 'polite');
        var pendingStatus = tag('div', 'response__status');
        pendingStatus.appendChild(tag('span', 'response__label', 'Response'));
        pendingStatus.appendChild(tag('span', 'response__spinner'));
        pendingStatus.appendChild(tag('span', 'response__text', 'Waiting for ' + req.method.toUpperCase()));
        pending.appendChild(pendingStatus);
        mount.textContent = '';
        mount.appendChild(pending);
        revealResponse();

        var options = { method: req.method.toUpperCase(), headers: {} };
        Object.keys(req.headers).forEach(function (k) { options.headers[k] = req.headers[k]; });
        if (options.headers.Cookie) {
            applyBrowserCookies(req.url, options.headers.Cookie);
            delete options.headers.Cookie;
            options.credentials = 'include';
        }

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

    function tag(name, className, text) {
        var node = document.createElement(name);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;

        return node;
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

    function jsonScalarNode(value) {
        var type = jsonType(value);
        if (type === 'string') return tag('span', 'tok-str', JSON.stringify(value));
        if (type === 'number') return tag('span', 'tok-num', String(value));
        if (type === 'boolean') return tag('span', 'tok-bool', String(value));
        if (type === 'null') return tag('span', 'tok-null', 'null');
        return tag('span', 'tok-null', String(value));
    }

    function jsonKeyLabel(key) {
        if (key === null || key === undefined) return '';
        return typeof key === 'number' ? '[' + key + ']' : key;
    }

    function jsonNodeElement(value, key, depth) {
        var type = jsonType(value);
        var keyLabel = jsonKeyLabel(key);
        var keyNode = tag('span', 'response-json__key', keyLabel !== '' ? keyLabel : 'root');
        var colon = tag('span', 'response-json__colon', ':');

        if (type !== 'object' && type !== 'array') {
            var row = tag('div', 'response-json__row');
            row.appendChild(keyNode);
            row.appendChild(colon);
            row.appendChild(document.createTextNode(' '));
            row.appendChild(jsonScalarNode(value));

            return row;
        }

        var isArray = type === 'array';
        var items = isArray ? value : Object.keys(value);
        var count = isArray ? value.length : items.length;
        var summary = count + ' ' + (isArray ? (count === 1 ? 'item' : 'items') : (count === 1 ? 'field' : 'fields'));
        var empty = count === 0;
        var details = tag('details', 'response-json response-json--' + type);
        if (depth < 2 && !empty) details.open = true;

        var summaryNode = document.createElement('summary');
        summaryNode.appendChild(keyNode);
        summaryNode.appendChild(colon);
        summaryNode.appendChild(document.createTextNode(' '));
        summaryNode.appendChild(tag('span', 'response-json__brace', isArray ? '[' : '{'));
        summaryNode.appendChild(document.createTextNode(' '));
        summaryNode.appendChild(tag('span', 'response-json__meta', summary));
        if (empty) summaryNode.appendChild(tag('span', 'response-json__empty', 'empty'));
        details.appendChild(summaryNode);

        if (!empty) {
            var children = tag('div', 'response-json__children');
            if (isArray) {
                value.forEach(function (item, i) {
                    children.appendChild(jsonNodeElement(item, i, depth + 1));
                });
            } else {
                Object.keys(value).forEach(function (name) {
                    children.appendChild(jsonNodeElement(value[name], name, depth + 1));
                });
            }
            details.appendChild(children);
        }

        details.appendChild(tag('div', 'response-json__end', isArray ? ']' : '}'));

        return details;
    }

    function jsonTreeElement(value) {
        var tree = tag('div', 'response__tree');
        tree.appendChild(jsonNodeElement(value, null, 0));

        return tree;
    }

    function renderResponse(res, text, ms) {
        var isJson = false;
        var parsedJson = null;
        var body = text;
        try { parsedJson = JSON.parse(text); body = JSON.stringify(parsedJson, null, 2); isJson = true; } catch (e) { /* keep raw */ }

        var size = formatBytes(new Blob([text]).size);
        var ctype = (res.headers.get('content-type') || '').split(';')[0].trim();
        var headers = [];
        res.headers.forEach(function (value, key) { headers.push({ key: key, value: value }); });

        var mount = document.getElementById('responseMount');
        var response = tag('div', 'response');
        response.setAttribute('aria-live', 'polite');

        var status = tag('div', 'response__status');
        var statusCode = tag('span', 'response__code', String(res.status));
        statusCode.setAttribute('data-class', statusClass(res.status));
        status.appendChild(tag('span', 'response__label', 'Response'));
        status.appendChild(statusCode);
        status.appendChild(tag('span', 'response__text', res.statusText || ''));
        if (ctype) status.appendChild(tag('span', 'response__ctype', ctype));
        status.appendChild(tag('span', 'response__meta', size + ' · ' + ms + ' ms'));
        response.appendChild(status);

        var bodyDetails = document.createElement('details');
        bodyDetails.open = true;
        var bodySummary = document.createElement('summary');
        bodySummary.appendChild(document.createTextNode('Body'));
        if (isJson) {
            var treeControls = tag('span', 'response__tree-controls');
            var expand = tag('button', 'response__tree-action', 'Expand all');
            expand.type = 'button';
            expand.dataset.jsonAction = 'expand';
            var collapse = tag('button', 'response__tree-action', 'Collapse all');
            collapse.type = 'button';
            collapse.dataset.jsonAction = 'collapse';
            treeControls.appendChild(expand);
            treeControls.appendChild(collapse);
            bodySummary.appendChild(treeControls);
        }
        if (text !== '') {
            var copyBtn = tag('button', 'response__copy', 'Copy');
            copyBtn.type = 'button';
            bodySummary.appendChild(copyBtn);
        }
        bodyDetails.appendChild(bodySummary);
        if (text === '') {
            bodyDetails.appendChild(tag('div', 'response__empty', 'No content'));
        } else if (isJson) {
            bodyDetails.appendChild(jsonTreeElement(parsedJson));
        } else {
            bodyDetails.appendChild(tag('pre', 'response__body', body));
        }
        response.appendChild(bodyDetails);

        var headerDetails = document.createElement('details');
        var headerSummary = document.createElement('summary');
        headerSummary.appendChild(document.createTextNode('Headers '));
        headerSummary.appendChild(tag('span', 'summary__count', String(headers.length)));
        headerDetails.appendChild(headerSummary);
        var headerWrap = tag('div', 'response__headers');
        if (headers.length) {
            headers.forEach(function (h) {
                var row = tag('div', 'hrow');
                row.appendChild(tag('span', 'hrow__key', h.key));
                row.appendChild(tag('span', 'hrow__val', h.value));
                headerWrap.appendChild(row);
            });
        } else {
            headerWrap.appendChild(tag('div', 'response__empty', 'No headers'));
        }
        headerDetails.appendChild(headerWrap);
        response.appendChild(headerDetails);

        mount.textContent = '';
        mount.appendChild(response);
        revealResponse();

        var btn = response.querySelector('.response__copy');
        if (btn) btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation(); // don't toggle the <details>
            copyText(body, function () {
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = 'Copy'; }, 1400);
            });
        });

        response.querySelectorAll('.response__tree-action').forEach(function (control) {
            control.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var open = control.dataset.jsonAction === 'expand';
                response.querySelectorAll('.response-json').forEach(function (node) {
                    node.open = open;
                });
            });
        });
    }

    function renderError(err, url) {
        var response = tag('div', 'response');
        response.setAttribute('aria-live', 'polite');
        var status = tag('div', 'response__status');
        var code = tag('span', 'response__code', '—');
        code.setAttribute('data-class', 'x');
        status.appendChild(tag('span', 'response__label', 'Response'));
        status.appendChild(code);
        status.appendChild(tag('span', null, 'Request failed'));
        response.appendChild(status);

        var hint = tag('div', 'response__hint');
        hint.appendChild(document.createTextNode('Couldn’t reach '));
        hint.appendChild(tag('b', null, url));
        hint.appendChild(document.createTextNode('. This is usually CORS: the API must allow requests from this docs origin (' + location.origin + '), or the server URL is wrong. Browser detail: ' + (err && err.message ? err.message : '')));
        response.appendChild(hint);

        var mount = document.getElementById('responseMount');
        mount.textContent = '';
        mount.appendChild(response);
        revealResponse();
    }

    var snippets = createSnippetController({
        BODY_METHODS: BODY_METHODS,
        state: state,
        store: store,
        esc: esc,
        readForm: readForm,
        copyText: copyText,
        entryById: entryById,
        requestBodyContent: requestBodyContent,
        responseSchema: responseSchema,
        resolveSchema: resolveSchema,
        typesOf: typesOf,
        isNullable: isNullable,
    });

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
}
