<?php
declare(strict_types=1);
namespace ParagonIE\Paseto;

/**
 * Interface KeyInterface
 * @package ParagonIE\Paseto
 */
interface KeyInterface
{
    /**
     * The intended version for this protocol. Currently only meaningful
     * in asymmetric cryptography.
     *
     * @return ProtocolInterface
     */
    public function getProtocol(): ProtocolInterface;

    /**
     * Returns the raw key as a string.
     *
     * @return string
     */
    public function raw(): string;

    /**
     * This hides the internal state from var_dump(), etc. if it returns
     * an empty array.
     *
     * @return array
     */
    public function __debugInfo();
}
