<?php

namespace App\Services\Insurer\Exceptions;

/** The insurer did not process the request and asked us to retry later. */
final class InsurerRetriableFailure extends InsurerException
{
}
