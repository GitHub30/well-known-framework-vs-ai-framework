<?php
declare(strict_types=1);

namespace App\Providers;

use App\Services\DriveService;
use App\Services\SeoService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DriveService::class, static fn(): DriveService => new DriveService(storage_path('uploads')));
        $this->app->singleton(SeoService::class, static fn(): SeoService => new SeoService());
    }

    public function boot(): void {}
}
