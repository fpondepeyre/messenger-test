<?php

namespace Zenstruck\Messenger\Test;

use PHPUnit\Framework\Assert as PHPUnit;
use Symfony\Component\Messenger\Envelope;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @mixin Envelope
 */
final class TestEnvelope
{
    /**
     * @var \Symfony\Component\Messenger\Envelope
     */
    private $envelope;

    public function __construct(Envelope $envelope)
    {
        $this->envelope = $envelope;
    }

    public function __call($name, $arguments)
    {
        return $this->envelope->{$name}(...$arguments);
    }

    /**
     * @return $this
     */
    public function assertHasStamp(string $class)
    {
        PHPUnit::assertNotEmpty($this->envelope->all($class));

        return $this;
    }

    /**
     * @return $this
     */
    public function assertNotHasStamp(string $class)
    {
        PHPUnit::assertEmpty($this->envelope->all($class));

        return $this;
    }
}
