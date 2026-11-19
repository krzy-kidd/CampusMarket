<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('account_status')
            ->orWhere('account_status', '')
            ->update(['account_status' => 'active']);
    }

    public function down(): void
    {
        // A missing status is invalid data; the repair cannot be safely reversed.
    }
};
