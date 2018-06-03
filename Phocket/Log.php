<?php

namespace Phocket;

class Log
{
	private $fd;
	private $level = 1;

	public function __construct($file, $level)
	{
		$this->fd = fopen($file, 'w+');
	}

	public function write($string, $level=1)
	{
		if($level>=$this->level)
		{
			fwrite($this->fd, date('d/m/Y H:i:s').' '.$string."\n");
		}
	}
}
