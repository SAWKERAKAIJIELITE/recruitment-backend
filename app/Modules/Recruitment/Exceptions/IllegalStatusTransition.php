<?php

namespace App\Modules\Recruitment\Exceptions;

use DomainException;

/** Thrown when code tries to move an application along a path the business rules do not allow. */
class IllegalStatusTransition extends DomainException
{
}
