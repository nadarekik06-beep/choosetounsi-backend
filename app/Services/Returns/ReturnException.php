<?php

namespace App\Services\Returns;

/** A return step that is not allowed now (wrong state, missing input). Rendered as 422. */
class ReturnException extends \RuntimeException
{
}
