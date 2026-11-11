<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Exception;

/**
 * Base for every exception this package throws.
 *
 * The package only throws for programming errors (bad configuration, a
 * transport that breaks its contract). Bad input never throws.
 */
interface GnewsUnwrapException extends \Throwable
{
}
