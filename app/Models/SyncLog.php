<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    protected $fillable = ['action', 'synced_at'];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    public static function getLastSync(string $action): ?self
    {
        return self::where('action', $action)
            ->orderBy('synced_at', 'desc')
            ->first();
    }

    public static function recordSync(string $action): self
    {
        return self::create([
            'action' => $action,
            'synced_at' => now(),
        ]);
    }
}
