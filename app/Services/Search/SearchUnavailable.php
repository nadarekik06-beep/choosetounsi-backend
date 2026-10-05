<?php

namespace App\Services\Search;

use RuntimeException;

/** The AI service (or the photo index) can't answer: photo search shows "unavailable", text search is unaffected. */
class SearchUnavailable extends RuntimeException
{
    public function __construct(string $message = '', public int $status = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }
}
