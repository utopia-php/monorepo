<?php

declare(strict_types=1);

namespace Utopia\Reputation;

/**
 * One Verdict lookup. `verdict` is the matchable value; score and categories
 * are kept so callers that need them do not look up twice.
 */
final readonly class Record
{
    /**
     * @param list<string> $categories
     */
    public function __construct(
        public string $ip,
        public Verdict $verdict,
        public int $score,
        public array $categories,
    ) {}

    public static function clean(string $ip): self
    {
        return new self($ip, Verdict::Clean, 0, []);
    }
}
