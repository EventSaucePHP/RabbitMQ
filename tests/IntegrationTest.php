<?php

namespace EventSauce\RabbitMQ\Tests;

use EventSauce\EventSourcing\DefaultHeadersDecorator;
use EventSauce\EventSourcing\Message;
use EventSauce\EventSourcing\Serialization\ConstructingMessageSerializer;
use EventSauce\EventSourcing\Time\TestClock;
use EventSauce\RabbitMQ\NaiveExceptionHandler;
use EventSauce\RabbitMQ\RabbitMQConsumer;
use EventSauce\RabbitMQ\RabbitMQMessageDispatcher;
use OldSound\RabbitMqBundle\RabbitMq\Consumer;
use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;
use OldSound\RabbitMqBundle\RabbitMq\Producer;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PHPUnit\Framework\TestCase;
use Throwable;

class IntegrationTest extends TestCase
{
    /**
     * @var AMQPStreamConnection
     */
    private $connection;

    /**
     * @var Producer
     */
    private $producer;

    /**
     * @var AMQPChannel
     */
    private $channel;

    /**
     * @var Consumer
     */
    private $consumer;

    /**
     * @before
     */
    public function setupConnection()
    {
        $connection = new AMQPStreamConnection('localhost', '5672', 'guest', 'guest');
        $channel = $connection->channel();
        $queue = 'test.queue';
        $exchange = 'test.exchange';

        $producer = new Producer($connection, $channel, 'producer');
        $exchangeOptions = [
            'name'        => $exchange,
            'passive'     => false,
            'durable'     => true,
            'auto_delete' => false,
            'type'        => 'direct',
        ];
        $producer->setExchangeOptions($exchangeOptions);
        $queueOptions = [
            'name'        => $queue,
            'passive'     => false,
            'durable'     => true,
            'exclusive'   => false,
            'auto_delete' => false,
        ];
        $producer->setQueueOptions($queueOptions);

        $this->producer = $producer;
        $this->connection = $connection;
        $this->channel = $channel;

        $consumer = new Consumer($connection, $channel, 'consumer');
        $consumer->setQueueOptions($queueOptions);
        $consumer->setExchangeOptions($exchangeOptions);
        $consumer->setIdleTimeout(0.5);
        $this->consumer = $consumer;
    }

    /**
     * @after
     */
    public function closeConnection()
    {
        try {
            $this->consumer->stopConsuming();
            $this->consumer->delete();
        } catch (Throwable $ignore) {
        }

        $this->channel->close();
        $this->connection->close();
    }

    /**
     * @test
     */
    public function it_works()
    {
        $serializer = new ConstructingMessageSerializer();
        $dispatcher = new RabbitMQMessageDispatcher($this->producer, $serializer);
        $event = new TestEvent((new TestClock())->pointInTime());
        $message = (new DefaultHeadersDecorator())->decorate(new Message($event));
        $dispatcher->dispatch($message);

        $collector = new CollectingConsumer();
        $messageConsumer = new RabbitMQConsumer($collector, $serializer);

        $this->consumer->setCallback([$messageConsumer, 'execute']);
        $this->consumer->consume(1);

        $this->assertEquals($message, $collector->message);
    }

    /**
     * @test
     */
    public function requeue_rejected_messages()
    {
        $serializer = new ConstructingMessageSerializer();
        $dispatcher = new RabbitMQMessageDispatcher($this->producer, $serializer);
        $event = new TestEvent((new TestClock())->pointInTime());
        $message = (new DefaultHeadersDecorator())->decorate(new Message($event));
        $dispatcher->dispatch($message);

        $exceptionThrower = new ExceptionThrowingConsumer();
        $messageConsumer = new RabbitMQConsumer($exceptionThrower, $serializer);

        $this->consumer->setCallback([$messageConsumer, 'execute']);
        $this->consumer->consume(1);
        $this->assertEquals(1, $exceptionThrower->counter);
    }

    /**
     * @test
     */
    public function nacking_messages()
    {
        $serializer = new ConstructingMessageSerializer();
        $dispatcher = new RabbitMQMessageDispatcher($this->producer, $serializer);
        $event = new TestEvent((new TestClock())->pointInTime());
        $message = (new DefaultHeadersDecorator())->decorate(new Message($event));
        $dispatcher->dispatch($message);

        $exceptionThrower = new ExceptionThrowingConsumer();
        $messageConsumer = new RabbitMQConsumer($exceptionThrower, $serializer, new NaiveExceptionHandler(ConsumerInterface::MSG_REJECT));

        $this->consumer->setCallback([$messageConsumer, 'execute']);

        try {
            $this->consumer->consume(2);
        } catch (AMQPTimeoutException $idledTooLong) {

        }
        $this->assertTrue(isset($idledTooLong));
        $this->assertEquals(1, $exceptionThrower->counter);
    }
}