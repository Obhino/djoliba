<?php

namespace App\Service\Security;

class EncryptionService
{
    private string $key;

    public function __construct(string $encryptionKeyHex)
    {
        $key = hex2bin($encryptionKeyHex);
        if ($key === false || strlen($key) !== 32) {
            throw new \InvalidArgumentException("La clé de chiffrement doit être une chaîne hexadécimale de 32 octets (64 caractères).");
        }
        $this->key = $key;
    }

    /**
     * Chiffre une chaîne de texte.
     */
    public function encrypt(string $plainText): string
    {
        if (empty($plainText)) {
            return '';
        }

        // Génère un nonce cryptographiquement sécurisé
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        // Chiffre le texte
        $cipherText = sodium_crypto_secretbox($plainText, $nonce, $this->key);

        // Retourne le nonce combiné avec le message chiffré en base64
        return base64_encode($nonce . $cipherText);
    }

    /**
     * Déchiffre une chaîne chiffrée.
     */
    public function decrypt(string $cipherTextBase64): string
    {
        if (empty($cipherTextBase64)) {
            return '';
        }

        $decoded = base64_decode($cipherTextBase64);
        if ($decoded === false) {
            return '';
        }

        $nonceSize = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($decoded) < $nonceSize + 1) {
            return '';
        }

        $nonce = substr($decoded, 0, $nonceSize);
        $cipherText = substr($decoded, $nonceSize);

        $plainText = sodium_crypto_secretbox_open($cipherText, $nonce, $this->key);
        if ($plainText === false) {
            throw new \RuntimeException("Échec du déchiffrement : données corrompues ou clé invalide.");
        }

        return $plainText;
    }
}
