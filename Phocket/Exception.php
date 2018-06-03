<?php

namespace Phocket;

class Exception extends \Exception
{
	public static $codes = [
		999 => "Woop-woop! That's the sound of da police!"
	];

	public function __construct($code, $extra='', $db=FALSE)
	{
		if(isset($this->codes[$code]))
		{
			echo self::$codes[$code].': '.$extra."\n";
		}
		echo $this->getTraceAsString()."\n";
	}
}
