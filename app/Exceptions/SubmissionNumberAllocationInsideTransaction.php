<?php

namespace App\Exceptions;

use LogicException;

final class SubmissionNumberAllocationInsideTransaction extends LogicException
{
    public function __construct()
    {
        parent::__construct(
            'SubmissionNumberGenerator::generate() must not be called while the connection already has an active transaction, otherwise an outer rollback could undo an allocated submission number.',
        );
    }
}
