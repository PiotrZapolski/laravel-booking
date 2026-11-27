<?php

namespace Zapol\Booking\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Zapol\Booking\Events\BookingCreated;
use Zapol\Booking\Mail\BookingCancelled;
use Zapol\Booking\Mail\BookingConfirmation;

/**
 * The default install: `persist_bookings` is false and the package must work
 * with no table and without ever opening a database connection.
 */
class PersistBookingsDisabledTest extends BookingFlowTestCase
{
    public function test_option_is_off_by_default(): void
    {
        $this->assertFalse(config('booking.persist_bookings'));
    }

    public function test_full_flow_never_opens_a_database_connection(): void
    {
        // Any database access would have to open this connection, and fail.
        config()->set('database.connections.testing.database', '/nonexistent-dir/booking.sqlite');

        $token = $this->book()->assertStatus(201)->json('token');
        $this->postJson("/booking/api/bookings/{$token}/reschedule", ['slot' => '2030-06-04T15:00:00+02:00'])
            ->assertOk();
        $this->postJson("/booking/api/bookings/{$token}/cancel")->assertOk();

        $this->assertSame([], $this->app['db']->getConnections());
        $this->assertFalse(Event::hasListeners(BookingCreated::class));
        Mail::assertSent(BookingConfirmation::class);
        Mail::assertSent(BookingCancelled::class);
    }
}
