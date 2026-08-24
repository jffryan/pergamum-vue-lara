<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use App\Models\BookList;
use App\Models\Genre;
use App\Models\Location;
use App\Policies\BookListPolicy;
use App\Policies\GenrePolicy;
use App\Policies\LocationPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        BookList::class => BookListPolicy::class,
        Genre::class => GenrePolicy::class,
        Location::class => LocationPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        //
    }
}
