<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection(config('wrla.wrla_user_data.connection'))->create('wrla_user_data', function (Blueprint $table): void {
            // Id
            $table->id();

            // User ID
            $table->string('user_id');

            // Avatar
            $table->string('avatar')->nullable()->default(null);

            // Add permissions JSON column
            $table->json('permissions')->nullable()->default(null);

            // Add settings JSON column
            $table->json('settings')->nullable()->default(null);

            // Add data JSON column
            $table->json('data')->nullable()->default(null);

            // Add mfa_secret column
            $table->string('mfa_secret')->nullable()->default(null);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('wrla.wrla_user_data.connection'))->dropIfExists('wrla_user_data');
    }
};
