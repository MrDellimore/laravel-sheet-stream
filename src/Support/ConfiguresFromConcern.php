<?php

namespace MrDellimore\SheetStream\Support;

trait ConfiguresFromConcern
{
    /**
     * The job's blank_cells setting, falling back to config for payloads queued before the
     * property existed. Only for jobs that declare a $blankCellsAsNull property.
     */
    private function resolvedBlankCellsAsNull(): bool
    {
        return $this->blankCellsAsNull ?? RowHelper::blankCellsAsNull(config('sheet-stream.blank_cells'));
    }

    private function applyJobConfig(object $subject, ?object $fallback = null): void
    {
        $this->tries = $this->resolveProperty('tries', $subject, $fallback);
        $this->timeout = $this->resolveProperty('timeout', $subject, $fallback);
        $this->onQueue($this->resolveProperty('queue', $subject, $fallback));
        $this->onConnection($this->resolveProperty('connection', $subject, $fallback));
    }

    private function resolveProperty(string $prop, object $subject, ?object $fallback): mixed
    {
        if (property_exists($subject, $prop)) {
            return $subject->{$prop};
        }

        if ($fallback !== null && property_exists($fallback, $prop)) {
            return $fallback->{$prop};
        }

        return null;
    }
}
