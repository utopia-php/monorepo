<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Utopia\Queue\Codec\Plain;

/**
 * The normalizer every codec that can carry an object applies. It is tested here rather
 * than through such a codec because ext-igbinary is not on every build, and a guard that
 * only runs where the extension happens to be installed is the guard that goes missing.
 *
 * {@see CodecTest} covers the same contract as a consumer sees it, through the codecs.
 */
final class PlainTest extends TestCase
{
    public function testAnArrayObjectBecomesItsStorage(): void
    {
        $plain = Plain::of(['payload' => new ArrayObject(['id' => 'p1', 'name' => 'test'])]);

        $this->assertSame(['payload' => ['id' => 'p1', 'name' => 'test']], $plain);
    }

    public function testAnArrayObjectNestedInsideAnotherIsFlattenedToo(): void
    {
        // getArrayCopy() flattens one level, so the inner one survives it. This is the
        // case a reduction inside a message class misses.
        $plain = Plain::of(['payload' => new ArrayObject(['id' => 'p1', 'team' => new ArrayObject(['id' => 't1'])])]);

        $this->assertSame(['payload' => ['id' => 'p1', 'team' => ['id' => 't1']]], $plain);
    }

    public function testAnEmptyMapRenderedForJsonBecomesAnEmptyArray(): void
    {
        // A rendered API response carries new stdClass() wherever a map is empty, because
        // that is what {} has to look like in JSON. A consumer already receives [] for
        // these under Json, so this keeps what it sees rather than changing it.
        $plain = Plain::of(['payload' => ['prefs' => new \stdClass(), 'name' => 'test']]);

        $this->assertSame(['payload' => ['prefs' => [], 'name' => 'test']], $plain);
    }

    public function testAClassThisDoesNotKnowIsLeftAlone(): void
    {
        // The boundary, on purpose. Reshaping a type nobody considered is how this class
        // of defect is made; a consumer that publishes one should see it, not have it
        // quietly turned into something else.
        $date = new \DateTimeImmutable('2026-01-01 00:00:00');

        $this->assertSame(['payload' => $date], Plain::of(['payload' => $date]));
    }

    public function testScalarsAndNullArePassedThrough(): void
    {
        $this->assertSame(['s' => 'utopia', 'i' => 42, 'f' => 1.5, 'b' => false, 'n' => null, 'e' => []], Plain::of([
            's' => 'utopia', 'i' => 42, 'f' => 1.5, 'b' => false, 'n' => null, 'e' => [],
        ]));
    }

    public function testTheSameObjectTwiceIsNotACycle(): void
    {
        // The same object twice in one payload has a flat form, and refusing it would
        // turn an ordinary message into a poison one.
        $shared = new ArrayObject(['id' => 't1']);

        $this->assertSame(['a' => ['id' => 't1'], 'b' => ['id' => 't1']], Plain::of(['a' => $shared, 'b' => $shared]));
    }

    public function testAPayloadThatContainsItselfIsRefused(): void
    {
        // A binary format carries a self-referential graph happily and json_encode
        // refuses one, so this has to refuse it too rather than descend until the stack
        // ends. On decode that makes it a poison message the broker parks; descending
        // takes the worker down with it.
        /** @var ArrayObject<string, mixed> $cycle */
        $cycle = new ArrayObject(['id' => 'p1']);
        $cycle['self'] = $cycle;

        $this->expectException(RuntimeException::class);

        Plain::of(['payload' => $cycle]);
    }

    public function testAnArrayThatContainsItselfIsRefusedToo(): void
    {
        // A reference rather than an object, which is why tracking visited objects was
        // not enough: nothing about this loop involves an object at all.
        $cycle = ['id' => 'p1'];
        $cycle['self'] = &$cycle;

        $this->expectException(RuntimeException::class);

        Plain::of(['payload' => $cycle]);
    }

    public function testItCarriesTheDepthJsonCarries(): void
    {
        // The limit is not a number this picked: a payload this accepts has to be one
        // JSON would have accepted, or switching codec starts refusing work that used to
        // go through. json_encode is asserted here beside it so the number cannot drift.
        json_encode($this->nest(512), JSON_THROW_ON_ERROR);

        $this->assertSame('leaf', $this->leafOf(Plain::of($this->nest(512))));
    }

    public function testItRefusesTheDepthJsonRefuses(): void
    {
        $this->expectException(\JsonException::class);
        json_encode($this->nest(513), JSON_THROW_ON_ERROR);
    }

    public function testADeeperPayloadIsRefusedRatherThanCarried(): void
    {
        $this->expectException(RuntimeException::class);

        Plain::of($this->nest(513));
    }

    public function testAnObjectCostsWhatTheArrayItStandsForCosts(): void
    {
        // An ArrayObject is one level, not two. Charging for the conversion as well
        // would refuse an object payload at half the nesting its JSON equivalent has,
        // which is the same message passing on one codec and parked on the other.
        $deep = $this->nest(512, fn(array $level): ArrayObject => new ArrayObject($level));

        $this->assertSame('leaf', $this->leafOf(Plain::of($deep)));
    }

    /**
     * @param  ?callable(array<string, mixed>): mixed  $wrap  what each level is made of
     * @return array<string, mixed>
     */
    private function nest(int $levels, ?callable $wrap = null): array
    {
        $value = ['leaf' => 'leaf'];

        for ($i = 1; $i < $levels; $i++) {
            $value = ['down' => $wrap === null ? $value : $wrap($value)];
        }

        return $value;
    }

    private function leafOf(mixed $value): mixed
    {
        while (\is_array($value) && isset($value['down'])) {
            $value = $value['down'];
        }

        return \is_array($value) ? $value['leaf'] ?? null : null;
    }
}
