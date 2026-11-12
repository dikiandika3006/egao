<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ isset($title) ? $title.' · ' : '' }}Egao Dental Care</title>

{{--
    Livewire membaca [data-update-uri] PERTAMA di dokumen. Elemen ini memastikan endpoint memakai
    base URL aplikasi, sehingga tetap jalan bila dibuka dari subfolder (mis. localhost/egao/public).
--}}
<meta name="livewire-update-uri" data-update-uri="{{ url('livewire/update') }}">

{{-- PWA (FR-MOB) --}}
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="theme-color" content="#34583c">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Egao">
<link rel="icon" type="image/png" href="{{ asset('icons/icon-192.png') }}">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles
