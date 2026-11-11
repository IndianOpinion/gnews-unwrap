<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

/**
 * Every reason code a result or an attempt can carry.
 *
 * The string values are stable: store them, alert on them, compare them.
 */
enum Reason: string
{
    // Detection (no network).
    case InvalidUrl = 'invalid_url';
    case NotGoogleNews = 'not_google_news';
    case NotAnArticleLink = 'not_an_article_link';
    case MalformedId = 'malformed_id';

    // Offline methods.
    case MissingParameter = 'missing_parameter';
    case OpaqueId = 'opaque_id';
    case UnknownIdFormat = 'unknown_id_format';
    case DecodeFailed = 'decode_failed';

    // batch_execute.
    case SignatureMissing = 'signature_missing';
    case FormatChanged = 'format_changed';

    // Network limits and failures.
    case TooManyRedirects = 'too_many_redirects';
    case RedirectLoop = 'redirect_loop';
    case Timeout = 'timeout';
    case BudgetExceeded = 'budget_exceeded';
    case NetworkError = 'network_error';
    case RateLimited = 'rate_limited';
    case Forbidden = 'forbidden';
    case Gone = 'gone';
    case UpstreamError = 'upstream_error';
    case UnexpectedStatus = 'unexpected_status';
    case ConsentWall = 'consent_wall';
    case LoginWall = 'login_wall';
    case UnexpectedContentType = 'unexpected_content_type';
    case CircuitOpen = 'circuit_open';
    case PrivateAddress = 'private_address';

    // Targets.
    case BadTarget = 'bad_target';
    case NoCandidate = 'no_candidate';
    case NotRedirected = 'not_redirected';

    /**
     * Transient reasons are never cached: the next attempt may succeed.
     */
    public function isTransient(): bool
    {
        return match ($this) {
            self::RateLimited,
            self::UpstreamError,
            self::CircuitOpen,
            self::Timeout,
            self::NetworkError,
            self::BudgetExceeded => true,
            default => false,
        };
    }

    /**
     * Reasons that mean Google changed something or is pushing back.
     * The resolver logs these at warning level.
     */
    public function isWarning(): bool
    {
        return match ($this) {
            self::RateLimited, self::FormatChanged, self::CircuitOpen => true,
            default => false,
        };
    }
}
