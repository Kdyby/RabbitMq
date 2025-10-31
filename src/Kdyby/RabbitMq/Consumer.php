<?php

declare(strict_types = 1);

namespace Kdyby\RabbitMq;

use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * @method onStart(\Kdyby\RabbitMq\Consumer $self)
 * @method onConsume(\Kdyby\RabbitMq\Consumer $self, \PhpAmqpLib\Message\AMQPMessage $msg)
 * @method onReject(\Kdyby\RabbitMq\Consumer $self, \PhpAmqpLib\Message\AMQPMessage $msg, $processFlag)
 * @method onAck(\Kdyby\RabbitMq\Consumer $self, \PhpAmqpLib\Message\AMQPMessage $msg)
 * @method onError(\Kdyby\RabbitMq\Consumer $self, \PhpAmqpLib\Exception\AMQPExceptionInterface $e)
 * @method onTimeout(\Kdyby\RabbitMq\Consumer $self)
 */
class Consumer extends \Kdyby\RabbitMq\BaseConsumer
{

	/**
	 * @var array
	 */
	public $onConsume = [];

	/**
	 * @var array
	 */
	public $onReject = [];

	/**
	 * @var array
	 */
	public $onAck = [];

	/**
	 * @var array
	 */
	public $onStart = [];

	/**
	 * @var array
	 */
	public $onStop = [];

	/**
	 * @var array
	 */
	public $onTimeout = [];

	/**
	 * @var array
	 */
	public $onError = [];

	/**
	 * @var int $memoryLimit
	 */
	protected $memoryLimit;

	/**
	 * Set the memory limit
	 *
	 * @param int $memoryLimit
	 */
	public function setMemoryLimit(int $memoryLimit): void
	{
		$this->memoryLimit = $memoryLimit;
	}

	/**
	 * Get the memory limit
	 */
	public function getMemoryLimit(): ?int
	{
		return $this->memoryLimit;
	}

	public function consume(int $msgAmount): void
	{
		$this->target = $msgAmount;
		$this->setupConsumer();
		$this->onStart($this);

		$this->logger->debug('Consumer started', [
			'rabbitmq_consumer' => $this->exchangeOptions['name'],
			'queue' => $this->queueOptions['name'],
			'target_messages' => $msgAmount,
			'memory_limit_mb' => $this->memoryLimit,
		]);

		$previousErrorHandler = \set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use (&$previousErrorHandler) {
			if (!\preg_match('~stream_select\\(\\)~i', $errstr)) {
				$args = \func_get_args();
				return \call_user_func_array($previousErrorHandler, $args);
			}

			throw new \PhpAmqpLib\Exception\AMQPRuntimeException($errstr . ' in ' . $errfile . ':' . $errline, $errno);
		});

		try {
			while (\count($this->getChannel()->callbacks)) {
				$this->maybeStopConsumer();

				try {
					$this->logger->debug('Waiting for incoming messages...');
					$this->getChannel()->wait(NULL, FALSE, $this->getIdleTimeout());
				} catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
					$this->onTimeout($this);
					// nothing bad happened, right?
					// intentionally not throwing the exception
					$this->logger->debug('Consumer idle timeout reached.', ['rabbitmq_consumer' => $this->exchangeOptions['name']]);
				}
			}

			$this->logger->debug('Consumer loop finished normally', [
				'rabbitmq_consumer' => $this->exchangeOptions['name'],
				'consumed_messages' => $this->consumed,
			]);

		} catch (\PhpAmqpLib\Exception\AMQPRuntimeException $e) {
			\restore_error_handler();

			$this->logger->debug('AMQP runtime exception', [
				'rabbitmq_consumer' => $this->exchangeOptions['name'],
				'exception' => $e->getMessage(),
				'force_stop' => $this->forceStop,
			]);

			// sending kill signal to the consumer causes the stream_select to return false
			// the reader doesn't like the false value, so it throws AMQPRuntimeException
			$this->maybeStopConsumer();
			if ( ! $this->forceStop) {
				$this->onError($this, $e);
				throw $e;
			}

		} catch (AMQPExceptionInterface $e) {
			\restore_error_handler();

			$this->logger->debug('AMQP exception', [
				'rabbitmq_consumer' => $this->exchangeOptions['name'],
				'exception' => $e->getMessage(),
				'exception_class' => \get_class($e),
			]);

			$this->onError($this, $e);
			throw $e;

		} catch (\Kdyby\RabbitMq\Exception\TerminateException $e) {
			$this->logger->debug('Consumer terminated', [
				'rabbitmq_consumer' => $this->exchangeOptions['name'],
				'consumed_messages' => $this->consumed,
				'target_messages' => $this->target,
			]);

			$this->stopConsuming();
		}
	}

	/**
	 * Purge the queue
	 */
	public function purge(): void
	{
		$this->getChannel()->queue_purge($this->queueOptions['name'], TRUE);
	}

	public function processMessage(AMQPMessage $msg): void
	{
		$this->onConsume($this, $msg);

		$this->logger->debug('Processing message', [
			'rabbitmq_consumer' => $this->exchangeOptions['name'],
			'delivery_tag' => $msg->delivery_info['delivery_tag'] ?? null,
			'routing_key' => $msg->delivery_info['routing_key'] ?? null,
		]);

		try {
			$processFlag = \call_user_func($this->callback, $msg);
			$this->handleProcessMessage($msg, $processFlag);

		} catch (\Kdyby\RabbitMq\Exception\TerminateException $e) {
			$this->handleProcessMessage($msg, $e->getResponse());
			throw $e;

		} catch (\Throwable $e) {
			$this->logger->debug('Exception during message processing', [
				'rabbitmq_consumer' => $this->exchangeOptions['name'],
				'delivery_tag' => $msg->delivery_info['delivery_tag'] ?? null,
				'exception' => $e->getMessage(),
				'exception_class' => \get_class($e),
			]);

			$this->onReject($this, $msg, IConsumer::MSG_REJECT_REQUEUE);
			throw $e;
		}
	}

	/**
	 * @param \PhpAmqpLib\Message\AMQPMessage $msg
	 * @param int|bool $processFlag
	 */
	protected function handleProcessMessage(AMQPMessage $msg, $processFlag): void
	{
		if ($processFlag === IConsumer::MSG_REJECT_REQUEUE || $processFlag === FALSE) {
			// Reject and requeue message to RabbitMQ
			$msg->delivery_info['channel']->basic_reject($msg->delivery_info['delivery_tag'], TRUE);
			$this->onReject($this, $msg, $processFlag);

			$this->logger->debug('Message rejected and requeued', [
				'rabbitmq_consumer' => $this->exchangeOptions['name'],
				'delivery_tag' => $msg->delivery_info['delivery_tag'],
				'process_flag' => $processFlag,
			]);

		} elseif ($processFlag === IConsumer::MSG_SINGLE_NACK_REQUEUE) {
			// NACK and requeue message to RabbitMQ
			$msg->delivery_info['channel']->basic_nack($msg->delivery_info['delivery_tag'], FALSE, TRUE);
			$this->onReject($this, $msg, $processFlag);

			$this->logger->debug('Message NACKed and requeued', [
				'rabbitmq_consumer' => $this->exchangeOptions['name'],
				'delivery_tag' => $msg->delivery_info['delivery_tag'],
			]);

		} else {
			if ($processFlag === IConsumer::MSG_REJECT) {
				// Reject and drop
				$msg->delivery_info['channel']->basic_reject($msg->delivery_info['delivery_tag'], FALSE);
				$this->onReject($this, $msg, $processFlag);

				$this->logger->debug('Message rejected and dropped', [
					'rabbitmq_consumer' => $this->exchangeOptions['name'],
					'delivery_tag' => $msg->delivery_info['delivery_tag'],
				]);

			} else {
				// Remove message from queue only if callback return not false
				$msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
				$this->onAck($this, $msg);

				$this->logger->debug('Message acknowledged', [
					'rabbitmq_consumer' => $this->exchangeOptions['name'],
					'delivery_tag' => $msg->delivery_info['delivery_tag'],
				]);
			}
		}

		$this->consumed++;
		$this->maybeStopConsumer();

		if ($this->isRamAlmostOverloaded()) {
			$this->logger->debug('Memory limit reached, stopping consumer', [
				'rabbitmq_consumer' => $this->exchangeOptions['name'],
				'memory_usage_mb' => \round(\memory_get_usage(TRUE) / 1024 / 1024, 2),
				'memory_limit_mb' => $this->memoryLimit,
				'consumed_messages' => $this->consumed,
			]);

			$this->stopConsuming();
		}
	}

	/**
	 * Checks if memory in use is greater or equal than memory allowed for this process
	 *
	 * @return bool
	 */
	protected function isRamAlmostOverloaded(): bool
	{
		if ($this->getMemoryLimit() === NULL) {
			return FALSE;
		}

		return \memory_get_usage(TRUE) >= ($this->getMemoryLimit() * 1024 * 1024);
	}

}
