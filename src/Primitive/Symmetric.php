<?php
namespace ParagonIE\Halite\Primitive;

use ParagonIE\Halite\Alerts\Crypto as CryptoAlert;
use ParagonIE\Halite\Contract;
use ParagonIE\Halite\Util as CryptoUtil;
use ParagonIE\Halite\Halite as Config;
use \ParagonIE\Halite\Key;

class Symmetric implements Contract\SymmetricKeyCryptoInterface
{   
    /**
     * Decrypt a message using the Halite encryption protocol
     * 
     * @param string $ciphertext
     * @param Key $secretKey
     * @param boolean $raw Don't hex decode the input?
     */
    public static function decrypt($ciphertext, Contract\CryptoKeyInterface $secretKey, $raw = false)
    {
        if (!$raw) {
            // We were given hex data:
            $ciphertext = \Sodium\hex2bin($ciphertext);
        }
        $length = CryptoUtil::safeStrlen($ciphertext);
        
        // The first 4 bytes are reserved for the version size
        $version = CryptoUtil::safeSubstr($ciphertext, 0, Config::VERSION_TAG_LEN);
        
        // The HKDF is used for key splitting
        $salt = CryptoUtil::safeSubstr(
            $ciphertext,
            Config::VERSION_TAG_LEN,
            Config::HKDF_SALT_LEN
        );
        
        // This is the nonce (we authenticated it):
        $nonce = CryptoUtil::safeSubstr(
            $ciphertext, 
            // 36:
            Config::VERSION_TAG_LEN + Config::HKDF_SALT_LEN,
            // 24:
            \Sodium\CRYPTO_STREAM_NONCEBYTES
        );
        
        // This is the crypto_stream_xor()ed ciphertext
        $xored = CryptoUtil::safeSubstr(
            $ciphertext, 
            // 60:
                Config::VERSION_TAG_LEN +
                Config::HKDF_SALT_LEN +
                \Sodium\CRYPTO_STREAM_NONCEBYTES,
            // $length - 92:
            $length - (
                Config::VERSION_TAG_LEN +
                Config::HKDF_SALT_LEN +
                \Sodium\CRYPTO_STREAM_NONCEBYTES +
                \Sodium\CRYPTO_AUTH_BYTES
            )
        );
        
        // $auth is the last 32 bytes
        $auth = CryptoUtil::safeSubstr($ciphertext, $length - \Sodium\CRYPTO_AUTH_BYTES);
        
        // Split our keys
        list($eKey, $aKey) = self::splitKeys($secretKey, $salt);
        
        // Check the MAC first
        if (!\Sodium\crypto_auth_verify(
            $auth, 
            $version . $salt . $nonce . $xored,
            $aKey
        )) {
            throw new CryptoAlert\InvalidMessage(
                'Invalid message authenticaiton code'
            );
        }
        // Down the road, do whatever logic around $version here, in case we
        // need to upgrade our protocol.
        
        
        // Add version logic above
        $plaintext = \Sodium\crypto_stream_xor($xored, $nonce, $eKey);
        if ($plaintext === false) {
            throw new CryptoAlert\InvalidMessage(
                'Invalid message authenticaiton code'
            );
        }
        return $plaintext;
    }
    
    /**
     * Encrypt a message using the Halite encryption protocol
     * 
     * @param string $plaintext
     * @param Key $secretKey
     * @param boolean $raw Don't hex encode the output?
     * @return string
     */
    public static function encrypt($plaintext, Contract\CryptoKeyInterface $secretKey, $raw = false)
    {
        $nonce = \Sodium\randombytes_buf(\Sodium\CRYPTO_SECRETBOX_NONCEBYTES);
        $salt = \Sodium\randombytes_buf(Config::HKDF_SALT_LEN);
        list($eKey, $aKey) = self::splitKeys($secretKey, $salt);
        $xored = \Sodium\crypto_stream_xor($plaintext, $nonce, $eKey);
        $auth = \Sodium\crypto_auth(
            Config::HALITE_VERSION . $salt . $nonce . $xored,
            $aKey
        );
        
        \Sodium\memzero($eKey);
        \Sodium\memzero($aKey);
        if (!$raw) {
            return \Sodium\bin2hex(Config::HALITE_VERSION . $salt . $nonce . $xored . $auth);
        }
        return Config::HALITE_VERSION . $salt . $nonce . $xored . $auth;
    }
    
    /**
     * Generate an encryption key
     * 
     * @param array $type
     */
    public static function generateKeys($type = Key::CRYPTO_SECRETBOX)
    {
        if ($type & Key::ASYMMETRIC !== 0) {
            throw new CryptoAlert\InvalidFlags;
        }
        $secret = '';
        switch ($type) {
            case Key::ENCRYPTION:
            case Key::CRYPTO_AUTH:
            case Key::CRYPTO_SECRETBOX:
                return [
                    Key::generate($type, $secret),
                    $secret
                ];
            default:
                throw new CryptoAlert\InvalidKey;
        }
    }
    
    /**
     * Split a key using a variant of HKDF that used a keyed BLAKE2b hash rather
     * than an HMAC construct
     * 
     * @param \ParagonIE\Halite\Key $master
     * @param string $salt
     * @return array
     */
    public static function splitKeys(Key $master, $salt = null)
    {
        $binary = $master->get();
        return [
            CryptoUtil::hkdfBlake2b($binary, \Sodium\CRYPTO_SECRETBOX_KEYBYTES, Config::HKDF_SBOX, $salt),
            CryptoUtil::hkdfBlake2b($binary, \Sodium\CRYPTO_AUTH_KEYBYTES, Config::HKDF_AUTH, $salt)
        ];
    }
}
