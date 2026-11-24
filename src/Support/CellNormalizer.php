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
 * DateTimeImmutable (a time of day such as h:mm as that time on 1899-12-30) and
 * elapsed-time cells such as [h]:mm:ss as DateInterval. This class closes that
 * gap so import code written against Laravel Excel keeps working.
 */
final class CellNormalizer
{
    public const string DATES_AS_SERIAL = 'serial';

    public const string DATES_AS_DATETIME = 'datetime';

    /** Days from 1899-12-30, the day the readers count serials from, to 1970-01-01. */
    private const int UNIX_EPOCH_SERIAL = 25569;

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
     * Convert a date a reader produced back to the Excel serial stored in the cell, using
     * the date's own wall-clock fields so the configured timezone is respected.
     *
     * This is the exact inverse of how OpenSpout and rusty_sheet build the date: the serial
     * is a plain day count from 1899-12-30, on both sides of Excel's fictitious 1900-02-29
     * (serial 60), which neither reader models. So serial 1 arrives as 1899-12-31, a time
     * of day such as 18:00 (0.75) as 1899-12-30 18:00, serial 60 as 1900-02-28, and each
     * maps back to the number it came from. No leap-day correction belongs here: applying
     * one would shift every serial below 61, time-only cells included, down a day.
     *
     * The other side of that coin: from 1900-03-01 on (serial 61) the result is also the
     * serial Excel itself gives the calendar date, but a calendar date in January or
     * February 1900 comes out one higher than Excel's (1900-01-01 is 2, where Excel says 1).
     *
     * Returns an int for dates with no time component, mirroring how
     * PhpSpreadsheet yields a whole-number serial for date-only cells.
     */
    public static function dateToSerial(DateTimeInterface $date): int|float
    {
        $days = (int) floor(($date->getTimestamp() + $date->getOffset()) / 86400) + self::UNIX_EPOCH_SERIAL;

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
