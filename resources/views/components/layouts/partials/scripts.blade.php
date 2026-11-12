{{-- Script Livewire dimuat dari base URL aplikasi (aman untuk subfolder). Nama file mengikuti route Livewire. --}}
@livewireScripts(['url' => url(config('app.debug') ? 'livewire/livewire.js' : 'livewire/livewire.min.js')])
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register(@js(asset('sw.js'))).catch(() => {}));
    }
</script>
