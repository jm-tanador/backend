<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WatchHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'video_id', 'channel_id', 'channel_title', 'title', 'watched_at',
    ];
}