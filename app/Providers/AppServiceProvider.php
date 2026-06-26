<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

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
        $this->configureDefaults();

        // Rate limiter for creating secrets:
        RateLimiter::for('secrets.create', function (Request $request) {
            $user = $request->user();
            if ($user) {
                $limitKey = 'user:' . $user->id;
                return [
                    Limit::perMinute(15)->by($limitKey),
                    Limit::perHour(200)->by($limitKey),
                ];
            }
            
            $limitKey = 'guest:' . $request->ip();
            return [
                Limit::perMinute(20)->by($limitKey),
                Limit::perHour(400)->by($limitKey),
            ];
        });

        // Rate limiter for viewing secrets:
        RateLimiter::for('secrets.view', function (Request $request) {
            $user = $request->user();
            if ($user) {
                $limitKey = 'user:' . $user->id;
                return [
                    Limit::perMinute(30)->by($limitKey),
                    Limit::perHour(300)->by($limitKey),
                ];
            }

            $limitKey = 'guest:' . $request->ip();
            return [
                Limit::perMinute(50)->by($limitKey),
                Limit::perHour(500)->by($limitKey),
            ];
        });

        \Illuminate\Auth\Notifications\VerifyEmail::toMailUsing(function (object $notifiable, string $url) {
            return (new \Illuminate\Notifications\Messages\MailMessage)
                ->subject('Verify Your Ilusion Account')
                ->markdown('emails.verify_email', [
                    'url' => $url,
                    'name' => $notifiable->name
                ]);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => Password::min(8));
    }
}
