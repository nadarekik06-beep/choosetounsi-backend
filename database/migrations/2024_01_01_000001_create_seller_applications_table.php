<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSellerApplicationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('seller_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Business Information
            $table->string('full_name');
            $table->string('phone_number');
            $table->string('business_name');
            $table->string('business_category'); // e.g., Handicrafts, Food, Textiles
            $table->text('business_description');
            
            // Location
            $table->string('wilaya'); // Governorate
            $table->string('city');
            
            // Media
            $table->string('profile_picture')->nullable();
            $table->json('sample_images')->nullable(); // Array of image paths
            
            // Social Media (optional)
            $table->string('facebook_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('website_url')->nullable();
            
            // Application Status
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users'); // Admin who reviewed
            
            $table->timestamps();
            
            // Indexes
            $table->index('status');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('seller_applications');
    }
}