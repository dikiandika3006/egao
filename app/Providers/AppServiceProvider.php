<?php

namespace App\Providers;

use App\Support\Access;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(BranchContext::class);
    }

    public function boot(): void
    {
        Carbon::setLocale('id');
        Model::shouldBeStrict(! $this->app->isProduction());

        // Lindungi data asli: migrate:fresh / migrate:refresh / migrate:reset / db:wipe ditolak di luar
        // lingkungan testing. Darurat (mis. reset DB lokal): set ALLOW_DESTRUCTIVE_DB=true di .env.
        DB::prohibitDestructiveCommands(
            ! $this->app->environment('testing') && ! config('backup.allow_destructive_db')
        );

        // Di production semua tautan memakai HTTPS (cookie sesi ikut aman lewat SESSION_SECURE_COOKIE).
        if ($this->app->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Hak akses Edit per menu (Pengaturan › Hak akses peran): komponen dengan const MENU hanya mengizinkan
        // method di READ_ACTIONS bagi peran tanpa izin Edit. Panggilan internal Livewire ($refresh, _startUpload, __dispatch) dilewati.
        \Livewire\on('call', function ($component, string $method) {
            if (! defined($component::class.'::MENU') || str_starts_with($method, '$') || str_starts_with($method, '_')) {
                return;
            }
            if (in_array($method, defined($component::class.'::READ_ACTIONS') ? $component::READ_ACTIONS : [], true)) {
                return;
            }

            $menu = (fn () => method_exists($this, 'menuKey') ? $this->menuKey($method) : static::MENU)->call($component);
            abort_unless(Access::can($menu, 'edit'), 403, 'Peran Anda tidak punya izin mengubah data di menu ini.');
        });

        Blade::directive('rupiah', fn ($e) => "<?php echo e(\\App\\Support\\Format::rupiah($e)); ?>");
    }
}
