<?php

namespace MrDellimore\SheetStream\Support;

trait ConfiguresFromConcern
{
    private function applyJobConfig(object $subject, ?object $fallback = null): void
    {
        $this->tries = $subject->tries ?? $fallback?->tries;
        $this->timeout = $subject->timeout ?? $fallback?->timeout;
        $this->onQueue($subject->queue ?? $fallback?->queue);
        $this->onConnection($subject->connection ?? $fallback?->connection);
    }
}
