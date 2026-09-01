<?php

namespace MrDellimore\SheetStream\Support;

trait ConfiguresFromConcern
{
    private function applyJobConfig(object $subject, ?object $fallback = null): void
    {
        $this->tries = $subject->tries ?? $fallback?->tries ?? null;
        $this->timeout = $subject->timeout ?? $fallback?->timeout ?? null;
        $this->onQueue($subject->queue ?? $fallback?->queue ?? null);
        $this->onConnection($subject->connection ?? $fallback?->connection ?? null);
    }
}
