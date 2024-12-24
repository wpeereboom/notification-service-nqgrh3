<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Utils\Encryption;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Log\LoggerInterface;
use Aws\SecretsManager\SecretsManagerClient;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Enterprise-grade JWT service implementing secure token management with AWS integration.
 * Provides token generation, validation, and refresh capabilities with comprehensive
 * security features including encryption, key rotation, and audit logging.
 * 
 * @version 1.0.0
 */
class JwtService
{
    private const TOKEN_ISSUER = 'notification-service';
    private const TOKEN_LIFETIME = 3600; // 1 hour in seconds
    private const REFRESH_WINDOW = 300; // 5 minutes refresh window
    private const RATE_LIMIT_WINDOW = 60; // 1 minute
    private const MAX_TOKENS_PER_WINDOW = 100;

    private Encryption $encryption;
    private LoggerInterface $logger;
    private SecretsManagerClient $secretsManager;
    private string $privateKeyId;
    private string $publicKeyId;
    private array $tokenBlacklist = [];
    private array $rateLimitCache = [];
    private ?array $currentKeyPair = null;
    private int $lastKeyRotation = 0;

    /**
     * Initialize JWT service with required dependencies and configuration.
     *
     * @param Encryption $encryption Encryption service for payload security
     * @param LoggerInterface $logger PSR-3 logger for audit trail
     * @param SecretsManagerClient $secretsManager AWS Secrets Manager client
     * @param string $privateKeyId AWS Secrets Manager private key ID
     * @param string $publicKeyId AWS Secrets Manager public key ID
     * @throws RuntimeException If initialization fails
     */
    public function __construct(
        Encryption $encryption,
        LoggerInterface $logger,
        SecretsManagerClient $secretsManager,
        string $privateKeyId,
        string $publicKeyId
    ) {
        $this->encryption = $encryption;
        $this->logger = $logger;
        $this->secretsManager = $secretsManager;
        $this->privateKeyId = $privateKeyId;
        $this->publicKeyId = $publicKeyId;

        try {
            // Validate key accessibility
            $this->loadKeyPair();
            
            $this->logger->info('JWT service initialized successfully', [
                'privateKeyId' => $this->privateKeyId,
                'publicKeyId' => $this->publicKeyId
            ]);
        } catch (Exception $e) {
            $this->logger->error('JWT service initialization failed', [
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to initialize JWT service: ' . $e->getMessage());
        }
    }

    /**
     * Generate a secure JWT token with encrypted payload and comprehensive logging.
     *
     * @param array $payload Token payload data
     * @return string Signed JWT token
     * @throws InvalidArgumentException If payload is invalid
     * @throws RuntimeException If token generation fails
     */
    public function generateToken(array $payload): string
    {
        $this->validatePayload($payload);
        $this->checkRateLimit();

        try {
            // Encrypt sensitive payload fields
            $encryptedPayload = $this->encryptSensitiveData($payload);

            // Add standard JWT claims
            $tokenId = bin2hex(random_bytes(16));
            $issuedAt = time();
            $expiresAt = $issuedAt + self::TOKEN_LIFETIME;

            $claims = [
                'iss' => self::TOKEN_ISSUER,
                'jti' => $tokenId,
                'iat' => $issuedAt,
                'exp' => $expiresAt,
                'data' => $encryptedPayload
            ];

            // Ensure fresh key pair
            $keyPair = $this->getKeyPair();

            // Generate token
            $token = JWT::encode($claims, $keyPair['private'], 'RS256', $tokenId);

            $this->logger->info('Token generated successfully', [
                'tokenId' => $tokenId,
                'expiresAt' => date('c', $expiresAt)
            ]);

            return $token;
        } catch (Exception $e) {
            $this->logger->error('Token generation failed', [
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to generate token: ' . $e->getMessage());
        }
    }

    /**
     * Validate JWT token signature, expiration, and blacklist status.
     *
     * @param string $token JWT token to validate
     * @return array Decoded and decrypted token payload
     * @throws InvalidArgumentException If token is invalid
     * @throws RuntimeException If validation fails
     */
    public function validateToken(string $token): array
    {
        try {
            // Check blacklist
            if ($this->isTokenBlacklisted($token)) {
                throw new InvalidArgumentException('Token has been revoked');
            }

            // Get current public key
            $keyPair = $this->getKeyPair();
            
            // Decode and verify token
            $decoded = JWT::decode(
                $token,
                new Key($keyPair['public'], 'RS256')
            );

            // Validate claims
            $this->validateClaims($decoded);

            // Decrypt payload
            $decryptedPayload = $this->decryptSensitiveData((array)$decoded->data);

            $this->logger->info('Token validated successfully', [
                'tokenId' => $decoded->jti
            ]);

            return $decryptedPayload;
        } catch (Exception $e) {
            $this->logger->warning('Token validation failed', [
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Token validation failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate new token while maintaining security context and audit trail.
     *
     * @param string $token Current valid token
     * @return string New signed JWT token
     * @throws InvalidArgumentException If current token is invalid
     * @throws RuntimeException If refresh fails
     */
    public function refreshToken(string $token): string
    {
        try {
            // Validate current token
            $decoded = $this->validateToken($token);

            // Check refresh window
            $tokenData = JWT::decode($token, new Key($this->getKeyPair()['public'], 'RS256'));
            if ($tokenData->exp - time() > self::REFRESH_WINDOW) {
                throw new InvalidArgumentException('Token not eligible for refresh yet');
            }

            // Generate new token with same payload
            $newToken = $this->generateToken($decoded);

            // Add old token to blacklist
            $this->blacklistToken($token);

            $this->logger->info('Token refreshed successfully', [
                'oldTokenId' => $tokenData->jti,
                'newTokenId' => JWT::decode(
                    $newToken,
                    new Key($this->getKeyPair()['public'], 'RS256')
                )->jti
            ]);

            return $newToken;
        } catch (Exception $e) {
            $this->logger->error('Token refresh failed', [
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to refresh token: ' . $e->getMessage());
        }
    }

    /**
     * Load RSA key pair from AWS Secrets Manager.
     *
     * @return array Associative array with 'public' and 'private' keys
     * @throws RuntimeException If key loading fails
     */
    private function loadKeyPair(): array
    {
        try {
            $privateKey = $this->secretsManager->getSecretValue([
                'SecretId' => $this->privateKeyId
            ])->get('SecretString');

            $publicKey = $this->secretsManager->getSecretValue([
                'SecretId' => $this->publicKeyId
            ])->get('SecretString');

            return [
                'private' => $privateKey,
                'public' => $publicKey
            ];
        } catch (Exception $e) {
            throw new RuntimeException('Failed to load key pair: ' . $e->getMessage());
        }
    }

    /**
     * Get current key pair, handling rotation if needed.
     *
     * @return array Current RSA key pair
     */
    private function getKeyPair(): array
    {
        // Check if key rotation is needed (every 6 hours)
        if ($this->lastKeyRotation < (time() - 21600)) {
            $this->currentKeyPair = $this->loadKeyPair();
            $this->lastKeyRotation = time();
        }

        return $this->currentKeyPair ?? $this->loadKeyPair();
    }

    /**
     * Encrypt sensitive payload data using the encryption service.
     *
     * @param array $payload Raw payload data
     * @return array Encrypted payload
     */
    private function encryptSensitiveData(array $payload): array
    {
        $sensitiveFields = ['user_id', 'email', 'permissions'];
        
        foreach ($sensitiveFields as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = $this->encryption->encrypt(
                    is_array($payload[$field]) ? json_encode($payload[$field]) : $payload[$field]
                );
            }
        }

        return $payload;
    }

    /**
     * Decrypt sensitive payload data using the encryption service.
     *
     * @param array $payload Encrypted payload data
     * @return array Decrypted payload
     */
    private function decryptSensitiveData(array $payload): array
    {
        $sensitiveFields = ['user_id', 'email', 'permissions'];
        
        foreach ($sensitiveFields as $field) {
            if (isset($payload[$field])) {
                $decrypted = $this->encryption->decrypt($payload[$field]);
                $payload[$field] = $this->isJson($decrypted) ? 
                    json_decode($decrypted, true) : 
                    $decrypted;
            }
        }

        return $payload;
    }

    /**
     * Validate token claims for security requirements.
     *
     * @param object $claims Token claims
     * @throws InvalidArgumentException If claims are invalid
     */
    private function validateClaims(object $claims): void
    {
        if (!isset($claims->iss) || $claims->iss !== self::TOKEN_ISSUER) {
            throw new InvalidArgumentException('Invalid token issuer');
        }

        if (!isset($claims->exp) || $claims->exp < time()) {
            throw new InvalidArgumentException('Token has expired');
        }

        if (!isset($claims->jti) || !isset($claims->iat)) {
            throw new InvalidArgumentException('Missing required claims');
        }
    }

    /**
     * Check if token generation rate limit has been exceeded.
     *
     * @throws RuntimeException If rate limit exceeded
     */
    private function checkRateLimit(): void
    {
        $window = time() - self::RATE_LIMIT_WINDOW;
        $this->rateLimitCache = array_filter(
            $this->rateLimitCache,
            fn($timestamp) => $timestamp >= $window
        );

        if (count($this->rateLimitCache) >= self::MAX_TOKENS_PER_WINDOW) {
            throw new RuntimeException('Token generation rate limit exceeded');
        }

        $this->rateLimitCache[] = time();
    }

    /**
     * Add token to blacklist for revocation.
     *
     * @param string $token Token to blacklist
     */
    private function blacklistToken(string $token): void
    {
        $decoded = JWT::decode($token, new Key($this->getKeyPair()['public'], 'RS256'));
        $this->tokenBlacklist[$decoded->jti] = time() + ($decoded->exp - $decoded->iat);
        
        // Clean expired entries
        $this->tokenBlacklist = array_filter(
            $this->tokenBlacklist,
            fn($expiry) => $expiry >= time()
        );
    }

    /**
     * Check if token is blacklisted.
     *
     * @param string $token Token to check
     * @return bool True if token is blacklisted
     */
    private function isTokenBlacklisted(string $token): bool
    {
        try {
            $decoded = JWT::decode($token, new Key($this->getKeyPair()['public'], 'RS256'));
            return isset($this->tokenBlacklist[$decoded->jti]);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate payload data for token generation.
     *
     * @param array $payload Payload to validate
     * @throws InvalidArgumentException If payload is invalid
     */
    private function validatePayload(array $payload): void
    {
        if (empty($payload)) {
            throw new InvalidArgumentException('Payload cannot be empty');
        }

        $requiredFields = ['user_id'];
        foreach ($requiredFields as $field) {
            if (!isset($payload[$field])) {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }
    }

    /**
     * Check if a string is valid JSON.
     *
     * @param string $string String to check
     * @return bool True if string is valid JSON
     */
    private function isJson(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}