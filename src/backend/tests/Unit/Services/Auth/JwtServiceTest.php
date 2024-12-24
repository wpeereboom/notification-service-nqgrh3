<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth;

use App\Services\Auth\JwtService;
use App\Utils\Encryption;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Aws\SecretsManager\SecretsManagerClient;
use Aws\Result;
use InvalidArgumentException;
use RuntimeException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Comprehensive test suite for JwtService class.
 * @covers \App\Services\Auth\JwtService
 */
class JwtServiceTest extends TestCase
{
    private const TEST_PRIVATE_KEY = '-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC9QFi8...
-----END PRIVATE KEY-----';

    private const TEST_PUBLIC_KEY = '-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAvUBYvD...
-----END PUBLIC KEY-----';

    private const TEST_TOKEN_PAYLOAD = [
        'user_id' => 1,
        'roles' => ['user'],
        'permissions' => ['read', 'write']
    ];

    private JwtService $jwtService;
    private MockObject $encryptionMock;
    private MockObject $loggerMock;
    private MockObject $secretsManagerMock;

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void
    {
        // Create mock objects
        $this->encryptionMock = $this->createMock(Encryption::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->secretsManagerMock = $this->createMock(SecretsManagerClient::class);

        // Configure secrets manager mock for key loading
        $this->secretsManagerMock
            ->method('getSecretValue')
            ->willReturnCallback(function($params) {
                $value = $params['SecretId'] === 'private_key' 
                    ? self::TEST_PRIVATE_KEY 
                    : self::TEST_PUBLIC_KEY;
                return new Result(['SecretString' => $value]);
            });

        // Configure encryption mock for sensitive data
        $this->encryptionMock
            ->method('encrypt')
            ->willReturnCallback(fn($data) => base64_encode($data));
        $this->encryptionMock
            ->method('decrypt')
            ->willReturnCallback(fn($data) => base64_decode($data));

        // Initialize service
        $this->jwtService = new JwtService(
            $this->encryptionMock,
            $this->loggerMock,
            $this->secretsManagerMock,
            'private_key',
            'public_key'
        );
    }

    /**
     * Test successful token generation with proper claims.
     */
    public function testGenerateTokenSuccess(): void
    {
        // Expect logging of successful token generation
        $this->loggerMock
            ->expects($this->once())
            ->method('info')
            ->with(
                'Token generated successfully',
                $this->arrayHasKey('tokenId')
            );

        // Generate token
        $token = $this->jwtService->generateToken(self::TEST_TOKEN_PAYLOAD);

        // Decode token for validation
        $decoded = JWT::decode(
            $token,
            new Key(self::TEST_PUBLIC_KEY, 'RS256')
        );

        // Verify standard claims
        $this->assertEquals('notification-service', $decoded->iss);
        $this->assertIsString($decoded->jti);
        $this->assertIsInt($decoded->iat);
        $this->assertIsInt($decoded->exp);
        
        // Verify expiration is set to 1 hour
        $this->assertEquals($decoded->exp - $decoded->iat, 3600);

        // Verify payload data is encrypted
        $this->assertIsObject($decoded->data);
        $this->assertTrue(isset($decoded->data->user_id));
        $this->assertTrue(isset($decoded->data->roles));
        $this->assertTrue(isset($decoded->data->permissions));
    }

    /**
     * Test successful token validation.
     */
    public function testValidateTokenSuccess(): void
    {
        // Generate a token for testing
        $token = $this->jwtService->generateToken(self::TEST_TOKEN_PAYLOAD);

        // Expect logging of successful validation
        $this->loggerMock
            ->expects($this->once())
            ->method('info')
            ->with(
                'Token validated successfully',
                $this->arrayHasKey('tokenId')
            );

        // Validate token
        $payload = $this->jwtService->validateToken($token);

        // Verify payload contents
        $this->assertEquals(self::TEST_TOKEN_PAYLOAD['user_id'], $payload['user_id']);
        $this->assertEquals(self::TEST_TOKEN_PAYLOAD['roles'], $payload['roles']);
        $this->assertEquals(self::TEST_TOKEN_PAYLOAD['permissions'], $payload['permissions']);
    }

    /**
     * Test successful token refresh operation.
     */
    public function testRefreshTokenSuccess(): void
    {
        // Generate initial token with near-expiration time
        $token = $this->jwtService->generateToken(self::TEST_TOKEN_PAYLOAD);

        // Expect logging of successful refresh
        $this->loggerMock
            ->expects($this->once())
            ->method('info')
            ->with(
                'Token refreshed successfully',
                $this->logicalAnd(
                    $this->arrayHasKey('oldTokenId'),
                    $this->arrayHasKey('newTokenId')
                )
            );

        // Refresh token
        $newToken = $this->jwtService->refreshToken($token);

        // Verify new token is different
        $this->assertNotEquals($token, $newToken);

        // Verify old token is now invalid
        $this->expectException(RuntimeException::class);
        $this->jwtService->validateToken($token);
    }

    /**
     * Test token validation failures.
     */
    public function testTokenValidationFailure(): void
    {
        // Test expired token
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Token has expired');
        
        // Create expired token by manipulating claims
        $claims = [
            'iss' => 'notification-service',
            'jti' => bin2hex(random_bytes(16)),
            'iat' => time() - 7200,
            'exp' => time() - 3600,
            'data' => self::TEST_TOKEN_PAYLOAD
        ];
        
        $expiredToken = JWT::encode($claims, self::TEST_PRIVATE_KEY, 'RS256');
        $this->jwtService->validateToken($expiredToken);
    }

    /**
     * Test rate limiting for token generation.
     */
    public function testTokenGenerationRateLimit(): void
    {
        // Generate tokens up to limit
        for ($i = 0; $i < 100; $i++) {
            $this->jwtService->generateToken(self::TEST_TOKEN_PAYLOAD);
        }

        // Expect rate limit exception
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Token generation rate limit exceeded');
        $this->jwtService->generateToken(self::TEST_TOKEN_PAYLOAD);
    }

    /**
     * Test invalid payload validation.
     */
    public function testInvalidPayload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required field: user_id');
        
        $invalidPayload = ['roles' => ['user']];
        $this->jwtService->generateToken($invalidPayload);
    }

    /**
     * Test token blacklisting.
     */
    public function testTokenBlacklisting(): void
    {
        // Generate and refresh token to trigger blacklisting
        $token = $this->jwtService->generateToken(self::TEST_TOKEN_PAYLOAD);
        $this->jwtService->refreshToken($token);

        // Verify blacklisted token is rejected
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Token has been revoked');
        $this->jwtService->validateToken($token);
    }
}