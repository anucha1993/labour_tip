<?php

namespace App\Providers;

use App\Support\Permissions;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        // สิทธิ์การใช้งาน: every permission key is a Gate ability, so $user->can('labour.edit'),
        // @can('labour.edit') and ->middleware('perm:labour.edit') all read the roles matrix.
        Gate::before(function ($user) {
            return ($user->type ?? null) === Permissions::MASTER ? true : null;
        });
        foreach (Permissions::all() as $permission) {
            Gate::define($permission, fn ($user) => Permissions::has($user->type ?? null, $permission));
        }
    }
}
