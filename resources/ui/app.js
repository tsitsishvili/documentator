/*
 * Documentator — built-in explorer entrypoint.
 * Tiny on purpose: the readable app code lives in core.js and snippets.js.
 */
var version = new URL(import.meta.url).searchParams.get('v') || '1';
var suffix = '?v=' + encodeURIComponent(version);

Promise.all([
    import('./core.js' + suffix),
    import('./snippets.js' + suffix),
]).then(function (modules) {
    modules[0].start({ createSnippetController: modules[1].createSnippetController });
});
