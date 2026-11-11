<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Internal;

use IndianOpinion\GnewsUnwrap\Attempt;
use IndianOpinion\GnewsUnwrap\Method;
use IndianOpinion\GnewsUnwrap\Reason;
use IndianOpinion\GnewsUnwrap\Transport\Response;

/**
 * Mutable state of one resolution while it runs.
 *
 * @internal
 */
final class LinkRun
{
    /** @var list<Attempt> */
    public array $attempts = [];
    public int $requests = 0;
    public bool $rateLimited = false;
    public bool $insecureTarget = false;
    public ?string $ampUrl = null;
    /** @var array<string, Response> GET responses already fetched in this resolution */
    public array $memo = [];
    /** @var array{url: string, body: string}|null last HTML page from a Google host */
    public ?array $page = null;
    /** @var array{url: string, body: string}|null page fetched by batch_execute */
    public ?array $batchPage = null;

    public function __construct(public readonly float $start)
    {
    }

    public function fail(Method $method, Reason $reason): void
    {
        $this->attempts[] = new Attempt($method, $reason);
    }

    public function succeed(Method $method): void
    {
        $this->attempts[] = new Attempt($method, null);
    }

    public function lastReason(): ?Reason
    {
        for ($i = count($this->attempts) - 1; $i >= 0; $i--) {
            if ($this->attempts[$i]->reason !== null) {
                return $this->attempts[$i]->reason;
            }
        }

        return null;
    }

    public function sawTransient(): bool
    {
        foreach ($this->attempts as $attempt) {
            if ($attempt->reason !== null && $attempt->reason->isTransient()) {
                return true;
            }
        }

        return false;
    }
}
