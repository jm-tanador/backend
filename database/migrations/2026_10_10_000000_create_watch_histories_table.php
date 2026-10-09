<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('watch_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('video_id');
            $table->string('channel_id')->nullable();
            $table->string('channel_title')->nullable();
            $table->string('title')->nullable();
            $table->timestamp('watched_at')->useCurrent();
            $table->unique(['user_id', 'video_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('watch_histories');
    }
};