<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Exception;

/**
 * A transport returned something that is not a Response. This is a bug in
 * the transport, not a network failure, so it is thrown rather than turned
 * into a result.
 */
final class TransportContractException extends \LogicException implements GnewsUnwrapException
{
}
