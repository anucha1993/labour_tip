<?php

namespace App\Providers;

use App\Services\ExpiryService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(ExpiryService::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Paginator::defaultView('vendor.pagination.app');
        Paginator::defaultSimpleView('vendor.pagination.simple');

        // Sidebar / topbar notification badge: one cheap query per page instead of four heavy ones.
        View::composer('layouts.main_layout', function ($view) {
            $counts = ['total' => 0];
            if (auth()->check()) {
                try {
                    $counts = app(ExpiryService::class)->counts();
                } catch (\Throwable $e) {
                    report($e);
                }
            }
            $view->with('expiryCounts', $counts);
        });
    }
}
