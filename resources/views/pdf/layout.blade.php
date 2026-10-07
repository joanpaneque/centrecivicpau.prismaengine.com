<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a2e; }
        h1 { font-size: 18px; margin: 0 0 4px; color: #00056a; }
        h2 { font-size: 13px; margin: 14px 0 6px; color: #00056a; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-weight: bold; border-bottom: 1.5px solid #00056a; padding: 4px 3px; }
        td { padding: 3px; border-bottom: 0.5px solid #ddd; vertical-align: top; }
        .right { text-align: right; }
        .center { text-align: center; }
        .muted { color: #666; }
        .small { font-size: 8px; }
        .total td { font-weight: bold; font-size: 12px; border-top: 1.5px solid #00056a; border-bottom: none; }
        .box { border: 0.8px solid #ccd; border-radius: 4px; padding: 8px; }
        .header td { border: none; padding: 0; }
        .badge { display: inline-block; padding: 1px 5px; border-radius: 3px; background: #eef; color: #00056a; font-size: 8px; }
        .warn { color: #b45309; }
    </style>
</head>
<body>
@yield('content')
</body>
</html>
