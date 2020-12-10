<?php

declare(strict_types=1);

namespace edomato\Net\Smpp\Client\React;

use edomato\Net\Smpp\Proto\Address;
use edomato\Net\Smpp\Proto\Contract\Address as AddressContract;

class Session
{
    /** @var int */
    const TRANSMITTER = 1;
    const RECEIVER = 2;
    const TRANSCEIVER = 3;

    /** @var int */
    private $mode;

    /** @var string */
    private $systemId;

    /** @var string */
    private $password;

    /** @var string */
    private $systemType;

    /** @var AddressContract */
    private $address;

    /** @var int */
    private $interfaceVersion;

    /**
     * Construct
     * 
     * @param int           $mode
     * @param string        $systemId
     * @param string|null   $password
     * @param string|null   $systemType
     * @param AddressContract|null  $address
     * @param int|null      $interfaceVersion
     */
    public function __construct(
        int $mode,
        string $systemId,
        string $password = null,
        string $systemType = null,
        Address $address = null,
        int $interfaceVersion = null)
    {
        $this->mode = $mode;
        $this->systemId = $systemId;
        $this->password = $password ?? '';

        $this->systemType = $systemType ?? '';

        $this->address = $address ?? new Address(Address::TON_UNKNOWN, Address::NPI_UNKNOWN, '');

        $this->interfaceVersion = $interfaceVersion ?? 0x34;
    }

    /**
     * Returns the mode
     *
     * @return int
     */
    public function getMode(): int
    {
        return $this->mode;
    }

    /**
     * Returns the system ID
     *
     * @return string
     */
    public function getSystemId(): string
    {
        return $this->systemId;
    }

    /**
     * Returns the password
     *
     * @return string
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * Returns the system type
     *
     * @return string
     */
    public function getSystemType(): string
    {
        return $this->systemType;
    }

    /**
     * Returns the address
     *
     * @return Address
     */
    public function getAddress(): Address
    {
        return $this->address;
    }

    /**
     * Returns the interface version
     *
     * @return int
     */
    public function getInterfaceVersion(): int
    {
        return $this->interfaceVersion;
    }
}
