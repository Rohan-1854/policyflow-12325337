<?php

namespace App\Exceptions;

use LogicException;

/**
 * Programming error: code tried to change the accepted premium or the
 * business inputs of an existing policy request. Deliberately not a
 * client-facing exception - it should surface as a 500 and be logged.
 */
final class ImmutableSnapshotViolation extends LogicException
{
    public function __construct(public readonly string $attribute)
    {
        parent::__construct("Attribute '{$attribute}' of a policy request is immutable once created.");
    }
}
