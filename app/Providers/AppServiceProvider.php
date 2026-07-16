<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Mail\Events\MessageSent;
use App\Listeners\LogSentEmail;
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

        // Non-invasive email logging for Admin Email Center (does not alter send call sites)
        Event::listen(MessageSent::class, LogSentEmail::class);

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