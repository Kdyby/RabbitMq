<?php

declare(strict_types = 1);

namespace Kdyby\RabbitMq\Command;

#[\Symfony\Component\Console\Attribute\AsCommand(name: 'rabbitmq:consumer')]
class ConsumerCommand extends \Kdyby\RabbitMq\Command\BaseConsumerCommand
{

	protected function configure(): void
	{
		parent::configure();

		$this->setDescription('Starts a configured consumer');
	}

}
