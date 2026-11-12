<?php

namespace App\Providers;

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

        Blade::directive('rupiah', fn ($e) => "<?php echo e(\\App\\Support\\Format::rupiah($e)); ?>");
    }
}
