<?php

namespace App\Services\Insurer\Exceptions;

/** The insurer declined the request. Retrying will not help. */
final class InsurerPermanentFailure extends InsurerException
{
}
