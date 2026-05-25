<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('event_type');
            $table->string('attendee_email');
            $table->string('attendee_name')->nullable();
            $table->string('google_event_id')->index();
            $table->dateTimeTz('starts_at');
            $table->dateTimeTz('ends_at');
            $table->string('status')->default('confirmed');
            $table->json('fields')->nullable();
            $table->string('meet_link')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_bookings');
    }
};
