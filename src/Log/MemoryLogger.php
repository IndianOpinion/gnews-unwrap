<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Log;

/**
 * Keeps log lines in memory. Handy in tests and in one-off scripts.
 */
final class MemoryLogger implements Logger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log(string $level, string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    /** @return list<string> messages at the given level */
    public function messages(string $level): array
    {
        $out = [];
        foreach ($this->records as $record) {
            if ($record['level'] === $level) {
                $out[] = $record['message'];
            }
        }

        return $out;
    }
}
