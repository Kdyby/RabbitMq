<?php

namespace Kdyby\RabbitMq;



/**
 * @author Alvaro Videla <videlalvaro@gmail.com>
 * @author Filip Procházka <filip@prochazka.su>
 */
interface IConsumer
{

	/**
	 * Flag for message ack
	 */
	const MSG_ACK = 1;

	/**
	 * Flag single for message nack and requeue
	 */
	const MSG_SINGLE_NACK_REQUEUE = 2;

	/**
	 * Flag for reject and requeue
	 */
	const MSG_REJECT_REQUEUE = 0;

	/**
	 * Flag for reject and drop
	 */
	const MSG_REJECT = -1;



	function setExchangeOptions(array $options = array());

	function setQueueOptions(array $options = array());

	function setRoutingKey($routingKey);

}
