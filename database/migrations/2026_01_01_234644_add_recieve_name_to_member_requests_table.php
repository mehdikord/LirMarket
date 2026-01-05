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
        Schema::table('member_requests', function (Blueprint $table) {
            $table->string('recieve_name')->nullable()->after('receive_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('member_requests', function (Blueprint $table) {
            $table->dropColumn('recieve_name');
        });
    }
};
