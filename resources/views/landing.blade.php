<!DOCTYPE html>
<html data-theme="{{ $defaultPreferences['theme'] }}" lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="{{ __('message.2fauth_description') }}" lang="{{ $lang }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0, shrink-to-fit=no, viewport-fit=cover">
    <meta name="csrf-token" content="{{csrf_token()}}">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- PWA Meta Tags -->
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="2FA-Vault">
    <meta name="theme-color" content="#4f46e5">
    <meta name="msapplication-TileColor" content="#4f46e5">
    
    <title>{{ config('app.name') }}</title>

    <!-- Favicons -->
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}" />
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('icons/favicon-32x32.png') }}" />
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('icons/favicon-16x16.png') }}" />
    
    <!-- Apple Touch Icons -->
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}" />
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icons/apple-touch-icon.png') }}" />
    
    <!-- PWA Manifest -->
    <link rel="manifest" href="{{ asset('manifest.json') }}">

</head>
<body>
    <div id="app">
        <app></app>
    </div>
    <script type="text/javascript"
        {!! isset($cspNonce) ? "nonce='" . $cspNonce . "'" : "" !!} >
        var appSettings = {!! $appSettings !!};
        var appConfig = {!! $appConfig !!};
        var urls = {!! $urls !!};
        var defaultPreferences = {!! $defaultPreferences->toJson() !!};
        var lockedPreferences = {!! $lockedPreferences->toJson() !!};
        var appVersion = '{{ config("2fauth.version") }}';
        var isDemoApp = {!! $isDemoApp !!};
        var isTestingApp = {!! $isTestingApp !!};
        var appLocales = {!! $locales !!};
    </script>
    @vite('resources/js/app.js')
</body>
</html>