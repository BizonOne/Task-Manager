<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One place a person has said they want to be told about their work.
 */
class NotificationChannel extends Model
{
    public const TELEGRAM = 'telegram';

    public const SLACK = 'slack';

    public const WEBPUSH = 'webpush';

    /**
     * How long a half-finished connection is worth keeping.
     *
     * The Telegram flow hands out a code and waits for the person to press
     * Start. A code that never gets used should not sit there forever waiting
     * to be guessed.
     */
    public const CONNECT_WINDOW_MINUTES = 30;

    protected $fillable = [
        'type',
        'target',
        'label',
        'enabled',
        'muted_events',
        'meta',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'connect_expires_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'enabled' => 'boolean',
        'muted_events' => 'array',
        'meta' => 'array',
    ];

    /**
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            self::TELEGRAM => 'Telegram',
            self::SLACK => 'Slack',
            self::WEBPUSH => 'Browser',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Connections that are finished and switched on.
     */
    public function scopeLive($query)
    {
        return $query->whereNotNull('verified_at')->where('enabled', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function isLive(): bool
    {
        return $this->verified_at !== null && $this->enabled;
    }

    /**
     * Whether this channel wants to hear about a particular kind of event.
     */
    public function wants(string $event): bool
    {
        return $this->isLive() && ! in_array($event, (array) $this->muted_events, true);
    }

    public function typeLabel(): string
    {
        return self::types()[$this->type] ?? Str::headline($this->type);
    }

    /**
     * Hand out a fresh code and start the clock.
     */
    public function startConnecting(): string
    {
        $this->connect_token = Str::random(40);
        $this->connect_expires_at = Carbon::now()->addMinutes(self::CONNECT_WINDOW_MINUTES);
        $this->verified_at = null;
        $this->save();

        return $this->connect_token;
    }

    /**
     * The person came back and proved they are who the code was given to.
     */
    public function complete(string $target, ?string $label = null): void
    {
        $this->target = $target;
        $this->label = $label;
        $this->verified_at = Carbon::now();
        $this->connect_token = null;
        $this->connect_expires_at = null;
        $this->last_error = null;
        $this->save();
    }

    /**
     * Find the half-finished connection a code belongs to, if it is still
     * within its window.
     */
    public static function awaiting(string $token): ?self
    {
        return static::where('connect_token', $token)
            ->where('connect_expires_at', '>', Carbon::now())
            ->first();
    }

    /**
     * Remember why a message did not arrive.
     *
     * Written quietly: this happens while a notification is being delivered,
     * and a failure to deliver must not itself start another round of them.
     */
    public function recordFailure(string $message): void
    {
        $this->last_error = Str::limit($message, 500);
        $this->saveQuietly();
    }

    public function recordDelivery(): void
    {
        $this->last_error = null;
        $this->last_sent_at = Carbon::now();
        $this->saveQuietly();
    }
}
