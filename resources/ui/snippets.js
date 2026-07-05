/*
 * Documentator — request snippet generation.
 * Kept separate from the UI shell so app/core code stays readable.
 */
export function createSnippetController(deps) {
    'use strict';

    var BODY_METHODS = deps.BODY_METHODS;
    var state = deps.state;
    var store = deps.store;
    var esc = deps.esc;
    var readForm = deps.readForm;
    var copyText = deps.copyText;
    var entryById = deps.entryById;
    var requestBodyContent = deps.requestBodyContent;
    var responseSchema = deps.responseSchema;
    var resolveSchema = deps.resolveSchema;
    var typesOf = deps.typesOf;
    var isNullable = deps.isNullable;

    /* ---------- snippet generation ---------- */

    function fieldsToObject(fields) {
        var o = {};
        fields.forEach(function (f) { o[f.name] = f.value; });
        return o;
    }
    function fileNames(file) {
        if (!file.filenames.length && file.multiple) return ['path/to/file-1', 'path/to/file-2'];
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
        schema = resolveSchema(schema) || {};
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
        schema = resolveSchema(schema);
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
        schema = resolveSchema(schema);
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
        schema = resolveSchema(schema);
        return typesOf(schema)[0] === 'string' && (schema.format === 'date' || schema.format === 'date-time');
    }

    /* `new Date(x)`, guarding null when the field allows it. */
    function dateFieldExpr(schema, acc) {
        return isNullable(schema) ? acc + ' ? new Date(' + acc + ') : null' : 'new Date(' + acc + ')';
    }

    /* `{ ...src, <date overrides> }` — only the date-bearing props are rewritten. */
    function spreadLiteral(schema, src, pad, paren, depth) {
        schema = resolveSchema(schema) || {};
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
        schema = resolveSchema(schema) || {};
        if (isDateField(schema)) return dateFieldExpr(schema, acc);
        var t = typesOf(schema)[0];
        if (t === 'array' && schema.items && schemaHasDate(schema.items)) {
            var item = resolveSchema(schema.items) || {};
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
        schema = resolveSchema(schema) || {};
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
        schema = resolveSchema(schema) || {};
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

    function renderHighlightedCode(code, source) {
        code.innerHTML = highlightCode(esc(source));
    }

    function updateSnippet() {
        var code = document.getElementById('snippetCode');
        if (code) renderHighlightedCode(code, GENERATORS[activeLang()].build(readForm()));
    }

    function copySnippet() {
        var text = document.getElementById('snippetCode').textContent;
        var btn = document.getElementById('snippetCopy');
        copyText(text, function () {
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = 'Copy'; }, 1400);
        });
    }


    function html() {
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

        return '<div class="snippet">' +
            '<div class="snippet__bar"><div class="snippet__langs" id="snippetLangs">' + tabs + otherSelect + '</div>' +
            '<button class="snippet__copy" id="snippetCopy" type="button">Copy</button></div>' +
            '<pre class="snippet__code" id="snippetCode"></pre></div>';
    }

    function wire() {
        document.getElementById('snippetCopy').addEventListener('click', copySnippet);
        document.getElementById('snippetLangs').addEventListener('click', onLangClick);
        document.getElementById('snippetOther').addEventListener('change', onOtherChange);
        updateSnippet();
    }

    return {
        html: html,
        wire: wire,
        update: updateSnippet,
    };
}
