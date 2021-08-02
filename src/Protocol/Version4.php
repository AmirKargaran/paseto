<?php
declare(strict_types=1);
namespace ParagonIE\Paseto\Protocol;

use ParagonIE\ConstantTime\{
    Base64UrlSafe,
    Binary
};
use ParagonIE\Paseto\Keys\{
    AsymmetricPublicKey,
    AsymmetricSecretKey,
    SymmetricKey
};
use ParagonIE\Paseto\Keys\Version4\{
    AsymmetricSecretKey as V4AsymmetricSecretKey,
    SymmetricKey as V4SymmetricKey
};
use ParagonIE\Paseto\Exception\{
    ExceptionCode,
    InvalidVersionException,
    PasetoException,
    SecurityException
};
use ParagonIE\Paseto\{
    ProtocolInterface,
    Util
};
use ParagonIE\Paseto\Parsing\{
    Header,
    PasetoMessage
};
use Exception;
use SodiumException;
use Throwable;
use TypeError;
use function hash_equals,
    is_null,
    is_string,
    sodium_crypto_generichash,
    sodium_crypto_stream_xchacha20_xor,
    sodium_crypto_sign_detached,
    sodium_crypto_sign_verify_detached;

/**
 * Class Version1
 * @package ParagonIE\Paseto\Protocol
 */
class Version4 implements ProtocolInterface
{
    /** @const string HEADER */
    const HEADER = 'v4';
    const SYMMETRIC_KEY_BYTES = 32;
    const NONCE_SIZE = 32;
    const MAC_SIZE = 32;

    /**
     * Must be constructable with no arguments so an instance may be passed
     * around in a type safe way.
     */
    public function __construct()
    {
    }

    /**
     * @return int
     */
    public static function getSymmetricKeyByteLength(): int
    {
        return (int) static::SYMMETRIC_KEY_BYTES;
    }

    /**
     * Generate an asymmetric secret key for use with v4.public tokens.
     *
     * @return AsymmetricSecretKey
     *
     * @throws Exception
     * @throws TypeError
     */
    public static function generateAsymmetricSecretKey(): AsymmetricSecretKey
    {
        return V4AsymmetricSecretKey::generate(new static);
    }

    /**
     * Generate a symmetric key for use with v4.local tokens.
     *
     * @return SymmetricKey
     *
     * @throws Exception
     * @throws TypeError
     */
    public static function generateSymmetricKey(): SymmetricKey
    {
        return V4SymmetricKey::generate(new static);
    }

    /**
     * A unique header string with which the protocol can be identified.
     *
     * @return string
     */
    public static function header(): string
    {
        return (string) static::HEADER;
    }

    /**
     * Does this protocol support implicit assertions?
     * Yes.
     *
     * @return bool
     */
    public static function supportsImplicitAssertions(): bool
    {
        return true;
    }

    /**
     * Encrypt a message using a shared key.
     *
     * @param string $data
     * @param SymmetricKey $key
     * @param string $footer
     * @param string $implicit
     * @return string
     *
     * @throws PasetoException
     */
    public static function encrypt(
        string $data,
        SymmetricKey $key,
        string $footer = '',
        string $implicit = ''
    ): string {
        return self::__encrypt($data, $key, $footer, $implicit);
    }

    /**
     * Encrypt a message using a shared key.
     *
     * @param string $data
     * @param SymmetricKey $key
     * @param string $footer
     * @param string $implicit
     * @param string $nonceForUnitTesting
     * @return string
     *
     * @throws PasetoException
     * @throws TypeError
     */
    protected static function __encrypt(
        string $data,
        SymmetricKey $key,
        string $footer = '',
        string $implicit = '',
        string $nonceForUnitTesting = ''
    ): string {
        if (!($key->getProtocol() instanceof Version4)) {
            throw new InvalidVersionException(
                'The given key is not intended for this version of PASETO.',
                ExceptionCode::WRONG_KEY_FOR_VERSION
            );
        }
        return self::aeadEncrypt(
            $data,
            self::HEADER . '.local.', // PASETO Version 4 - Encrypt - Step 1
            $key,
            $footer,
            $implicit,
            $nonceForUnitTesting
        );
    }

    /**
     * Decrypt a message using a shared key.
     *
     * @param string $data
     * @param SymmetricKey $key
     * @param string|null $footer
     * @param string $implicit
     * @return string
     *
     * @throws PasetoException
     * @throws SodiumException
     * @throws TypeError
     */
    public static function decrypt(
        string $data,
        SymmetricKey $key,
        string $footer = null,
        string $implicit = ''
    ): string {
        if (!($key->getProtocol() instanceof Version4)) {
            throw new InvalidVersionException(
                'The given key is not intended for this version of PASETO.',
                ExceptionCode::WRONG_KEY_FOR_VERSION
            );
        }
        // PASETO Version 4 - Decrypt - Step 1:
        if (is_null($footer)) {
            $footer = Util::extractFooter($data);
            $data = Util::removeFooter($data);
        } else {
            $data = Util::validateAndRemoveFooter($data, $footer);
        }
        return self::aeadDecrypt(
            $data,
            self::HEADER . '.local.', // PASETO Version 4 - Decrypt - Step 2
            $key,
            $footer,
            $implicit
        );
    }

    /**
     * Sign a message. Public-key digital signatures.
     *
     * @param string $data
     * @param AsymmetricSecretKey $key
     * @param string $footer
     * @param string $implicit
     * @return string
     *
     * @throws PasetoException
     * @throws TypeError
     * @throws InvalidVersionException
     * @throws SecurityException
     * @throws SodiumException
     */
    public static function sign(
        string $data,
        AsymmetricSecretKey $key,
        string $footer = '',
        string $implicit = ''
    ): string {
        if (!($key->getProtocol() instanceof Version4)) {
            throw new InvalidVersionException(
                'The given key is not intended for this version of PASETO.',
                ExceptionCode::WRONG_KEY_FOR_VERSION
            );
        }
        // PASETO Version 4 - Sign - Step 1:
        $header = self::HEADER . '.public.';

        // PASETO Version 3 - Sign - Step 2 & 3:
        $signature = sodium_crypto_sign_detached(
            Util::preAuthEncode($header, $data, $footer, $implicit),
            $key->raw()
        );

        // PASETO Version 4 - Sign - Step 4:
        return (new PasetoMessage(
            Header::fromString($header),
            $data . $signature,
            $footer
        ))->toString();
    }

    /**
     * Verify a signed message. Public-key digital signatures.
     *
     * @param string $signMsg
     * @param AsymmetricPublicKey $key
     * @param string|null $footer
     * @param string $implicit
     * @return string
     *
     * @throws PasetoException
     * @throws SodiumException
     * @throws TypeError
     */
    public static function verify(
        string $signMsg,
        AsymmetricPublicKey $key,
        string $footer = null,
        string $implicit = ''
    ): string {
        if (!($key->getProtocol() instanceof Version4)) {
            throw new InvalidVersionException(
                'The given key is not intended for this version of PASETO.',
                ExceptionCode::WRONG_KEY_FOR_VERSION
            );
        }
        if (is_null($footer)) {
            $footer = Util::extractFooter($signMsg);
        } else {
            $signMsg = Util::validateAndRemoveFooter($signMsg, $footer);
        }
        $signMsg = Util::removeFooter($signMsg);
        $expectHeader = self::HEADER . '.public.';
        $givenHeader = Binary::safeSubstr($signMsg, 0, 10);
        if (!hash_equals($expectHeader, $givenHeader)) {
            throw new PasetoException(
                'Invalid message header.',
                ExceptionCode::INVALID_HEADER
            );
        }
        $decoded = Base64UrlSafe::decode(Binary::safeSubstr($signMsg, 10));
        $len = Binary::safeStrlen($decoded);

        // Separate the decoded bundle into the message and signature.
        $message = Binary::safeSubstr(
            $decoded,
            0,
            $len - SODIUM_CRYPTO_SIGN_BYTES
        );
        $signature = Binary::safeSubstr(
            $decoded,
            $len - SODIUM_CRYPTO_SIGN_BYTES
        );

        $valid = sodium_crypto_sign_verify_detached(
            $signature,
            Util::preAuthEncode($givenHeader, $message, $footer, $implicit),
            $key->raw()
        );
        if (!$valid) {
            throw new PasetoException(
                'Invalid signature for this message',
                ExceptionCode::INVALID_SIGNATURE
            );
        }
        return $message;
    }


    /**
     * Authenticated Encryption with Associated Data -- Encryption
     *
     * Algorithm: XChaCha20-Poly1305 + BLAKE2b-MAC (Encrypt-then-MAC)
     *
     * @param string $plaintext
     * @param string $header
     * @param SymmetricKey $key
     * @param string $footer
     * @param string $implicit
     * @param string $nonceForUnitTesting
     * @return string
     *
     * @throws Exception
     * @throws PasetoException
     * @throws SecurityException
     */
    public static function aeadEncrypt(
        string $plaintext,
        string $header,
        SymmetricKey $key,
        string $footer = '',
        string $implicit = '',
        string $nonceForUnitTesting = ''
    ): string {
        // PASETO Version 4 - Encrypt - Step 2:
        if ($nonceForUnitTesting) {
            $nonce = $nonceForUnitTesting;
        } else {
            $nonce = random_bytes(self::NONCE_SIZE);
        }
        // PASETO Version 4 - Encrypt - Step 3:
        list($encKey, $authKey, $nonce2) = $key->splitV4($nonce);

        /** @var string|bool $ciphertext */
        // PASETO Version 4 - Encrypt - Step 4:
        $ciphertext = sodium_crypto_stream_xchacha20_xor(
            $plaintext,
            $nonce2,
            $encKey
        );
        if (!is_string($ciphertext)) {
            throw new PasetoException(
                'Encryption failed.',
                ExceptionCode::UNSPECIFIED_CRYPTOGRAPHIC_ERROR
            );
        }
        // PASETO Version 4 - Encrypt - Step 5 & 6:
        $mac = sodium_crypto_generichash(
            Util::preAuthEncode($header, $nonce, $ciphertext, $footer, $implicit),
            $authKey
        );
        Util::wipe($encKey);
        Util::wipe($authKey);

        // PASETO Version 4 - Encrypt - Step 7:
        return (new PasetoMessage(
            Header::fromString($header),
            $nonce . $ciphertext . $mac,
            $footer
        ))->toString();
    }

    /**
     * Authenticated Encryption with Associated Data -- Decryption
     *
     * @param string $message
     * @param string $header
     * @param SymmetricKey $key
     * @param string $footer
     * @param string $implicit
     * @return string
     *
     * @throws PasetoException
     * @throws TypeError
     * @throws SodiumException
     */
    public static function aeadDecrypt(
        string $message,
        string $header,
        SymmetricKey $key,
        string $footer = '',
        string $implicit = ''
    ): string {
        $expectedLen = Binary::safeStrlen($header);
        $givenHeader = Binary::safeSubstr($message, 0, $expectedLen);

        // PASETO Version 4 - Decrypt - Step 2:
        if (!hash_equals($header, $givenHeader)) {
            throw new PasetoException(
                'Invalid message header.',
                ExceptionCode::INVALID_HEADER
            );
        }

        // PASETO Version 4 - Decrypt - Step 3:
        try {
            $decoded = Base64UrlSafe::decode(
                Binary::safeSubstr($message, $expectedLen)
            );
        } catch (Throwable $ex) {
            throw new PasetoException(
                'Invalid encoding detected',
                ExceptionCode::INVALID_BASE64URL,
                $ex
            );
        }
        $len = Binary::safeStrlen($decoded);
        $nonce = Binary::safeSubstr($decoded, 0, self::NONCE_SIZE);
        $ciphertext = Binary::safeSubstr(
            $decoded,
            self::NONCE_SIZE,
            $len - (self::NONCE_SIZE + self::MAC_SIZE)
        );
        $mac = Binary::safeSubstr($decoded, $len - self::MAC_SIZE);

        // PASETO Version 4 - Decrypt - Step 4:
        list($encKey, $authKey, $nonce2) = $key->splitV4($nonce);

        // PASETO Version 4 - Decrypt - Step 5 & 6:
        $calc = sodium_crypto_generichash(
            Util::preAuthEncode($header, $nonce, $ciphertext, $footer, $implicit),
            $authKey
        );
        // PASETO Version 4 - Decrypt - Step 7:
        if (!hash_equals($calc, $mac)) {
            throw new SecurityException(
                'Invalid MAC for given ciphertext.',
                ExceptionCode::INVALID_MAC
            );
        }

        // PASETO Version 4 - Decrypt - Step 8:
        /** @var string|bool $plaintext */
        $plaintext = sodium_crypto_stream_xchacha20_xor(
            $ciphertext,
            $nonce2,
            $encKey
        );
        if (!is_string($plaintext)) {
            throw new PasetoException(
                'Encryption failed.',
                ExceptionCode::UNSPECIFIED_CRYPTOGRAPHIC_ERROR
            );
        }

        return $plaintext;
    }
}
