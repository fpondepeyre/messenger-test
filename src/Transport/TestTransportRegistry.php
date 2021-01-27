<?php

namespace Zenstruck\Messenger\Test\Transport;

use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 */
final class TestTransportRegistry
{
    /** @var TransportInterface[] */
    private $transports = [];

    public function register(string $name, TransportInterface $transport): void
    {
        $this->transports[$name] = $transport;
    }

    public function get(?string $name = null): TestTransport
    {
        if (0 === \count($this->transports)) {
            throw new \LogicException('No transports registered.');
        }

        if (null === $name && 1 !== \count($this->transports)) {
            throw new \InvalidArgumentException(\sprintf('Multiple transports are registered (%s), you must specify a name.', \implode(', ', \array_keys($this->transports))));
        }

        if (null === $name) {
            $name = \array_key_first($this->transports);
        }

        if (!$transport = $this->transports[$name] ?? null) {
            throw new \InvalidArgumentException("Transport \"{$name}\" not registered.");
        }

        if (!$transport instanceof TestTransport) {
            throw new \LogicException("Transport \"{$name}\" needs to be set to \"test://\" in your test config to use this feature.");
        }

        return $transport;
    }
}
