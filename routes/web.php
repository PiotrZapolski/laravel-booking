<?php

use Illuminate\Support\Facades\Route;
use Zapol\Booking\Http\Controllers\BookingController;
use Zapol\Booking\Http\Controllers\EventTypeController;
use Zapol\Booking\Http\Controllers\OAuthController;
use Zapol\Booking\Http\Controllers\SlotsController;
use Zapol\Booking\Http\Controllers\WidgetController;

$prefix = config('booking.route_prefix', 'booking');

// Stateless widget / API routes. No 'web' middleware to avoid forcing a
// session/DB on hosts that don't otherwise need one.
Route::group(['prefix' => $prefix], function () {
    Route::get('widget.js', [WidgetController::class, 'script'])->name('booking.widget.script');
    Route::get('embed', [WidgetController::class, 'embed'])->name('booking.widget.embed');
    Route::get('reschedule/{token}', [WidgetController::class, 'reschedule'])->name('booking.reschedule');

    // OAuth web UI. The landing page is gated by a Laravel signed URL — the
    // artisan command `booking:google-auth` prints a short-lived link. The
    // OAuth callback itself is the redirect_uri registered with Google, so it
    // can't be signed; it accepts any code and just displays the resulting
    // refresh token (operator copies into .env). The token never leaves the
    // operator's browser.
    Route::get('google/connect', [OAuthController::class, 'connect'])
        ->middleware('signed')
        ->name('booking.google.connect');
    Route::get('google/start', [OAuthController::class, 'start'])
        ->name('booking.google.start');
    Route::get('google/callback', [OAuthController::class, 'callback'])
        ->name('booking.google.callback');
});

Route::group(['prefix' => $prefix . '/api'], function () {
    Route::get('event-types/{slug}', [EventTypeController::class, 'show'])->name('booking.api.event-type');
    Route::get('event-types/{slug}/slots', [SlotsController::class, 'index'])->name('booking.api.slots');
    Route::post('bookings', [BookingController::class, 'store'])->name('booking.api.store');
    Route::get('bookings/{token}', [BookingController::class, 'show'])->name('booking.api.show');
    Route::post('bookings/{token}/reschedule', [BookingController::class, 'reschedule'])->name('booking.api.reschedule');
    Route::post('bookings/{token}/cancel', [BookingController::class, 'cancel'])->name('booking.api.cancel');
});
