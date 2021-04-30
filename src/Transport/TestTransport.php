<?php

namespace Zenstruck\Messenger\Test\Transport;

use PHPUnit\Framework\Assert as PHPUnit;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Worker;
use Zenstruck\Messenger\Test\EnvelopeCollection;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class TestTransport implements TransportInterface
{
    private const DEFAULT_OPTIONS = [
        'intercept' => true,
        'catch_exceptions' => true,
    ];

    /**
     * @var \Symfony\Component\Messenger\MessageBusInterface
     */
    private $bus;
    /**
     * @var \Symfony\Component\Messenger\Transport\Serialization\SerializerInterface
     */
    private $serializer;
    /**
     * @var bool
     */
    private $intercept;
    /**
     * @var bool
     */
    private $catchExceptions;

    /** @var Envelope[] */
    private $sent = [];

    /** @var Envelope[] */
    private $acknowledged = [];

    /** @var Envelope[] */
    private $rejected = [];

    /** @var array<string, Envelope> */
    private $queue = [];

    /**
     * @internal
     */
    public function __construct(MessageBusInterface $bus, SerializerInterface $serializer, array $options = [])
    {
        $options = \array_merge(self::DEFAULT_OPTIONS, $options);

        $this->bus = $bus;
        $this->serializer = $serializer;
        $this->intercept = $options['intercept'];
        $this->catchExceptions = $options['catch_exceptions'];
    }

    /**
     * Processes any messages on the queue and processes future messages
     * immediately.
     * @return $this
     */
    public function unblock()
    {
        // process any messages currently on queue
        $this->process();

        $this->intercept = false;

        return $this;
    }

    /**
     * Intercepts any future messages sent to queue.
     * @return $this
     */
    public function intercept()
    {
        $this->intercept = true;

        return $this;
    }

    /**
     * @return $this
     */
    public function catchExceptions()
    {
        $this->catchExceptions = true;

        return $this;
    }

    /**
     * @return $this
     */
    public function throwExceptions()
    {
        $this->catchExceptions = false;

        return $this;
    }

    /**
     * @param int|null $number int: the number of messages on the queue to process
     *                         null: process all messages on the queue
     * @return $this
     */
    public function process(?int $number = null)
    {
        $count = \count($this->queue);

        if (null === $number) {
            return $this->process($count);
        }

        if (0 === $count) {
            return $this;
        }

        PHPUnit::assertGreaterThanOrEqual($number, $count, "Tried to process {$number} queued messages but only {$count} are on in the queue.");

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($number));

        if (!$this->catchExceptions) {
            $eventDispatcher->addListener(WorkerMessageFailedEvent::class, function(WorkerMessageFailedEvent $event) {
                throw $event->getThrowable();
            });
        }

        $worker = new Worker([$this], $this->bus, $eventDispatcher);
        $worker->run(['sleep' => 0]);

        return $this;
    }

    public function queue(): EnvelopeCollection
    {
        return new EnvelopeCollection(...\array_values($this->queue));
    }

    public function sent(): EnvelopeCollection
    {
        return new EnvelopeCollection(...$this->sent);
    }

    public function acknowledged(): EnvelopeCollection
    {
        return new EnvelopeCollection(...$this->acknowledged);
    }

    public function rejected(): EnvelopeCollection
    {
        return new EnvelopeCollection(...$this->rejected);
    }

    /**
     * @internal
     */
    public function get(): iterable
    {
        return \array_values($this->queue);
    }

    /**
     * @internal
     */
    public function ack(Envelope $envelope): void
    {
        $this->acknowledged[] = $envelope;
        unset($this->queue[\spl_object_hash($envelope->getMessage())]);
    }

    /**
     * @internal
     */
    public function reject(Envelope $envelope): void
    {
        $this->rejected[] = $envelope;
        unset($this->queue[\spl_object_hash($envelope->getMessage())]);
    }

    /**
     * @internal
     */
    public function send(Envelope $envelope): Envelope
    {
        // ensure serialization works (todo configurable? better error on failure?)
        $this->serializer->decode($this->serializer->encode($envelope));

        $this->sent[] = $envelope;
        $this->queue[\spl_object_hash($envelope->getMessage())] = $envelope;

        if (!$this->intercept) {
            $this->process();
        }

        return $envelope;
    }

    public function reset(): void
    {
        $this->queue = $this->sent = $this->acknowledged = $this->rejected = [];
    }
}
