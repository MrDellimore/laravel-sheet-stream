<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Support;

use DateInterval;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Normalizes the raw cell values an engine yields into the shape import
 * classes receive.
 *
 * Laravel Excel hands imports the raw Excel serial number for a date-styled
 * cell (a formatted string when the import uses WithFormatData), never a
 * DateTime object. OpenSpout, by contrast, materialises date-styled cells as
 * DateTimeImmutable (and time-only cells as DateInterval). This class closes
 * that gap so import code written against Laravel Excel keeps working.
 */
final class CellNormalizer
{
    public const string DATES_AS_SERIAL = 'serial';

    public const string DATES_AS_DATETIME = 'datetime';

    /** Excel serial for 1970-01-01 in the 1900 date system. */
    private const int UNIX_EPOCH_SERIAL = 25569;

    /**
     * Excel treats 1900 as a leap year (Lotus 1-2-3 compatibility), so
     * every serial before 1900-03-01 is one lower than the true day count.
     */
    private const int LEAP_BUG_CUTOFF_SERIAL = 61;

    public static function assertValidDateMode(string $mode): string
    {
        if (! in_array($mode, [self::DATES_AS_SERIAL, self::DATES_AS_DATETIME], true)) {
            throw new InvalidArgumentException(
                "Unsupported dates.import_as value: '{$mode}'. Supported values: serial, datetime."
            );
        }

        return $mode;
    }

    /**
     * @param  array<int|string, mixed>  $cells
     * @return array<int|string, mixed>
     */
    public static function normalize(array $cells, string $dateMode = self::DATES_AS_SERIAL): array
    {
        if ($dateMode !== self::DATES_AS_SERIAL) {
            return $cells;
        }

        foreach ($cells as $i => $cell) {
            if ($cell instanceof DateTimeInterface) {
                $cells[$i] = self::dateToSerial($cell);
            } elseif ($cell instanceof DateInterval) {
                $cells[$i] = self::intervalToSerial($cell);
            }
        }

        return $cells;
    }

    /**
     * Convert a date to its Excel serial number (1900 date system), using the
     * date's own wall-clock fields so the configured timezone is respected.
     *
     * Returns an int for dates with no time component, mirroring how
     * PhpSpreadsheet yields a whole-number serial for date-only cells.
     */
    public static function dateToSerial(DateTimeInterface $date): int|float
    {
        $days = (int) floor(($date->getTimestamp() + $date->getOffset()) / 86400) + self::UNIX_EPOCH_SERIAL;

        if ($days < self::LEAP_BUG_CUTOFF_SERIAL) {
            $days--;
        }

        $seconds = ((int) $date->format('G')) * 3600
            + ((int) $date->format('i')) * 60
            + (int) $date->format('s')
            + ((int) $date->format('u')) / 1_000_000;

        if ($seconds == 0) {
            return $days;
        }

        return $days + $seconds / 86400;
    }

    /**
     * Convert a time-only value (which Excel stores as a fraction of a day)
     * back to that fraction.
     */
    public static function intervalToSerial(DateInterval $interval): int|float
    {
        $seconds = $interval->d * 86400
            + $interval->h * 3600
            + $interval->i * 60
            + $interval->s
            + $interval->f;

        $serial = $seconds / 86400;

        if ($interval->invert === 1) {
            $serial = -$serial;
        }

        return $serial == floor($serial) ? (int) $serial : $serial;
    }
}
