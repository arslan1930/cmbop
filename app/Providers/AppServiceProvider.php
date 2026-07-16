<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use App\Models\Project;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('apple', \SocialiteProviders\Apple\Provider::class);
        });

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