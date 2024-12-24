<?php

declare(strict_types=1);

namespace App\Utils;

use Aws\Kms\KmsClient; // aws/aws-sdk-php ^3.0
use Psr\Log\LoggerInterface; // psr/log ^3.0
use Exception;

/**
 * Secure encryption utility class implementing AES-256-GCM encryption with AWS KMS key management.
 * Provides field-level encryption for sensitive data with context binding and comprehensive audit logging.
 */
class Encryption
{
    private const ALGORITHM = 'aes-256-gcm';
    private const IV_LENGTH = 12; // GCM recommended IV length
    private const TAG_LENGTH = 16; // GCM authentication tag length
    private const KEY_LENGTH = 32; // 256 bits
    private const CACHE_TTL = 300; // 5 minutes key cache TTL

    private KmsClient $kmsClient;
    private LoggerInterface $logger;
    private string $keyId;
    private array $keyCache = [];

    /**
     * Initialize encryption utility with AWS KMS client and logging configuration.
     *
     * @param KmsClient $kmsClient AWS KMS client instance
     * @param LoggerInterface $logger PSR-3 logger instance
     * @param string $keyId AWS KMS key ID or ARN
     * @throws Exception If initialization fails
     */
    public function __construct(
        KmsClient $kmsClient,
        LoggerInterface $logger,
        string $keyId
    ) {
        if (empty($keyId)) {
            throw new Exception('KMS key ID cannot be empty');
        }

        $this->kmsClient = $kmsClient;
        $this->logger = $logger;
        $this->keyId = $keyId;

        // Validate KMS key accessibility
        try {
            $this->kmsClient->describeKey(['KeyId' => $this->keyId]);
        } catch (Exception $e) {
            $this->logger->error('KMS key validation failed', [
                'keyId' => $this->keyId,
                'error' => $e->getMessage()
            ]);
            throw new Exception('Failed to validate KMS key: ' . $e->getMessage());
        }

        $this->logger->info('Encryption utility initialized', [
            'keyId' => $this->keyId
        ]);
    }

    /**
     * Encrypt sensitive data using AES-256-GCM with AWS KMS managed keys.
     *
     * @param string $data Plaintext data to encrypt
     * @param array $context Encryption context for key binding
     * @return string Base64 encoded encrypted data
     * @throws Exception If encryption fails
     */
    public function encrypt(string $data, array $context = []): string
    {
        $this->validateInput($data, 'encrypt');

        try {
            // Generate cryptographically secure IV
            $iv = random_bytes(self::IV_LENGTH);

            // Get encryption key
            $keyMaterial = $this->generateDataKey($this->keyId, $context);
            $plainKey = $keyMaterial['Plaintext'];
            $encryptedKey = $keyMaterial['CiphertextBlob'];

            // Encrypt data
            $tag = '';
            $ciphertext = openssl_encrypt(
                $data,
                self::ALGORITHM,
                $plainKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                json_encode($context),
                self::TAG_LENGTH
            );

            if ($ciphertext === false) {
                throw new Exception('Encryption failed: ' . openssl_error_string());
            }

            // Combine components
            $encrypted = base64_encode(
                json_encode([
                    'iv' => base64_encode($iv),
                    'key' => base64_encode($encryptedKey),
                    'data' => base64_encode($ciphertext),
                    'tag' => base64_encode($tag)
                ])
            );

            $this->logger->info('Data encrypted successfully', [
                'contextHash' => hash('sha256', json_encode($context))
            ]);

            return $encrypted;
        } catch (Exception $e) {
            $this->logger->error('Encryption failed', [
                'error' => $e->getMessage(),
                'contextHash' => hash('sha256', json_encode($context))
            ]);
            throw new Exception('Encryption failed: ' . $e->getMessage());
        }
    }

    /**
     * Decrypt data encrypted by the encrypt method.
     *
     * @param string $encryptedData Base64 encoded encrypted data
     * @param array $context Encryption context for key binding
     * @return string Decrypted plaintext
     * @throws Exception If decryption fails
     */
    public function decrypt(string $encryptedData, array $context = []): string
    {
        $this->validateInput($encryptedData, 'decrypt');

        try {
            // Decode and parse encrypted data
            $components = json_decode(base64_decode($encryptedData), true);
            if (!$components) {
                throw new Exception('Invalid encrypted data format');
            }

            $iv = base64_decode($components['iv']);
            $encryptedKey = base64_decode($components['key']);
            $ciphertext = base64_decode($components['data']);
            $tag = base64_decode($components['tag']);

            // Decrypt the data key
            $result = $this->kmsClient->decrypt([
                'CiphertextBlob' => $encryptedKey,
                'EncryptionContext' => $context
            ]);

            $plainKey = $result['Plaintext'];

            // Decrypt data
            $plaintext = openssl_decrypt(
                $ciphertext,
                self::ALGORITHM,
                $plainKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                json_encode($context)
            );

            if ($plaintext === false) {
                throw new Exception('Decryption failed: ' . openssl_error_string());
            }

            $this->logger->info('Data decrypted successfully', [
                'contextHash' => hash('sha256', json_encode($context))
            ]);

            return $plaintext;
        } catch (Exception $e) {
            $this->logger->error('Decryption failed', [
                'error' => $e->getMessage(),
                'contextHash' => hash('sha256', json_encode($context))
            ]);
            throw new Exception('Decryption failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate a new data key using AWS KMS with context binding.
     *
     * @param string $keyId AWS KMS key ID
     * @param array $context Encryption context
     * @return array Array containing plaintext and encrypted key
     * @throws Exception If key generation fails
     */
    private function generateDataKey(string $keyId, array $context): array
    {
        $cacheKey = $keyId . '_' . hash('sha256', json_encode($context));

        // Check cache first
        if (isset($this->keyCache[$cacheKey])) {
            $cached = $this->keyCache[$cacheKey];
            if (time() - $cached['timestamp'] < self::CACHE_TTL) {
                return $cached['key'];
            }
            unset($this->keyCache[$cacheKey]);
        }

        try {
            $result = $this->kmsClient->generateDataKey([
                'KeyId' => $keyId,
                'KeySpec' => 'AES_256',
                'EncryptionContext' => $context
            ]);

            $keyMaterial = [
                'Plaintext' => $result['Plaintext'],
                'CiphertextBlob' => $result['CiphertextBlob']
            ];

            // Cache the key
            $this->keyCache[$cacheKey] = [
                'key' => $keyMaterial,
                'timestamp' => time()
            ];

            $this->logger->debug('Generated new data key', [
                'keyId' => $keyId,
                'contextHash' => hash('sha256', json_encode($context))
            ]);

            return $keyMaterial;
        } catch (Exception $e) {
            $this->logger->error('Failed to generate data key', [
                'keyId' => $keyId,
                'error' => $e->getMessage()
            ]);
            throw new Exception('Failed to generate data key: ' . $e->getMessage());
        }
    }

    /**
     * Validate input parameters for encryption operations.
     *
     * @param mixed $input Input to validate
     * @param string $operation Operation type ('encrypt' or 'decrypt')
     * @return bool True if validation passes
     * @throws Exception If validation fails
     */
    private function validateInput(mixed $input, string $operation): bool
    {
        if (!is_string($input)) {
            throw new Exception('Input must be a string');
        }

        if (empty($input)) {
            throw new Exception('Input cannot be empty');
        }

        if ($operation === 'encrypt' && strlen($input) > 4096) {
            throw new Exception('Input data too large (max 4KB)');
        }

        return true;
    }
}