<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Transport;

use IndianOpinion\GnewsUnwrap\Exception\TransportException;

/**
 * FakeTransport that also claims concurrency, so tests can drive the
 * concurrent path of resolveMany() without curl. Requests in one batch run
 * one after another; batch sizes are recorded.
 */
final class FakeConcurrentTransport extends FakeTransport implements ConcurrentTransport
{
    /** @var list<int> */
    private array $batchSizes = [];

    public function sendMany(array $requests): array
    {
        $this->batchSizes[] = count($requests);
        $out = [];
        foreach ($requests as $key => $request) {
            try {
                $out[$key] = $this->send($request);
            } catch (TransportException $e) {
                $out[$key] = $e;
            }
        }

        return $out;
    }

    /** @return list<int> */
    public function batchSizes(): array
    {
        return $this->batchSizes;
    }
}
