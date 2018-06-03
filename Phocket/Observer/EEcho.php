<?php

namespace Phocket\Observer;

class EEcho
{
	public $route = '/echo';
	private $clients = [];

        public function __construct()
        {
        }

	public function notifyConnect($clientId)
	{
		$this->clients[] = $clientId;
	}

	public function notifyData($clientId, $data)
	{
		return [[$clientId, $data]];
	}

	public function notifyDisconnect($clientId)
	{

	}

	// this is called every phocket.ini:observerTick seconds
	public function tick()
	{
		$ret = [];
		foreach($this->clients as $clientId)
		{
			$ret[] = [$clientId, "Nothing to do as I only respode to notify.\n".time()];
		}
		return $ret;
	}
}

