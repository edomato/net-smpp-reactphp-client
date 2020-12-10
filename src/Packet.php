<?php

declare(strict_types=1);

namespace edomato\Net\Smpp\Client\React;

use edomato\Net\Smpp\Pdu\Contract\Pdu;
use React\Promise\Deferred;

class Packet
{
    /** @var Pdu */
    private $pdu;

    /** @var Deferred */
    private $deferred;

    public function __construct(Pdu $pdu, Deferred $deferred)
    {
        $this->pdu = $pdu;
        $this->deferred = $deferred;
    }

    /**
     * Returns the associated pdu.
     * 
     * @return Pdu
     */
    public function getPdu(): Pdu
    {
        return $this->pdu;
    }

    /**
     * Returns the associated deferred.
     *
     * @return Deferred
     */
    public function getDeferred(): Deferred
    {
        return $this->deferred;
    }
}
