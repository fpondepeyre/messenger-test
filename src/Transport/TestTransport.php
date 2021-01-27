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
     * @var string
     */
    private $name;
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

    /** @var array<string, Envelope[]> */
    private static $sent = [];

    /** @var array<string, Envelope[]> */
    private static $acknowledged = [];

    /** @var array<string, Envelope[]> */
    private static $rejected = [];

    /** @var array<string, Envelope[]> */
    private static $queue = [];

    /**
     * @internal
     */
    public function __construct(string $name, MessageBusInterface $bus, SerializerInterface $serializer, array $options = [])
    {
        $options = \array_merge(self::DEFAULT_OPTIONS, $options);

        $this->name = $name;
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
        $count = \count(self::$queue[$this->name] ?? []);

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
        return new EnvelopeCollection(...\array_values(self::$queue[$this->name] ?? []));
    }

    public function sent(): EnvelopeCollection
    {
        return new EnvelopeCollection(...self::$sent[$this->name] ?? []);
    }

    public function acknowledged(): EnvelopeCollection
    {
        return new EnvelopeCollection(...self::$acknowledged[$this->name] ?? []);
    }

    public function rejected(): EnvelopeCollection
    {
        return new EnvelopeCollection(...self::$rejected[$this->name] ?? []);
    }

    /**
     * @internal
     */
    public function get(): iterable
    {
        return \array_values(self::$queue[$this->name] ?? []);
    }

    /**
     * @internal
     */
    public function ack(Envelope $envelope): void
    {
        self::$acknowledged[$this->name][] = $envelope;
        unset(self::$queue[$this->name][\spl_object_hash($envelope->getMessage())]);
    }

    /**
     * @internal
     */
    public function reject(Envelope $envelope): void
    {
        self::$rejected[$this->name][] = $envelope;
        unset(self::$queue[$this->name][\spl_object_hash($envelope->getMessage())]);
    }

    /**
     * @internal
     */
    public function send(Envelope $envelope): Envelope
    {
        // ensure serialization works (todo configurable? better error on failure?)
        $this->serializer->decode($this->serializer->encode($envelope));

        self::$sent[$this->name][] = $envelope;
        self::$queue[$this->name][\spl_object_hash($envelope->getMessage())] = $envelope;

        if (!$this->intercept) {
            $this->process();
        }

        return $envelope;
    }

    public static function reset(): void
    {
        self::$queue = self::$sent = self::$acknowledged = self::$rejected = [];
    }
}
