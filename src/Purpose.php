<?php
declare(strict_types=1);
namespace ParagonIE\Paseto;

use ParagonIE\Paseto\Keys\{
    AsymmetricSecretKey,
    AsymmetricPublicKey,
    SymmetricKey
};
use ParagonIE\Paseto\Exception\InvalidPurposeException;

final class Purpose
{
    const WHITELIST = [
        'local',
        'public',
    ];

    const EXPECTED_SENDING_KEYS = [
        'local'  => SymmetricKey::class,
        'public' => AsymmetricSecretKey::class,
    ];

    const EXPECTED_RECEIVING_KEYS = [
        'local'  => SymmetricKey::class,
        'public' => AsymmetricPublicKey::class,
    ];

    /** @var array<string, string> */
    private static $sendingKeyToPurpose;

    /** @var array<string, string> */
    private static $receivingKeyToPurpose;

    /**
     * @var string
     */
    private $fuzz;

    /**
     * @var string
     */
    private $purpose;

    /**
     * @throws InvalidPurposeException
     */
    public function __construct(string $rawString)
    {
        if (!self::isValid($rawString)) {
            throw new InvalidPurposeException('Unknown purpose: ' . $rawString);
        }

        $this->purpose = $rawString;
        // prevent use of the == operator
        // i.e. new Purpose('a') == new Purpose('a') will now be false
        $this->fuzz = \random_bytes(16);
    }

    public static function local(): self
    {
        return new self('local');
    }

    public static function public(): self
    {
        return new self('public');
    }

    public function equals(self $purpose): bool
    {
        return \hash_equals($purpose->purpose, $this->purpose);
    }

    public function isSendingKeyValid(SendingKey $key): bool
    {
        $expectedKeyType = $this->expectedSendingKeyType();
        return $key instanceof $expectedKeyType;
    }

    public function isReceivingKeyValid(ReceivingKey $key): bool
    {
        $expectedKeyType = $this->expectedReceivingKeyType();
        return $key instanceof $expectedKeyType;
    }

    public function expectedSendingKeyType(): string
    {
        /** @var string */
        $keyType = self::EXPECTED_SENDING_KEYS[$this->rawString()];

        return $keyType;
    }

    public function expectedReceivingKeyType(): string
    {
        /** @var string */
        $keyType = self::EXPECTED_RECEIVING_KEYS[$this->rawString()];

        return $keyType;
    }

    public function rawString(): string
    {
        return $this->purpose;
    }

    public function __clone()
    {
        // reconstruct to change the fuzz value
        $this->__construct($this->rawString());
    }

    public static function isValid(string $rawString): bool
    {
        return \in_array($rawString, self::WHITELIST, true);
    }

    public static function fromSendingKey(SendingKey $key): self
    {
        if (empty(self::$sendingKeyToPurpose)) {
            /** @var array<string, string> */
            $expectedSendingKeys = self::EXPECTED_SENDING_KEYS;
            self::$sendingKeyToPurpose = \array_flip($expectedSendingKeys);
        }

        return new self(self::$sendingKeyToPurpose[\get_class($key)]);
    }

    public static function fromReceivingKey(ReceivingKey $key): self
    {
        if (empty(self::$receivingKeyToPurpose)) {
            /** @var array<string, string> */
            $expectedReceivingKeys = self::EXPECTED_RECEIVING_KEYS;
            self::$receivingKeyToPurpose = \array_flip($expectedReceivingKeys);
        }

        return new self(self::$receivingKeyToPurpose[\get_class($key)]);
    }
}
