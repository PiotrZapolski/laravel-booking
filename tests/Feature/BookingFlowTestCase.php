<?php

namespace Zapol\Booking\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase;
use Zapol\Booking\BookingServiceProvider;
use Zapol\Booking\Contracts\CalendarProvider;

abstract class BookingFlowTestCase extends TestCase
{
    protected FakeCalendar $calendar;

    protected function getPackageProviders($app): array
    {
        return [BookingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('booking.organizer.timezone', 'Europe/Warsaw');
        $app['config']->set('booking.organizer.email', 'organizer@example.com');
        $app['config']->set('booking.event_types', [
            'consultation' => [
                'title'            => 'Consultation',
                'duration_minutes' => 30,
                'form_fields'      => [
                    ['name' => 'name', 'type' => 'text', 'required' => true],
                    ['name' => 'email', 'type' => 'email', 'required' => true],
                ],
            ],
        ]);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->calendar = new FakeCalendar();
        $this->app->instance(CalendarProvider::class, $this->calendar);
    }

    protected function book(string $slot = '2030-06-03T12:00:00+02:00'): TestResponse
    {
        return $this->postJson('/booking/api/bookings', [
            'event_type' => 'consultation',
            'slot'       => $slot,
            'fields'     => ['name' => 'Jan Kowalski', 'email' => 'jan@example.com'],
        ]);
    }
}
