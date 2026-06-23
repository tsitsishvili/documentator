<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        body { margin: 0; }
    </style>
</head>
<body>
    {{-- Scalar driver (config: documentator.ui.driver = 'scalar'). The asset URL
         is pinned & configurable (documentator.ui.assets); self-host it to apply
         Subresource Integrity / a CSP if required. --}}
    <script id="api-reference" data-url="{{ $specUrl }}"></script>
    <script src="{{ $assets }}"></script>
</body>
</html>
