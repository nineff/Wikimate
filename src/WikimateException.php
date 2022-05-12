<?php

declare(strict_types=1);

namespace Hamstar\Wikimate;

/**
 * Defines Wikimate's exception for unexpected run-time errors
 * while communicating with the API.
 * WikimateException can be thrown only from Wikimate::request(),
 * and is propagated to callers of this library.
 *
 * @author  Frans P. de Vries
 *
 * @since   1.0.0  August 2021
 * @see    https://www.php.net/manual/en/class.runtimeexception.php
 */
class WikimateException extends \RuntimeException
{
}
