<?php

namespace Zenstruck\Messenger\Test;

use PHPUnit\Framework\Assert as PHPUnit;
use Symfony\Component\Messenger\Envelope;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class EnvelopeCollection implements \IteratorAggregate, \Countable
{
    /**
     * @var mixed[]
     */
    private $envelopes;

    /**
     * @internal
     */
    public function __construct(Envelope ...$envelopes)
    {
        $this->envelopes = $envelopes;
    }

    /**
     * @return $this
     */
    public function assertEmpty()
    {
        return $this->assertCount(0);
    }

    /**
     * @return $this
     */
    public function assertNotEmpty()
    {
        PHPUnit::assertNotEmpty($this, 'Expected some messages but found none.');

        return $this;
    }

    /**
     * @return $this
     */
    public function assertCount(int $count)
    {
        PHPUnit::assertCount($count, $this->envelopes, \sprintf('Expected %d messages, but %d messages found.', $count, \count($this->envelopes)));

        return $this;
    }

    /**
     * @return $this
     */
    public function assertContains(string $messageClass, ?int $times = null)
    {
        $actual = $this->messages($messageClass);

        PHPUnit::assertNotEmpty($actual, "Message \"{$messageClass}\" not found.");

        if (null !== $times) {
            PHPUnit::assertCount($times, $actual, \sprintf('Expected to find message "%s" %d times but found %d times.', $messageClass, $times, \count($actual)));
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function assertNotContains(string $messageClass)
    {
        $actual = $this->messages($messageClass);

        PHPUnit::assertEmpty($actual, "Found message \"{$messageClass}\" but should not.");

        return $this;
    }

    /**
     * @param string|callable|null $filter
     */
    public function first($filter = null): TestEnvelope
    {
        if (null === $filter) {
            // just the first envelope
            return $this->first(function () {
                return true;
            });
        }

        if (!\is_callable($filter)) {
            // first envelope for message class
            return $this->first(function (Envelope $e) use ($filter) {
                return $filter === \get_class($e->getMessage());
            });
        }

        $filter = self::normalizeFilter($filter);

        foreach ($this->envelopes as $envelope) {
            if ($filter($envelope)) {
                return new TestEnvelope($envelope);
            }
        }

        throw new \RuntimeException('No envelopes found.');
    }

    /**
     * The messages extracted from envelopes.
     *
     * @param string|null $class Only messages of this class
     *
     * @return object[]
     */
    public function messages(?string $class = null): array
    {
        $messages = \array_map(function (Envelope $envelope) {
            return $envelope->getMessage();
        }, $this->envelopes);

        if (!$class) {
            return $messages;
        }

        return \array_values(\array_filter($messages, function (object $message) use ($class) {
            return $class === \get_class($message);
        }));
    }

    /**
     * @return TestEnvelope[]
     */
    public function all(): array
    {
        return \iterator_to_array($this);
    }

    public function getIterator(): \Iterator
    {
        foreach ($this->envelopes as $envelope) {
            yield new TestEnvelope($envelope);
        }
    }

    public function count(): int
    {
        return \count($this->envelopes);
    }

    private static function normalizeFilter(callable $filter): callable
    {
        $function = new \ReflectionFunction(\Closure::fromCallable($filter));

        if (!$parameter = $function->getParameters()[0] ?? null) {
            return $filter;
        }

        if (!$type = $parameter->getType()) {
            return $filter;
        }

        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || Envelope::class === $type->getName()) {
            return $filter;
        }

        // user used message class name as type-hint
        return function(Envelope $envelope) use ($filter, $type) {
            if ($type->getName() !== \get_class($envelope->getMessage())) {
                return false;
            }

            return $filter($envelope->getMessage());
        };
    }
}
