<?php

class WebSocketConnectionFactory{
	public static function fromSocketData(WebSocket $socket, $data){
		$headers = WebSocketFunctions::parseHeaders($data);

		if(isset($headers['Sec-Websocket-Key1'])) {
			return new WebSocketConnectionHixie($socket, $headers, $data);
		} else if(strpos($data,'<policy-file-request/>') === 0) {
			return new WebSocketConnectionFlash($data);
		} else{
			return new WebSocketConnectionHybi($socket, $headers);
		}
	}
}

interface IWebSocketConnection{
	public function sendHandshakeResponse();

	public function readFrame($data);
	public function sendFrame(IWebSocketFrame $frame);
	public function sendMessage(IWebSocketMessage $msg);
	public function sendString($msg);

	public function getHeaders();
	public function getUriRequested();
	public function getCookies();

	public function disconnect();
}

abstract class WebSocketConnection implements IWebSocketConnection{
	protected $_headers = array();
	protected $_socket = null;
	protected $_cookies = array();

	public function __construct(WebSocket $socket, array $headers){
		$this->setHeaders($headers);
		$this->_socket = $socket;

		$this->sendHandshakeResponse();
	}

	public function getId(){
		return (int) $this->_socket->getResource();
	}

	public function sendFrame(IWebSocketFrame $frame){
		if($this->_socket->write($frame->encode()) === false)
			return FALSE;
	}

	public function sendMessage(IWebSocketMessage $msg){
		foreach($msg->getFrames() as $frame){
			if($this->sendFrame($frame) === false)
				return FALSE;
		}

		return TRUE;
	}

	public function getHeaders(){
		return $this->_headers;
	}

	public function setHeaders($headers){
		$this->headers = $headers;

		if(array_key_exists('Cookie', $this->headers) && is_array($this->headers['Cookie'])) {
			$this->cookie = array();
		} else {
			if(array_key_exists("Cookie", $this->headers)){
			 	$this->_cookies = WebSocketFunctions::cookie_parse($this->headers['Cookie']);
			}else $this->_cookies = array();
		}
	}

	public function getCookies(){
		return $this->_cookies;
	}

	public function getUriRequested(){
		return $this->headers['GET'];
	}

	public function getAdminKey(){
		return isset($this->headers['Admin-Key']) ? $this->headers['Admin-Key'] : null;
	}
}

class WebSocketConnectionFlash{
	public function __construct($data){
		$this->_socket->onFlashXMLRequest($this);
	}

	public function sendString($msg){
		$this->_socket->write($msg);
	}

	public function disconnect(){
		$this->_socket->close();
	}
}

class WebSocketConnectionHybi extends WebSocketConnection{
	private $_openMessage = null;

	public function sendHandshakeResponse(){
		// Check for newer handshake
		$challenge = isset($this->_headers['Sec-Websocket-Key']) ? $this->_headers['Sec-Websocket-Key'] : null;

		// Build response
		$response  = "HTTP/1.1 101 WebSocket Protocol Handshake\r\n" .
		                "Upgrade: WebSocket\r\n" .
		                "Connection: Upgrade\r\n";

		// Build HYBI response
		$response .= "Sec-WebSocket-Accept: ".WebSocketFunctions::calcHybiResponse($challenge)."\r\n\r\n";



		$this->_socket->write($response);

		WebSocketFunctions::say("HYBI Response SENT!");
	}

	public function readFrame($data){
		$frame = WebSocketFrame::decode($data);

		if(WebSocketOpcode::isControlFrame($frame->getType()))
			$this->processControlFrame($frame);
		else $this->processMessageFrame($frame);
	}

	/**
	* Process a Message Frame
	*
	* Appends or creates a new message and attaches it to the user sending it.
	*
	* When the last frame of a message is received, the message is sent for processing to the
	* abstract WebSocket::onMessage() method.
	*
	* @param IWebSocketUser $user
	* @param WebSocketFrame $frame
	*/
	protected function processMessageFrame(WebSocketFrame $frame){
		if($this->_openMessage && $this->_openMessage->isFinalised() == false){
			$this->_openMessage->takeFrame($frame);
		} else {
			$this->_openMessage = WebSocketMessage::fromFrame($frame);
		}

		if($this->_openMessage && $this->_openMessage->isFinalised()){
			$this->_socket->onMessage($this->_openMessage);
			$this->_openMessage = null;
		}
	}

	/**
	* Handle incoming control frames
	*
	* Sends Pong on Ping and closes the connection after a Close request.
	*
	* @param IWebSocketUser $user
	* @param WebSocketFrame $frame
	*/
	protected function processControlFrame(WebSocketFrame $frame){
		switch($frame->getType()){
			case WebSocketOpcode::CloseFrame:
				$frame = WebSocketFrame::create(WebSocketOpcode::CloseFrame);
				$this->sendFrame($frame);

				$this->_socket->disconnect();
				break;
			case WebSocketOpcode::PingFrame:
				$frame = WebSocketFrame::create(WebSocketOpcode::PongFrame);
				$this->sendFrame($frame);
				break;
		}
	}


	public function sendString($msg){
		$m = WebSocketMessage::create($msg);

		return $this->sendMessage($m);
	}

	public function disconnect(){
		$f = WebSocketFrame::create(WebSocketOpcode::CloseFrame);
		$this->sendFrame($f);

		$this->_socket->close();
	}
}

class WebSocketConnectionHixie extends WebSocketConnection{
	private $_clientHandshake;

	public function __construct(WebSocket $socket, array $headers, $clientHandshake){
		parent::__construct($socket, $headers);

		$this->_clientHandshake = $clientHandshake;
	}

	public function sendHandshakeResponse(){
		// Last 8 bytes of the client's handshake are used for key calculation later
		$l8b = substr($this->_clientHandshake, -8);

		// Check for 2-key based handshake (Hixie protocol draft)
		$key1 = isset($this->_headers['Sec-Websocket-Key1']) ? $this->_headers['Sec-Websocket-Key1'] : null;
		$key2 = isset($this->_headers['Sec-Websocket-Key2']) ? $this->_headers['Sec-Websocket-Key2'] : null;

		// Origin checking (TODO)
		$origin = isset($this->_headers['Origin']) ? $this->_headers['Origin'] : null;
		$host = $this->_headers['Host'];
		$location = $this->_headers['GET'];

		// Build response
		$response  = "HTTP/1.1 101 WebSocket Protocol Handshake\r\n" .
		                "Upgrade: WebSocket\r\n" .
		                "Connection: Upgrade\r\n";

		// Build HIXIE response
		$response .= "Sec-WebSocket-Origin: $origin\r\n"."Sec-WebSocket-Location: ws://{$host}$location\r\n";
		$response .= "\r\n" . WebSocketFunctions::calcHixieResponse($key1,$key2,$l8b);


		$this->_socket->write($response);
		echo "HIXIE Response SENT!";
	}

	public function readFrame($data){
		$m = WebSocketMessage76::create($data);

		$this->_socket->onMessage($m);
	}


	public function sendString($msg){
		$m = WebSocketMessage76::create($data);

		return $this->sendMessage($m);
	}

	public function disconnect(){
		$this->_socket->close();
	}
}