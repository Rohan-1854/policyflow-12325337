<?php

namespace App\Services\Insurer\Exceptions;

/** No usable response: the insurer may or may not have acted. Reconcile before re-issuing. */
final class InsurerTimeout extends InsurerException
{
}
