<?php

namespace App\Providers;

use App\Services\Geocoding\DatabaseReverseGeocoder;
use App\Services\Geocoding\ReverseGeocoder;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Mime\Address;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ReverseGeocoder::class, DatabaseReverseGeocoder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerMailLogger();
    }

    /**
     * Log a concise summary line for every outgoing email (no HTML body).
     */
    protected function registerMailLogger(): void
    {
        Event::listen(MessageSent::class, function (MessageSent $event): void {
            $message = $event->message;
            $to = collect($message->getTo())
                ->map(fn (Address $addr): string => $addr->getAddress())
                ->implode(', ');
            Log::info('Mail sent', ['to' => $to, 'subject' => $message->getSubject()]);
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

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
