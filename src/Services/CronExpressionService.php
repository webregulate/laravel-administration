<?php

namespace WebRegulate\LaravelAdministration\Services;

use Throwable;

/**
 * Converts a standard 5-field cron expression (minute hour day-of-month month
 * day-of-week) into a short, human readable description such as
 * "Every day at 16:00" or "Every 5 minutes, Monday to Friday".
 */
class CronExpressionService
{
    /** Human readable names for the day-of-week field (0 and 7 both mean Sunday). */
    protected const DAYS = [
        0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
        4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
    ];

    /** Human readable names for the month field. */
    protected const MONTHS = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    /**
     * Convert a cron expression into a human readable string. Returns the original
     * expression unchanged if it cannot be parsed.
     */
    public static function toHuman(string $expression): string
    {
        $expression = trim($expression);

        try {
            $fields = preg_split('/\s+/', $expression);

            if (count($fields) !== 5) {
                return $expression;
            }

            [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $fields;

            $time = static::describeTime($minute, $hour);
            $day = static::describeDay($dayOfMonth, $month, $dayOfWeek);

            $parts = array_filter([$time, $day]);

            return $parts === [] ? $expression : implode(', ', $parts);
        } catch (Throwable) {
            return $expression;
        }
    }

    /**
     * Describe the minute + hour portion, e.g. "Every minute", "Every 5 minutes",
     * "At 16:00" or "Every hour at minute 30".
     */
    protected static function describeTime(string $minute, string $hour): string
    {
        // Every minute of every hour.
        if ($minute === '*' && $hour === '*') {
            return 'Every minute';
        }

        // Stepped minutes: */5 -> "Every 5 minutes".
        if (preg_match('#^\*/(\d+)$#', $minute, $m) && $hour === '*') {
            return 'Every '.$m[1].' minutes';
        }

        // Stepped hours: minute fixed, hour */2 -> "Every 2 hours at minute 0".
        if (preg_match('#^\*/(\d+)$#', $hour, $h) && ctype_digit($minute)) {
            return 'Every '.$h[1].' hours at minute '.(int) $minute;
        }

        // Any minute within a fixed hour.
        if ($minute === '*' && ctype_digit($hour)) {
            return 'Every minute past '.static::padHour($hour).':00';
        }

        // Fixed minute, every hour.
        if (ctype_digit($minute) && $hour === '*') {
            return 'Every hour at minute '.(int) $minute;
        }

        // Fixed minute + fixed hour(s).
        if (ctype_digit($minute) && static::isHourList($hour)) {
            return 'At '.static::describeHourList($hour, $minute);
        }

        return 'At '.static::padMinuteHour($minute, $hour);
    }

    /**
     * Describe the day-of-month / month / day-of-week portion, e.g.
     * "every day", "Monday to Friday", "on day 1 of the month" or "in June".
     */
    protected static function describeDay(string $dayOfMonth, string $month, string $dayOfWeek): string
    {
        $segments = [];

        if ($dayOfWeek !== '*') {
            $segments[] = static::describeDayOfWeek($dayOfWeek);
        }

        if ($dayOfMonth !== '*') {
            $segments[] = 'on day '.$dayOfMonth.' of the month';
        }

        if ($month !== '*') {
            $segments[] = 'in '.static::describeMonth($month);
        }

        if ($segments === []) {
            return 'every day';
        }

        return implode(', ', $segments);
    }

    /**
     * Describe a day-of-week field: single day, list, or range (1-5 -> "Monday to Friday").
     */
    protected static function describeDayOfWeek(string $dayOfWeek): string
    {
        if (preg_match('/^(\d+)-(\d+)$/', $dayOfWeek, $m)) {
            $from = static::DAYS[(int) $m[1]] ?? $m[1];
            $to = static::DAYS[(int) $m[2]] ?? $m[2];

            return $from.' to '.$to;
        }

        if (str_contains($dayOfWeek, ',')) {
            $names = array_map(
                fn ($d) => static::DAYS[(int) $d] ?? $d,
                explode(',', $dayOfWeek)
            );

            return static::joinList($names);
        }

        return static::DAYS[(int) $dayOfWeek] ?? $dayOfWeek;
    }

    /**
     * Describe a month field: single month, list, or range.
     */
    protected static function describeMonth(string $month): string
    {
        if (preg_match('/^(\d+)-(\d+)$/', $month, $m)) {
            $from = static::MONTHS[(int) $m[1]] ?? $m[1];
            $to = static::MONTHS[(int) $m[2]] ?? $m[2];

            return $from.' to '.$to;
        }

        if (str_contains($month, ',')) {
            $names = array_map(
                fn ($mo) => static::MONTHS[(int) $mo] ?? $mo,
                explode(',', $month)
            );

            return static::joinList($names);
        }

        return static::MONTHS[(int) $month] ?? $month;
    }

    /** Whether an hour field is a single hour or comma-separated list of hours. */
    protected static function isHourList(string $hour): bool
    {
        foreach (explode(',', $hour) as $part) {
            if (! ctype_digit($part)) {
                return false;
            }
        }

        return true;
    }

    /** Describe a comma-separated list of hours with a shared minute, e.g. "09:00 and 17:00". */
    protected static function describeHourList(string $hour, string $minute): string
    {
        $times = array_map(
            fn ($h) => static::padMinuteHour($minute, $h),
            explode(',', $hour)
        );

        return static::joinList($times);
    }

    /** Join a list of strings with commas and a trailing "and". */
    protected static function joinList(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }

    /** Format a minute and hour as HH:MM. */
    protected static function padMinuteHour(string $minute, string $hour): string
    {
        return static::padHour($hour).':'.str_pad($minute, 2, '0', STR_PAD_LEFT);
    }

    /** Zero-pad an hour to two digits. */
    protected static function padHour(string $hour): string
    {
        return str_pad($hour, 2, '0', STR_PAD_LEFT);
    }
}
