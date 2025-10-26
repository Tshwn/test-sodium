<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'token',
        'abilities',
        'last_used_at',
        'expires_at',
    ];

    protected $casts = [
        'abilities' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $hidden = [
        'token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at instanceof Carbon && $this->expires_at->isPast();
    }

    /**
     * @return array{model: self, plain: string}
     */
    public static function issue(User $user, string $name, int $ttlSeconds): array
    {
        $plain = Str::random(64);

        $token = $user->apiTokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plain),
            'expires_at' => $ttlSeconds > 0 ? now()->addSeconds($ttlSeconds) : null,
        ]);

        return ['model' => $token, 'plain' => $plain];
    }
}
