<?php

namespace Phocket;

class Handler
{
	public $base = FALSE;
	public $domainName = FALSE;
	public $maxMailSize = 0;
	public $connections = [];
	public $buffers = [];
	public $maxRead = 1024000;
	public $maxSend = 256000;
	public $settings = [];
	private $observers = [];

	public function __construct($settings)
	{
		$this->settings = $settings; 
		$this->domainName = $settings['domain'];

		$this->log = new \Phocket\Log($settings['log']['file'],$settings['log']['level']);
		$this->log->write('Phocket started');

		$this->ctx = new \EventSslContext(\EventSslContext::TLS_SERVER_METHOD, [
			\EventSslContext::OPT_LOCAL_CERT  => ($settings['ssl']['root'].'/'.$settings['domain'].'/'.$settings['ssl']['cert']),
			\EventSslContext::OPT_LOCAL_PK    => ($settings['ssl']['root'].'/'.$settings['domain'].'/'.$settings['ssl']['key']),
			//\EventSslContext::OPT_PASSPHRASE  => '',
			\EventSslContext::OPT_VERIFY_PEER => false, // change to true with authentic cert
			\EventSslContext::OPT_ALLOW_SELF_SIGNED => true // change to false with authentic cert
		]);

		$this->base = new \EventBase();
		if(!$this->base) 
		{
			exit("Couldn't open event base\n");
		}

		\Event::signal($this->base, SIGTERM, [$this, 'sigHandler']);
		\Event::signal($this->base, SIGHUP, [$this, 'sigHandler']);

		$socket = stream_socket_server('tcp://'.$settings['bind-address'].':'.$settings['port'], $errno, $errstr);
		stream_set_blocking($socket, 0);
		$this->listener = new \EventListener($this->base, [$this, 'ev_accept'], $this->ctx, \EventListener::OPT_REUSEABLE | \EventListener::OPT_LEAVE_SOCKETS_BLOCKING, -1, $socket);

		if(!$this->listener)
		{
		    exit("Couldn't create listener\n");
		}

		$this->listener->setErrorCallback([$this, 'ev_lerror']);

		// TODO: Quick and dirty observer implementation .. needs work, including this->observerRegister and this->observerNotify.
		foreach($settings['observers'] as $observer => $status)
		{
			if($status)
			{
				if(!isset($settings['observers.'.$observer]))
				{
					$settings['observers.'.$observer] = [];
				}
				$className = '\\Phocket\\Observer\\'.$observer;
				$observer = new $className($settings['observers.'.$observer]);
				$this->observerRegister($observer);
			}
		}

		$keepaliveEventTimer = \Event::timer($this->base, function($keepalive) use (&$keepaliveEventTimer) {

			/* send PINGS .. need to control the timeing on when these are sent
			foreach($this->connections as $clientId => $params)
			{
				$this->ev_write($clientId, pack('CC', 0b10001001, 0));
			}*/

			foreach($this->observers as $observer)
			{
				$data = $observer->tick();
				if($data)
				{
					foreach($data as $idx => $tmp)
					{
						$this->ev_write($tmp[0], $this->buildFrame($tmp[1]));
					}
				}
			}
                        $keepaliveEventTimer->add($keepalive);
                }, $settings['observerTick']);
                $keepaliveEventTimer->addTimer($settings['observerTick']);

		//while(1)
		//{
			$this->base->dispatch();
		//	sleep(1);
		//}
	}

	public function observerRegister($observer)
	{
		$this->observers[] = $observer;
	}

	public function observerNotifyData($id, $data)
	{
		foreach($this->observers as $observer)
		{
			if($observer->route == $this->connections[$id]['route'])
			{
				$ret = $observer->notifyData($id, $data);
				if($ret)
				{
					foreach($ret as $idx => $tmp)
					{
						$this->ev_write($tmp[0], $this->buildFrame($tmp[1]));
					}
				}
			}
		}
	}

	public function observerNotifyConnect($id)
	{
		foreach($this->observers as $observer)
		{
			if($observer->route == $this->connections[$id]['route'])
			{
				$ret = $observer->notifyConnect($id);
				if($ret)
				{
					foreach($ret as $idx => $tmp)
					{
						$this->ev_write($tmp[0], $this->buildFrame($tmp[1]));
					}
				}
			}
		}
	}

	public function sigHandler($sig)
	{
		switch($sig)
		{
			case SIGTERM:
			{
			}
			break;
			case SIGHUP:
			{
			}
			break;
			default:
			{
			}
		}
	}

	public function ev_accept($listener, $fd=FALSE, $address=NULL, $args=NULL)
	{
		static $id = 0;

		$id += 1;
		$this->log->write('ev_accept('.$id.')',3);
		if(!$fd)
		{
			$this->log->write('ev_accept fd false?!', 3);
			return;
		}

		$this->connections[$id] = [
			'connected' => FALSE,
			'clientData' => '',
			'payload' => '', // what we get from the frame(s)
			'continuation' => FALSE
		];
		$this->connections[$id]['cnxSsl'] = FALSE;

		try {
			$this->connections[$id]['cnx'] = \EventBufferEvent::sslSocket($this->base, $fd, $this->ctx, \EventBufferEvent::SSL_ACCEPTING, \EventBufferEvent::OPT_CLOSE_ON_FREE);
			$this->connections[$id]['cnx']->setCallbacks([$this, 'ev_read'], [$this, 'ev_write'], [$this, 'ev_event'], $id);
			$this->connections[$id]['cnx']->enable(\Event::READ | \Event::WRITE);
		}
		catch(Exception $e)
		{
			$this->log->write('ev_accept('.$id.'): Failed to create new event! '.$e->getMessage(),3);
		}
	}

	function ev_event($id, $event, $events)
	{
		if($events & (\EventBufferEvent::EOF | \EventBufferEvent::ERROR))
		{
			$this->log->write('ev_event(): Freeing Event',3);
// TODO freeing this breaks things.. need to investigate more
//			$id->free();
		}
	}

	function ev_lerror($listener, $ctx)
	{
		$this->log->write('ev_lerror():',3);
	}

	public function ev_close($id)
	{
		$this->log->write('ev_close('.$id.')',3);
		$i=0;
		while(($this->connections[$id]['cnx']->getOutput()->length > 0) && ($i < 64))
		{
			$i++;
			$this->connections[$id]['cnx']->getOutput()->write($this->connections[$id]['fd'], $this->maxRead);
		}

		$i=0;
		while(($this->connections[$id]['cnx']->getInput()->length > 0) && ($i < 64))
		{
			$i++;
			$this->connections[$id]['cnx']->getInput()->read($this->connections[$id]['fd'], $this->maxRead);
		}

		try {
			if($this->connections[$id]['cnxSsl']) $this->connections[$id]['cnxSsl']->disable(\Event::READ | \Event::WRITE);
			$this->connections[$id]['cnx']->disable(\Event::READ | \Event::WRITE);
			if($this->connections[$id]['cnxSsl']) $this->connections[$id]['cnxSsl']->free();
			$this->connections[$id]['cnx']->free();
		}
		catch(Exception $e)
		{
			$this->log->write('ev_close('.$id.'): Failed to write to close! '.$e->getMessage(),3);
		}
		unset($this->connections[$id]);
	}

	protected function ev_write($id, $string)
	{
		try {

			if($this->connections[$id]['cnxSsl'])
			{
				if(!$this->connections[$id]['cnxSsl']->write($string))
				{
					$this->log->write('ev_write('.$id.'): Failed to write to client!',3);
				}
			}
			else
			{
				$this->connections[$id]['cnx']->write($string);
			}
		}
		catch(Exception $e)
		{
			$this->log->write('ev_write('.$id.'): Failed to write to client! '.$e->getMessage(),3);
		}
	}

	public function ev_read($buffer, $id)
	{
		if(!isset($buffer->input) || !$buffer->input->length)
		{
			return;
		}

		while($buffer->input->length > 0)
		{
			$this->connections[$id]['clientData'] .= $buffer->input->read($this->maxRead);

			if(!$this->connections[$id]['connected'] && strpos($this->connections[$id]['clientData'], "\r\n\r\n"))
			{
				$tmp = explode("\r\n\r\n", $this->connections[$id]['clientData'], 2);
				$this->connections[$id]['clientData'] = $tmp[1];
				$this->parseRequest($tmp[0], $id);
			}
			else if($this->connections[$id]['connected'])
			{
				$this->parseFrame($id);
			}
		}
	}

	public function parseFrame($id)
	{
		if(!$this->connections[$id]['continuation']) // start of a frame
		{
			// make sure we have enough data in the buffer
			if(strlen($this->connections[$id]['clientData'])<2)
			{
				return; // wait for more data
			}

			$payloadLength = (ord($this->connections[$id]['clientData'][1])&127); // 7bits 128 is the mask bit
			$mask = !!(ord($this->connections[$id]['clientData'][1]) & 128);
			$maskKey = FALSE;

			if($payloadLength <= 125)
			{
				if($mask)
				{
					// make sure we have enough data in the buffer
					if(strlen($this->connections[$id]['clientData'])<6)
					{
						return;
					}

					$maskKey = substr($this->connections[$id]['clientData'], 2, 4);
					$offset = 6;
				}
				else
				{
					$offset = 4;
				}
			}
			else if($payloadLength == 126)
			{
				if($mask)
				{
					// make sure we have enough data in the buffer
					if(strlen($this->connections[$id]['clientData'])<8)
					{
						return;
					}

					$maskKey = substr($this->connections[$id]['clientData'], 4, 4);
					$offset = 8;
				}
				else
				{
					$offset = 4;
				}

				$payloadLength = ord($this->connections[$id]['clientData'][2]); // high order byte
				$payloadLength <<= 8; // shift the high order byte left (big/network endian)
				$payloadLength |= ord($this->connections[$id]['clientData'][3]); // OR in the low order byte
			}
			else if($payloadLength == 127)
			{
				if($mask)
				{
					// make sure we have enough data in the buffer
					if(strlen($this->connections[$id]['clientData'])<9)
					{
						return;
					}

					$maskKey = substr($this->connections[$id]['clientData'], 10, 4);
					$offset = 14;
				}
				else
				{
					$offset = 10;
				}

				// TODO: according to spec the most sig bit should be zero? ~(128&$payloadLength) ??
				$payloadLength = ord($this->connections[$id]['clientData'][2]); // set most sig byte
				$payloadLength <<= 8; // shift the high order byte left (big/network endian)
				$payloadLength |= ord($this->connections[$id]['clientData'][3]); // OR in the next low order byte
				$payloadLength <<= 8;
				$payloadLength |= ord($this->connections[$id]['clientData'][4]); // OR in the next low order byte
				$payloadLength <<= 8;
				$payloadLength |= ord($this->connections[$id]['clientData'][5]); // OR in the next low order byte
				$payloadLength <<= 8;
				$payloadLength |= ord($this->connections[$id]['clientData'][6]); // OR in the next low order byte
				$payloadLength <<= 8;
				$payloadLength |= ord($this->connections[$id]['clientData'][7]); // OR in the next low order byte
				$payloadLength <<= 8;
				$payloadLength |= ord($this->connections[$id]['clientData'][8]); // OR in the next low order byte
				$payloadLength <<= 8;
				$payloadLength |= ord($this->connections[$id]['clientData'][9]); // OR in the next low order byte
			}

			if(!$this->connections[$id]['continuation']) // first frame, take it's opcode as rest will be 0x0
			{
				$this->connections[$id]['frameType'] = (ord($this->connections[$id]['clientData'][0]) & 15);
			}

			$this->connections[$id]['maskKey'] = $maskKey;
			$this->connections[$id]['frame'] = [
				'fin' => !!(ord($this->connections[$id]['clientData'][0]) & 128),
				'mask' => $mask,
				'maskKey' => $maskKey,
				'payloadLength' => $payloadLength // 7bits 128 is the mask bit
			];

			// remove frame header from payload data
			$this->connections[$id]['clientData'] = substr($this->connections[$id]['clientData'], $offset);
		}

		if(strlen($this->connections[$id]['clientData']) < $this->connections[$id]['frame']['payloadLength'])
		{
			$this->connections[$id]['continuation'] = TRUE;
			return; // wait for rest of payload to complete this frame
		}
		else
		{
			$this->connections[$id]['continuation'] = FALSE;
		}

		$encodedMessage = substr($this->connections[$id]['clientData'], 0, $this->connections[$id]['frame']['payloadLength']);
		$this->connections[$id]['clientData'] = substr($this->connections[$id]['clientData'], $this->connections[$id]['frame']['payloadLength']);

		for($i=0; $i<$this->connections[$id]['frame']['payloadLength']; $i++)
		{
			$this->connections[$id]['payload'] .= ($encodedMessage[$i] ^ $this->connections[$id]['maskKey'][$i%4]);
		}

		// get next frame if FIN not set
		if(!$this->connections[$id]['frame']['fin'])
		{
echo "Split frame! This is not yet tested so if you are seeing errors after this; sorry but let me know!\n";
			if(strlen($this->connections[$id]['payload'])) // if we have data left attempt to parse the next frame
			{
				$this->parseFrame($id);
			}
			return; // or go back for more data
		}
		else // message complete
		{
			if($this->connections[$id]['frameType'] == 0x8)
			{
				$data = [];
				foreach($this->observers as $observer)
				{
					$data = $observer->notifyDisconnect($id);
					if($data)
					{
						foreach($data as $idx => $tmp)
						{
							$this->ev_write($tmp[0], $this->buildFrame($tmp[1]));
						}
					}
				}

				$this->ev_close($id);
				return;
			}

//TODO: handle interleaving of control frames i.e. PING/PONG in the middle of a fragmented message
			if($this->connections[$id]['frameType'] == 0x9) // respond to a PING
			{
echo "Sending PONG in response to a client PING\n";
				// TODO: Untested and the payload is demasked.. not sure if this is correct for reponding to a PING..?  This also assumes a message length <= 125.
				$this->ev_write($id, $this->buildFrame($this->connections[$id]['payload'], pack('CC', 0b10001010, $payloadLength)));
				return;
			}

			if($this->connections[$id]['frameType'] == 0xA) // unsolicited Pong frame .. do nothing.
			{
echo "Recieved PONG\n";
				return;
			}

			$this->observerNotifyData($id, $this->connections[$id]['payload']);
			$this->connections[$id]['payload'] = FALSE;
		}
	}

	public function buildFrame($message, $flags=FALSE)
	{
		$messageLength = strlen($message); // length in bytes

		/* This was an attempt to allow for fragmented messages
		if($messageLength > $this->maxSend)
		{
			$overhead = 10;
			if($message <= 125) $overhead = 2;
			else if($message < 65535) $overhead = 4;
			else $overHead = $overhead = 10;

			while($frag = substr($message, 0, $this->maxSend-$overhead))
			{
				$frame = ...
				$message = substr($message, $this->maxSend-$overhead);
			}
		}
		else*/

		if($messageLength <= 125)
		{
			$frame = pack('CC', 0b10000001, $messageLength).$message;
		}
		else if($messageLength <= 65535)
		{
			$frame = pack('CCn', 0b10000001, 0b01111110, $messageLength).$message; // be sure to set 126 in the second byte
		}
		else if($messageLength > 65535)
		{
			$frame = pack('CCJ', 0b10000001, 0b01111111, $messageLength).$message; // be sure to set 127 in the second byte
		}

		return $frame;		
	}

	public function parseRequest($request, $id)
	{
		$t = new \http\Message($request);
		$headers = $t->getHeaders();

		if(isset($headers['Sec-Websocket-Key']))
		{
			$key = base64_encode(sha1($headers['Sec-Websocket-Key'].'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', TRUE));
			$this->ev_write($id,"HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: ".$key."\r\n\r\n");
			$this->connections[$id]['connected'] = TRUE;
			$url = parse_url($t->getRequestUrl());
			$this->connections[$id]['route'] = $url['path'];
			$this->connections[$id]['query'] = [];
			parse_str($url['query'], $this->connections[$id]['query']);
			$this->observerNotifyConnect($id);
		}
	}
}
