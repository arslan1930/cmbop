<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use App\Models\Project;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        View::composer('*', function ($view) {

            if (auth()->check()) {

                $projects = Project::where('user_id', auth()->id())
                    ->latest()
                    ->get();

            } else {
                $projects = collect();
            }

            $view->with('sidebarProjects', $projects);
        });
    }
}