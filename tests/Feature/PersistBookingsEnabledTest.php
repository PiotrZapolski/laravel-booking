<?php

namespace Zapol\Booking\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Zapol\Booking\Mail\BookingCancelled;
use Zapol\Booking\Mail\BookingConfirmation;
use Zapol\Booking\Mail\BookingRescheduled;

class PersistBookingsEnabledTest extends BookingFlowTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('booking.persist_bookings', true);
    }

    private function migrate(): void
    {
        (require __DIR__ . '/../../database/migrations/2026_01_01_000000_create_bookings_table.php')->up();
    }

    public function test_create_writes_a_row_and_reschedule_and_cancel_update_it(): void
    {
        $this->migrate();

        $created = $this->book()->assertStatus(201);

        $row = DB::table('booking_bookings')->first();
        $this->assertSame(1, DB::table('booking_bookings')->count());
        $this->assertSame('consultation', $row->event_type);
        $this->assertSame('jan@example.com', $row->attendee_email);
        $this->assertSame('Jan Kowalski', $row->attendee_name);
        $this->assertSame('evt-1', $row->google_event_id);
        // 12:00 Warsaw in June is 10:00 in the app timezone (UTC).
        $this->assertSame('2030-06-03 10:00:00', $row->starts_at);
        $this->assertSame('2030-06-03 10:30:00', $row->ends_at);
        $this->assertSame('confirmed', $row->status);
        $this->assertSame(['name' => 'Jan Kowalski', 'email' => 'jan@example.com'], json_decode($row->fields, true));

        $token = $created->json('token');

        $this->postJson("/booking/api/bookings/{$token}/reschedule", ['slot' => '2030-06-04T15:00:00+02:00'])
            ->assertOk();

        $row = DB::table('booking_bookings')->first();
        $this->assertSame(1, DB::table('booking_bookings')->count());
        $this->assertSame('2030-06-04 13:00:00', $row->starts_at);
        $this->assertSame('2030-06-04 13:30:00', $row->ends_at);
        $this->assertSame('confirmed', $row->status);

        $this->postJson("/booking/api/bookings/{$token}/cancel")->assertOk();

        $row = DB::table('booking_bookings')->first();
        $this->assertSame(1, DB::table('booking_bookings')->count());
        $this->assertSame('cancelled', $row->status);
        $this->assertSame('2030-06-04 13:00:00', $row->starts_at);
    }

    public function test_reschedule_of_a_booking_made_before_the_option_was_on_starts_its_row(): void
    {
        $this->migrate();

        $token = $this->book()->json('token');
        DB::table('booking_bookings')->delete();

        $this->postJson("/booking/api/bookings/{$token}/reschedule", ['slot' => '2030-06-04T15:00:00+02:00'])
            ->assertOk();

        $row = DB::table('booking_bookings')->first();
        $this->assertSame('evt-1', $row->google_event_id);
        $this->assertSame('jan@example.com', $row->attendee_email);
        $this->assertSame('2030-06-04 13:00:00', $row->starts_at);
    }

    public function test_missing_table_does_not_break_the_booking(): void
    {
        Log::spy();

        $token = $this->book()->assertStatus(201)->json('token');
        $this->postJson("/booking/api/bookings/{$token}/reschedule", ['slot' => '2030-06-04T15:00:00+02:00'])
            ->assertOk();
        $this->postJson("/booking/api/bookings/{$token}/cancel")->assertOk();

        Mail::assertSent(BookingConfirmation::class);
        Mail::assertSent(BookingRescheduled::class);
        Mail::assertSent(BookingCancelled::class);
        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_database_failure_does_not_break_the_booking(): void
    {
        // A connection that cannot be opened at all.
        config()->set('database.connections.testing.database', '/nonexistent-dir/booking.sqlite');

        $token = $this->book()->assertStatus(201)->json('token');
        $this->postJson("/booking/api/bookings/{$token}/reschedule", ['slot' => '2030-06-04T15:00:00+02:00'])
            ->assertOk();
        $this->postJson("/booking/api/bookings/{$token}/cancel")->assertOk();

        $this->assertArrayNotHasKey('evt-1', $this->calendar->events);
        Mail::assertSent(BookingConfirmation::class);
        Mail::assertSent(BookingCancelled::class);
    }
}
