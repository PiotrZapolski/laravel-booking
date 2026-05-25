<?php

use Illuminate\Support\Facades\Route;
use Zapol\Booking\Http\Controllers\BookingController;
use Zapol\Booking\Http\Controllers\EventTypeController;
use Zapol\Booking\Http\Controllers\SlotsController;
use Zapol\Booking\Http\Controllers\WidgetController;

$prefix = config('booking.route_prefix', 'booking');

Route::group([
    'prefix' => $prefix,
    'middleware' => ['web'],
], function () {
    Route::get('widget.js', [WidgetController::class, 'script'])->name('booking.widget.script');
    Route::get('embed', [WidgetController::class, 'embed'])->name('booking.widget.embed');
    Route::get('reschedule/{token}', [WidgetController::class, 'reschedule'])->name('booking.reschedule');
});

Route::group([
    'prefix' => $prefix . '/api',
    'middleware' => ['api'],
], function () {
    Route::get('event-types/{slug}', [EventTypeController::class, 'show'])->name('booking.api.event-type');
    Route::get('event-types/{slug}/slots', [SlotsController::class, 'index'])->name('booking.api.slots');
    Route::post('bookings', [BookingController::class, 'store'])->name('booking.api.store');
    Route::get('bookings/{token}', [BookingController::class, 'show'])->name('booking.api.show');
    Route::post('bookings/{token}/reschedule', [BookingController::class, 'reschedule'])->name('booking.api.reschedule');
    Route::post('bookings/{token}/cancel', [BookingController::class, 'cancel'])->name('booking.api.cancel');
});
