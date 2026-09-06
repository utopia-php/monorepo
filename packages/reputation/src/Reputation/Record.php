<?php

declare(strict_types=1);

namespace Utopia\Reputation;

/**
 * One IP's reputation. Constructing a record does not search the database;
 * {@see getVerdict()}, {@see getScore()}, and {@see getCategories()} run the
 * lookup once and reuse it.
 */
final class Record
{
    private Verdict $verdict = Verdict::Clean;

    private int $score = 0;

    /**
     * @var list<string>
     */
    private array $categories = [];

    /**
     * @param (callable(): mixed)|null $lookup
     *        Fetches the raw MMDB (or test) payload. Null means no search —
     *        the record stays {@see Verdict::Clean}.
     */
    private function __construct(
        private readonly string $ip,
        private readonly mixed $lookup,
        private bool $resolved,
    ) {}

    /**
     * @param callable(): mixed $lookup
     */
    public static function pending(string $ip, callable $lookup): self
    {
        return new self($ip, $lookup, false);
    }

    public static function clean(string $ip): self
    {
        return new self($ip, null, true);
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getVerdict(): Verdict
    {
        $this->load();

        return $this->verdict;
    }

    public function getScore(): int
    {
        $this->load();

        return $this->score;
    }

    /**
     * @return list<string>
     */
    public function getCategories(): array
    {
        $this->load();

        return $this->categories;
    }

    private function load(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->resolved = true;

        $lookup = $this->lookup;
        if (! \is_callable($lookup)) {
            return;
        }

        $payload = $lookup();
        if (! \is_array($payload)) {
            return;
        }

        $rawVerdict = $payload['verdict'] ?? null;
        $this->verdict = \is_string($rawVerdict)
            ? (Verdict::tryFrom(strtolower($rawVerdict)) ?? Verdict::Clean)
            : Verdict::Clean;

        $rawScore = $payload['score'] ?? null;
        $this->score = \is_int($rawScore) ? $rawScore : 0;

        $categories = [];
        if (isset($payload['categories']) && \is_array($payload['categories'])) {
            foreach ($payload['categories'] as $category) {
                if (\is_string($category)) {
                    $categories[] = $category;
                }
            }
        }

        $this->categories = $categories;
    }
}
