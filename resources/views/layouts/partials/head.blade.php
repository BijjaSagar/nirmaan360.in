<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=khand:500,600,700|mukta:400,500,600,700,800|dm-mono:400,500" rel="stylesheet">
@vite(['resources/css/app.css', 'resources/js/app.js'])
