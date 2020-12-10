<?php

declare(strict_types=1);

namespace edomato\Net\Smpp\Client\React;

use edomato\Net\Smpp\Pdu\BindReceiver;
use edomato\Net\Smpp\Pdu\BindTransceiver;
use edomato\Net\Smpp\Pdu\BindTransmitter;
use edomato\Net\Smpp\Pdu\Contract\DeliverSm;
use edomato\Net\Smpp\Pdu\Contract\EnquireLink;
use edomato\Net\Smpp\Pdu\Contract\Pdu;
use edomato\Net\Smpp\Pdu\Contract\Unbind;
use edomato\Net\Smpp\Pdu\DeliverSmResp;
use edomato\Net\Smpp\Pdu\EnquireLinkResp;
use edomato\Net\Smpp\Pdu\Factory;
use edomato\Net\Smpp\Pdu\SubmitSm;
use edomato\Net\Smpp\Pdu\UnbindResp;
use edomato\Net\Smpp\Proto\Address;
use edomato\Net\Smpp\Utils\DataWrapper;
use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Promise\CancellablePromiseInterface;
use React\Promise\Deferred;
use React\Promise\ExtendedPromiseInterface;
use React\Socket\ConnectorInterface;
use React\Stream\DuplexStreamInterface;

class Client extends EventEmitter
{
    /** @var ConnectorInterface */
    private $connector;

    /** @var LoopInterface */
    private $loop;

    /** @var DuplexStreamInterface */
    private $stream;

    /** @var int */
    private $sequenceNumber;

    /** @var string */
    private $buffer;

    /** @var Factory */
    private $packetFactory;

    /** @var Packet[] */
    private $sentPackets = [];

    /**
     * Construct
     * 
     * @param ConnectorInterface $connector
     * @param LoopInterface $loop
     */
    public function __construct(ConnectorInterface $connector, LoopInterface $loop)
    {
        $this->connector = $connector;
        $this->loop = $loop;
        $this->sequenceNumber = 1;
        $this->packetFactory = new Factory();
    }

    /**
     * Connects to SMSC
     * 
     * @param Session   $session
     * @param string    $host
     * @param int       $port
     * @param int       $timeout
     * 
     * @return ExtendedPromiseInterface
     */
    public function connect(Session $session, string $host, int $port = 2775, $timeout = 5): ExtendedPromiseInterface
    {
        $deferred = new Deferred();

        $future = null;
        $timer = $this->loop->addTimer(
            $timeout,
            static function () use ($deferred, $timeout, $future) {
                $exception = new \RuntimeException(sprintf('Connection timed out after %d seconds.', $timeout));
                $deferred->reject($exception);
                if ($future instanceof CancellablePromiseInterface) {
                    $future->cancel();
                }
                $future = null;
            }
        );

        $future = $this->connector->connect($host . ':' . $port)
            ->always(function () use ($timer) {
                $this->loop->cancelTimer($timer);
            })
            ->then(function (DuplexStreamInterface $stream) use ($session, $deferred) {
                $this->stream = $stream;
                
                $stream->on('data', function ($data) {
                    $this->handleReceive($data);
                });

                $stream->on('end', function() {
                    $this->emit('end');
                });
                
                $stream->on('close', function () {
                    $this->emit('close');
                });
                
                $stream->on('error', function (\Exception $e) {
                    $this->emit('error', [$e]);
                });
                
                $this->registerClient($session)
                    ->then(function ($pdu) use ($deferred) {
                        $deferred->resolve($pdu);
                    });
            })
            ->otherwise(static function (\Exception $e) use ($deferred) {
                $deferred->reject($e);
            });

        return $deferred->promise();
    }

    /**
     * Registers a new client with the broker.
     *
     * @param Session   $session
     *
     * @return ExtendedPromiseInterface
     */
    private function registerClient(Session $session): ExtendedPromiseInterface
    {
        $deferred = new Deferred();

        switch ($session->getMode()) {
            case Session::TRANSMITTER:
                $pdu = new BindTransmitter();
                break;
            
            case Session::RECEIVER:
                $pdu = new BindReceiver();
                break;
            
            case Session::TRANSCEIVER:
                $pdu = new BindTransceiver();
                break;
        }
        
        $this->sequenceNumber = $pdu->getSequenceNumber();
        
        $pdu->setSystemId($session->getSystemId());
        $pdu->setPassword($session->getPassword());
        $pdu->setSystemType($session->getSystemType());
        $pdu->setInterfaceVersion($session->getInterfaceVersion());
        $pdu->setAddress($session->getAddress());

        $packet = new Packet($pdu, $deferred);
        $this->sentPackets[$pdu->getSequenceNumber()] = $packet;

        $this->stream->write($pdu);

        return $deferred->promise();
    }

    /**
     * Sends a SMS
     * 
     * @param Address $destination
     * @param string $message
     * 
     * @return ExtendedPromiseInterface
     */
    public function sendSMS(Address $destination, string $message): ExtendedPromiseInterface
    {
        $deferred = new Deferred();

        $this->sequenceNumber++;
        $pdu = new SubmitSm();
        $pdu->setSequenceNumber($this->sequenceNumber);
        $pdu->setDestinationAddress($destination);
        $pdu->setShortMessage($message);
        
        $packet = new Packet($pdu, $deferred);
        $this->sentPackets[$pdu->getSequenceNumber()] = $packet;
        
        $this->stream->write($pdu);

        return $deferred->promise();
    }

    /**
     * Handles incoming data.
     *
     * @param string $data
     *
     * @throws MalformedPdu|UnknownPdu
     */
    public function handleReceive(string $data): void
    {
        $this->buffer .= $data;

        $dataWrapper = new DataWrapper($this->buffer);

        while ($dataWrapper->bytesLeft() >= 16) {
            $length = $dataWrapper->readInt32();
            if (strlen($this->buffer) >= $length) {
                $dataWrapper->readBytes($length - 4);
                try {
                    $pdu = $this->packetFactory->createFromBuffer(substr($this->buffer, 0, $length));
                    $this->buffer = substr($this->buffer, $length);
                    $this->handlePdu($pdu);
                } catch (\Throwable $th) {
                    throw $th;
                }
            }
        }
    }

    /**
     * Handles an incoming pdu.
     *
     * @param Pdu $pdu
     *
     * @return void
     */
    public function handlePdu(Pdu $pdu): void
    {
        if ($pdu->getCommandId() & 0x80000000) { // Packet is a response or Generic NACK
            $packet = $this->sentPackets[$pdu->getSequenceNumber()];
            unset($this->sentPackets[$pdu->getSequenceNumber()]);
            $packet->getDeferred()->resolve($pdu);
        } elseif ($pdu instanceof EnquireLink) {
            $response = new EnquireLinkResp();
            $response->setSequenceNumber($pdu->getSequenceNumber());

            $this->emit('enquireLink', [$pdu]);
        } elseif ($pdu instanceof DeliverSm) {
            $response = new DeliverSmResp();
            $response->setMessageId('');
            $response->setSequenceNumber($pdu->getSequenceNumber());

            $this->emit('deliverSM', [$pdu]);
        } elseif ($pdu instanceof Unbind) {
            $response = new UnbindResp();
            $response->setSequenceNumber($pdu->getSequenceNumber());

            $this->emit('unbind', [$pdu]);
        }
        
        if (isset($response)) {
            $this->stream->write($response);
        }
    }
}
