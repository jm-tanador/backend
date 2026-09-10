<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB; // <-- make sure DB is imported

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique();
            $table->string('avatar')->nullable();
            $table->text('google_token')->nullable();
            $table->text('google_refresh_token')->nullable();
        });

        // PostgreSQL command to make password optional without doctrine/dbal:
        DB::statement('ALTER TABLE users ALTER COLUMN password DROP NOT NULL;');
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google_id', 'avatar', 'google_token', 'google_refresh_token']);
        });

        DB::statement('ALTER TABLE users ALTER COLUMN password SET NOT NULL;');
    }
};