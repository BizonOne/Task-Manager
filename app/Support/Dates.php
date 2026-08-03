<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * One place that decides how a date or a time is written down.
 *
 * Before this, the app rendered timestamps eleven different ways, half of them
 * on a 12-hour clock, and a "Created" line that gave the day but not the hour.
 *
 * Two kinds of value, and the difference matters:
 *
 *  - An **instant** (created_at, updated_at, snooze_until) is a moment on the
 *    world's clock. It is stored in UTC and shown in the display timezone.
 *  - A **wall-clock time** (a routine that runs at 09:00, a reminder set for
 *    18:30) is not an instant — it is the same 09:00 wherever you are. Putting
 *    it through a timezone conversion would move it by three hours and be
 *    plainly wrong.
 */
class Dates
{
    /** "Aug 03, 2026" */
    public const DATE = 'M d, Y';

    /** "23:59" — 24-hour, because "11:59 PM" makes people count. */
    public const TIME = 'H:i';

    /** "Aug 03, 2026 23:59" */
    public const DATE_TIME = self::DATE.' '.self::TIME;

    /** "Aug 03" — for lists tight on room. */
    public const SHORT_DATE = 'M d';

    /** "Aug 03, 23:59" */
    public const SHORT_DATE_TIME = self::SHORT_DATE.', '.self::TIME;

    /**
     * The timezone timestamps are shown in. Storage stays UTC either way —
     * this only decides what a person reads.
     */
    public static function timezone(): string
    {
        return config('app.display_timezone') ?: config('app.timezone', 'UTC');
    }

    /**
     * "Aug 03, 2026"
     */
    public static function date(mixed $value): ?string
    {
        return self::render($value, self::DATE);
    }

    /**
     * "Aug 03, 2026 23:59"
     */
    public static function dateTime(mixed $value): ?string
    {
        return self::render($value, self::DATE_TIME);
    }

    /**
     * "23:59", from an instant.
     */
    public static function time(mixed $value): ?string
    {
        return self::render($value, self::TIME);
    }

    /**
     * "Aug 03, 23:59"
     */
    public static function shortDateTime(mixed $value): ?string
    {
        return self::render($value, self::SHORT_DATE_TIME);
    }

    /**
     * "23:59", from a wall-clock time — a `time` column, or "09:00:00" as
     * text. Not converted: 09:00 is 09:00 for everyone reading it.
     */
    public static function clock(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format(self::TIME);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A date with the day of the week, for emails: "Mon, 3 Aug 2026".
     */
    public static function longDate(mixed $value): ?string
    {
        return self::render($value, 'D, j M Y');
    }

    /**
     * Format an instant in the display timezone.
     */
    private static function render(mixed $value, string $format): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = $value instanceof CarbonInterface ? $value->copy() : Carbon::parse($value);

            return $date->setTimezone(self::timezone())->format($format);
        } catch (\Throwable) {
            return null;
        }
    }
}
