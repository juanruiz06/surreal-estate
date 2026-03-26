<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();

            $table->string('external_id')->unique()->nullable();
            $table->string('url')->nullable(); // The link to the listing, unique and stable

            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('images')->nullable();
            $table->integer('price')->nullable();
            $table->integer('size')->nullable(); // In square meters
            $table->integer('rooms')->nullable();
            $table->integer('bathrooms')->nullable();

            $table->string('type')->nullable(); // House, Penthouse, Flat...
            $table->string('state')->nullable(); // New, Used, To renovate...

            // Location
            $table->string('city')->nullable();
            $table->string('neighborhood')->nullable();

            // Misc
            $table->json('characteristics')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
