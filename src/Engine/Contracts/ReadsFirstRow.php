<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Engine\Contracts;

/**
 * A SheetReader whose rows() fetches ahead in batches implements this so a caller that
 * only wants the first row (SheetStream::headers()) doesn't pull a whole batch.
 */
interface ReadsFirstRow
{
    /**
     * The sheet's first row, or null when the sheet has none. Reads that one row only;
     * a later rows() call continues with the row after it.
     *
     * @return array<int|string, scalar|null|\DateTimeInterface>|null
     */
    public function firstRow(): ?array;
}
