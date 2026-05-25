<?php

namespace Zapol\Booking;

use Illuminate\Support\ServiceProvider;
use Zapol\Booking\Console\OAuthCommand;
use Zapol\Booking\Services\AvailabilityCalculator;
use Zapol\Booking\Services\BookingTokenSigner;
use Zapol\Booking\Services\Google\GoogleCalendarService;

class BookingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/booking.php', 'booking');

        $this->app->singleton(GoogleCalendarService::class, function ($app) {
            return new GoogleCalendarService($app['config']->get('booking.google'));
        });

        $this->app->singleton(BookingTokenSigner::class, function ($app) {
            return new BookingTokenSigner($app['config']->get('app.key'));
        });

        $this->app->bind(AvailabilityCalculator::class, function ($app) {
            return new AvailabilityCalculator(
                $app->make(GoogleCalendarService::class),
                $app['config']->get('booking')
            );
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'booking');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'booking');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/booking.php' => $this->configPath('booking.php'),
            ], 'booking-config');

            $this->publishes([
                __DIR__ . '/../resources/dist' => public_path('vendor/booking'),
            ], 'booking-assets');

            $this->publishes([
                __DIR__ . '/../resources/views' => $this->resourcePath('views/vendor/booking'),
            ], 'booking-views');

            $this->publishes([
                __DIR__ . '/../database/migrations' => $this->databasePath('migrations'),
            ], 'booking-migrations');

            $this->commands([
                OAuthCommand::class,
            ]);
        }
    }

    private function configPath(string $file): string
    {
        return function_exists('config_path')
            ? config_path($file)
            : base_path('config/' . $file);
    }

    private function resourcePath(string $path): string
    {
        return function_exists('resource_path')
            ? resource_path($path)
            : base_path('resources/' . $path);
    }

    private function databasePath(string $path): string
    {
        return function_exists('database_path')
            ? database_path($path)
            : base_path('database/' . $path);
    }
}
