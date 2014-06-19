<?php

namespace Kdyby\RabbitMq;

use PhpAmqpLib\Message\AMQPMessage;



/**
 * @author Alvaro Videla <videlalvaro@gmail.com>
 * @author Filip Procházka <filip@prochazka.su>
 */
class RpcServer extends BaseConsumer
{

	public function initServer($name)
	{
		$this->setExchangeOptions(array('name' => $name, 'type' => 'direct'));
		$this->setQueueOptions(array('name' => $name . '-queue'));
	}



	public function processMessage(AMQPMessage $msg)
	{
		try {
			$msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
			$result = call_user_func($this->callback, $msg);
			$this->sendReply(serialize($result), $msg->get('reply_to'), $msg->get('correlation_id'));
			$this->consumed++;
			$this->maybeStopConsumer();

		} catch (\Exception $e) {
			$this->sendReply('error: ' . $e->getMessage(), $msg->get('reply_to'), $msg->get('correlation_id'));
		}
	}



	protected function sendReply($result, $client, $correlationId)
	{
		$this->getChannel()->basic_publish(
			new AMQPMessage($result, array(
				'content_type' => 'text/plain',
				'correlation_id' => $correlationId
			)),
			$exchange = '',
			$client
		);
	}

}
