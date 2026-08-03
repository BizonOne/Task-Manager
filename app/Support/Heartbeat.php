<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Proof that the scheduler is actually running.
 *
 * The archive sweep, and anything else put on a timer, quietly does nothing if
 * the host is not running `schedule:run` — and the failure is invisible: no
 * error, no log, just work that never happens. So the scheduler leaves a mark
 * every quarter of an hour, and the admin page says plainly whether it is
 * alive.
 */
class Heartbeat
{
    public const KEY = 'scheduler.last_run_at';

    /** Older than this and the scheduler is not running. */
    public const STALE_AFTER_MINUTES = 45;

    public static function touch(): void
    {
        Setting::updateOrCreate(
            ['key' => self::KEY],
            ['value' => CarbonImmutable::now()->toIso8601String()],
        );

        Brand::forget();
    }

    public static function lastRunAt(): ?CarbonImmutable
    {
        $value = Setting::query()->where('key', self::KEY)->value('value');

        if (! $value) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether the scheduler has checked in recently enough to be believed.
     */
    public static function isAlive(): bool
    {
        $last = self::lastRunAt();

        return $last !== null
            && $last->gt(CarbonImmutable::now()->subMinutes(self::STALE_AFTER_MINUTES));
    }
}
