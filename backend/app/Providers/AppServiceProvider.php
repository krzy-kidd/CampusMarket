<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $frontendUrl = explode(',', (string) config('campus.frontend_url'))[0];

            return rtrim(trim($frontendUrl), '/').'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->email,
            ]);
        });

        VerifyEmail::createUrlUsing(function (User $user): string {
            $frontendUrl = explode(',', (string) config('campus.frontend_url'))[0];
            $apiUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ]);

            return rtrim(trim($frontendUrl), '/').'/verify-email?'.http_build_query(['url' => $apiUrl]);
        });
    }
}
