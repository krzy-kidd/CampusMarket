<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('category_name', 150);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['parent_category_id', 'category_name']);
        });

        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->text('description');
            $table->decimal('price', 10, 2)->unsigned();
            $table->unsignedTinyInteger('condition_rating');
            $table->enum('listing_status', ['available', 'pending_agreement', 'locked', 'sold', 'archived'])->default('available');
            $table->timestamps();

            $table->index(['listing_status', 'created_at']);
            $table->index(['category_id', 'listing_status']);
            $table->index(['seller_id', 'listing_status']);
        });

        Schema::create('listing_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->string('image_url', 2048);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['listing_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_images');
        Schema::dropIfExists('listings');
        Schema::dropIfExists('categories');
    }
};
