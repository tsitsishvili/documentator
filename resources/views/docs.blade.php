<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="{{ $cssUrl }}">
</head>
<body>
    <div id="app" data-state="loading">
        <div class="boot">
            <span class="boot__mark">{ }</span>
            <p class="boot__msg">Loading the API&hellip;</p>
            <noscript>This API explorer needs JavaScript enabled.</noscript>
        </div>
    </div>

    <script>
        window.__DOCUMENTATOR__ = {
            specUrl: @json($specUrl),
            title: @json($title),
            sections: @json($sections ?? []),
            currentSection: @json($currentSection ?? null),
            authStorage: @json($authStorage),
        };
    </script>
    <script type="module" src="{{ $jsUrl }}"></script>
</body>
</html>
