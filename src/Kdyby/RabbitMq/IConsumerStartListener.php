<?php

namespace Kdyby\RabbitMq;


/**
 * DI registered services implementing this interface will listen to every consumer onStart event.
 * @package Kdyby\RabbitMq
 * @author Jakub Adamus <adamus@makuma.cz>
 */
interface IConsumerStartListener
{

	/**
	 *
	 * @param Consumer $consumer Consumer currently starting
	 * @return NULL nothing to return
	 */
	public function onStartListener(Consumer $consumer);

}
