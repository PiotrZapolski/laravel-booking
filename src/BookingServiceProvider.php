<?php

namespace Zapol\Booking;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Zapol\Booking\Calendar\CalDavCalendarProvider;
use Zapol\Booking\Calendar\CalendarManager;
use Zapol\Booking\Calendar\MicrosoftCalendarProvider;
use Zapol\Booking\Conference\ZoomConferenceProvider;
use Zapol\Booking\Console\OAuthCommand;
use Zapol\Booking\Contracts\CalendarProvider;
use Zapol\Booking\Events\BookingCancelled;
use Zapol\Booking\Events\BookingCreated;
use Zapol\Booking\Events\BookingRescheduled;
use Zapol\Booking\Listeners\NotifySlack;
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

        $this->app->singleton(MicrosoftCalendarProvider::class, function ($app) {
            return new MicrosoftCalendarProvider(
                (array) $app['config']->get('booking.microsoft'),
                new Factory()
            );
        });

        $this->app->singleton(CalDavCalendarProvider::class, function ($app) {
            return new CalDavCalendarProvider([
                'caldav'    => (array) $app['config']->get('booking.caldav'),
                'organizer' => (array) $app['config']->get('booking.organizer'),
            ], new Factory());
        });

        $this->app->singleton(ZoomConferenceProvider::class, function ($app) {
            return new ZoomConferenceProvider(
                (array) $app['config']->get('booking.zoom'),
                new Factory()
            );
        });

        // The active calendar driver, picked by booking.calendar.driver.
        $this->app->singleton(CalendarProvider::class, function ($app) {
            return CalendarManager::resolve($app);
        });

        $this->app->singleton(BookingTokenSigner::class, function ($app) {
            return new BookingTokenSigner($app['config']->get('app.key'));
        });

        $this->app->bind(AvailabilityCalculator::class, function ($app) {
            return new AvailabilityCalculator(
                $app->make(CalendarProvider::class),
                $app['config']->get('booking')
            );
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'booking');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'booking');

        if ($this->app['config']->get('booking.notifications.slack_webhook_url')) {
            Event::listen([
                BookingCreated::class,
                BookingRescheduled::class,
                BookingCancelled::class,
            ], NotifySlack::class);
        }

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
