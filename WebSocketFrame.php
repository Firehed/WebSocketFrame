<?php

// Reserved for protocol violations, status code 1002
class FailWebSocketConnectionException extends Exception {}

class WebSocketFrame {

	public $payload;
	private $mask;

	public function __construct($payload, $mask = null) {
		$this->payload = $payload;
		$this->mask    = $mask;
	} // function __construct

	public function __toString() {
		$head = chr(0x81); // fin=1, rsv1-3=0, opcode=1
		$len = strlen($this->payload);
		if ($len <= 125) {
			$lenFrame = chr($len);
		}
		elseif ($len <= 0xFFFF) {
			$lenFrame = chr(126) . pack('n', $len);
		}
		else {
			$lenFrame = chr(127) . pack('NN', ($len & 0xFFFFFFFF00000000), ($len & 0x00000000FFFFFFFF));
		}
		if ($this->mask) {
			$lenFrame[0] = chr(ord($lenFrame[0]) | 0x80); // bitmask in "masked" flag
			return $head . $lenFrame . $this->mask . self::transformData($this->payload, $this->mask);
		}
		else {
			return $head . $lenFrame . $this->payload;
		}
	} // function __toString

	public static function decode($frame) {
		$snip = 0; // this will be trimmed off the beginning of the frame as non-payload

		$header = unpack('ninfo', substr($frame, 0, 2));
		$snip += 2;
		$info = $header['info'];


		$fin    = (bool) ($info & 0x8000);
		$rsv1   = (bool) ($info & 0x4000);
		$rsv2   = (bool) ($info & 0x2000);
		$rsv3   = (bool) ($info & 0x1000);
		$opcode =        ($info & 0x0F00) >> 8;
		$masked = (bool) ($info & 0x0080);
		$len    =         $info & 0x007F;

		if ($rsv1) {
			throw new FailWebSocketConnectionException('RSV1 set without known meaning');
		}

		if ($rsv2) {
			throw new FailWebSocketConnectionException('RSV2 set without known meaning');
		}

		if ($rsv3) {
			throw new FailWebSocketConnectionException('RSV3 set without known meaning');
		}

		switch ($opcode) {
			case 0:
			// continuation frame
			break;

			case 1:
			// text frame
			break;

			case 2:
			// binary frame
			break;
			
			case 3:
			case 4:
			case 5:
			case 6:
			case 7:
				// reseved for non-control frames
				throw new FailWebSocketConnectionException('Use of reserved non-control frame opcode');
			break;

			case 8:
			// Disconnect
			break;

			case 9:
			// ping
			break;

			case 10:
			// pong
			break;

			case 11:
			case 12:
			case 13:
			case 14:
			case 15:
				// reserved for control frames
				throw new FailWebSocketConnectionException('Use of reserved control frame opcode');
			break;
		}

		// If basic length field was one of the magic numbers, read further into the header to get the actual length
		if ($len == 126) {
			$len = substr($frame, $snip, 2);
			$snip += 2;
			$unpacked = unpack('nlen', $len);
			$len = $unpacked['len'];
		}
		elseif ($len == 127) {
			$len = substr($frame, $snip, 8);
			$snip += 8;
			$unpacked = unpack('Nh/Nl', $len); // php's pack doesn't have a specific unsigned 64-bit int format, hack it
			$len = ($unpacked['h'] << 32) | $unpacked['l'];
		}

		if ($masked) {
			$maskingKey = substr($frame, $snip, 4);
			$snip += 4;
			$payload = self::transformData(substr($frame, $snip), $maskingKey);
		}
		else {
			// The spec is unclear if this condition should actually fail the connection, since it says clients MUST mask payload
			$payload = substr($frame, $snip);
		}
		return new WebSocketFrame($payload);
	}

	private static function transformData($data, $maskingKey) {
		for ($i=0, $len = strlen($data); $i < $len; $i++) { 
			$data[$i] = $data[$i] ^ $maskingKey[$i%4];
		}
		return $data;
	}

}