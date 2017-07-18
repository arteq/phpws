<?php

namespace Devristo\Phpws\Client;

use React\SocketClient\Connector as BaseConnector;
use React\EventLoop\LoopInterface;
use React\Dns\Resolver\Resolver;
use React\Promise\When;

class Connector extends BaseConnector
{
    protected $contextOptions = array();

    public function __construct(LoopInterface $loop, Resolver $resolver, array $contextOptions = null)
    {
        parent::__construct($loop, $resolver);

        $contextOptions = null === $contextOptions ? array() : $contextOptions;
        $this->contextOptions = $contextOptions;
    }

    public function createSocketForAddress($address, $port, $hostName = null)
    {
        $url = $this->getSocketUrl($address, $port);

        $contextOpts = $this->contextOptions;
        // Fix for SSL in PHP >= 5.6, where peer name must be validated.
        if ($hostName !== null) {
            $contextOpts['ssl']['SNI_enabled'] = true;
            $contextOpts['ssl']['SNI_server_name'] = $hostName;
            $contextOpts['ssl']['peer_name'] = $hostName;
        }

// arteq start: fix proxy
if (!empty(getenv(PROXY)))
{
    // use proxy
    $proxy = 'tcp://'.getenv('PROXY');
    $ctx = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
        ),
    );

    $stream_context = stream_context_create($ctx);

    // connection to your proxy server
    $apns = stream_socket_client($proxy, $error, $errorString, 2, STREAM_CLIENT_CONNECT, $stream_context);

    // destination host and port must be accepted by proxy
    $connect_via_proxy = "CONNECT ".$address.":".$port." HTTP/1.1\r\n".
        "Host: ".$address.":".$port."\n".
        "User-Agent: SimplePush\n".
        "Proxy-Connection: Keep-Alive\n\n";

    fwrite($apns,$connect_via_proxy,strlen($connect_via_proxy));

    // read whole response and check successful "HTTP/1.0 200 Connection established"
    if ($response = fread($apns,1024)) 
    {
        $parts = explode(' ',$response);
        if($parts[1] !== '200') 
        {
            die('Connection error: '.trim($response));
        } 
        else 
        {
            echo "Connected via proxy: ".$response."\n";
        }
    } 
    else 
    {
        die('Proxy timeout or other error');
    }

    $socket = $apns;
}
else
{
    // dont use proxy
    $flags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT;
    $context = stream_context_create($contextOpts);
    $socket = stream_socket_client($url, $errno, $errstr, 0, $flags, $context);
    
}
// arteq end: fix proxy

        if (!$socket) {
            return When::reject(new \RuntimeException(
                sprintf("connection to %s:%d failed: %s", $address, $port, $errstr),
                $errno
            ));
        }

        stream_set_blocking($socket, 0);

        // wait for connection

        return $this
            ->waitForStreamOnce($socket)
            ->then(array($this, 'checkConnectedSocket'))
            ->then(array($this, 'handleConnectedSocket'));
    }
}
