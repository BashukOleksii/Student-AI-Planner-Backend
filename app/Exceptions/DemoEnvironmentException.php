<?php

namespace App\Exceptions;

use RuntimeException;

/** Fixed operator diagnostics only; never wrap SQL, configuration secrets or credentials. */
class DemoEnvironmentException extends RuntimeException {}
