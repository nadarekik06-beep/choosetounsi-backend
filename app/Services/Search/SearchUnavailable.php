<?php

namespace App\Services\Search;

use RuntimeException;

/** Meilisearch or the embedding service can't answer: callers fall back (MySQL search) or show "unavailable". */
class SearchUnavailable extends RuntimeException
{
    public function __construct(string $message = '', public int $status = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }
}
