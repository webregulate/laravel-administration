<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wrla_email_templates', function (Blueprint $table): void {
            $table->string('type')->default('markdown')->after('alias');
        });

        // Ensure all existing email templates default to the markdown render type
        DB::table('wrla_email_templates')->update(['type' => 'markdown']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wrla_email_templates', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }
};
