<?php

declare(strict_types=1);

namespace Vitalcare;

/** A mail-sending failure with a short message that never contains credentials. */
final class SmtpException extends \RuntimeException
{
}
