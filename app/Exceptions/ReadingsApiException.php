<?php

namespace App\Exceptions;

use RuntimeException;

/** The remote readings source failed or returned nothing usable. */
class ReadingsApiException extends RuntimeException {}
