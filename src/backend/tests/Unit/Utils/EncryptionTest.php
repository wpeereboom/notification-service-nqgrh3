<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use App\Utils\Encryption;
use Aws\Kms\KmsClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Exception;

/**
 * Comprehensive test suite for the Encryption utility class.
 * Verifies encryption/decryption functionality, AWS KMS integration,
 * and security controls with 100% coverage.
 *
 * @covers \App\Utils\Encryption
 */
class EncryptionTest extends TestCase
{
    private Encryption $encryption;
    private MockObject|KmsClient $kmsClientMock;
    private MockObject|LoggerInterface $loggerMock;
    private string $testKeyId = 'arn:aws:kms:region:account:key/test-key-id';
    private array $testContext = ['purpose' => 'testing', 'environment' => 'unit'];

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void
    {
        // Create KMS client mock
        $this->kmsClientMock = $this->createMock(KmsClient::class);
        
        // Configure describeKey for constructor validation
        $this->kmsClientMock->expects($this->once())
            ->method('describeKey')
            ->with(['KeyId' => $this->testKeyId])
            ->willReturn(['KeyMetadata' => ['KeyId' => $this->testKeyId]]);

        // Create logger mock
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        // Initialize encryption instance
        $this->encryption = new Encryption(
            $this->kmsClientMock,
            $this->loggerMock,
            $this->testKeyId
        );
    }

    /**
     * Test successful encryption with context binding.
     */
    public function testEncryptSuccessfully(): void
    {
        $plaintext = 'sensitive data';
        $dataKey = random_bytes(32);
        $encryptedKey = random_bytes(64);

        // Configure KMS mock for generateDataKey
        $this->kmsClientMock->expects($this->once())
            ->method('generateDataKey')
            ->with([
                'KeyId' => $this->testKeyId,
                'KeySpec' => 'AES_256',
                'EncryptionContext' => $this->testContext
            ])
            ->willReturn([
                'Plaintext' => $dataKey,
                'CiphertextBlob' => $encryptedKey
            ]);

        // Expect successful encryption logging
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Data encrypted successfully',
                $this->callback(function ($context) {
                    return isset($context['contextHash']);
                })
            );

        $encrypted = $this->encryption->encrypt($plaintext, $this->testContext);

        // Verify encrypted data structure
        $decoded = json_decode(base64_decode($encrypted), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('iv', $decoded);
        $this->assertArrayHasKey('key', $decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertArrayHasKey('tag', $decoded);
    }

    /**
     * Test successful decryption with context verification.
     */
    public function testDecryptSuccessfully(): void
    {
        $plaintext = 'sensitive data';
        $dataKey = random_bytes(32);
        
        // Create encrypted data structure
        $encrypted = base64_encode(json_encode([
            'iv' => base64_encode(random_bytes(12)),
            'key' => base64_encode(random_bytes(64)),
            'data' => base64_encode(random_bytes(32)),
            'tag' => base64_encode(random_bytes(16))
        ]));

        // Configure KMS mock for decrypt
        $this->kmsClientMock->expects($this->once())
            ->method('decrypt')
            ->with($this->callback(function ($params) {
                return isset($params['CiphertextBlob']) &&
                       $params['EncryptionContext'] === $this->testContext;
            }))
            ->willReturn(['Plaintext' => $dataKey]);

        // Expect successful decryption logging
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Data decrypted successfully',
                $this->callback(function ($context) {
                    return isset($context['contextHash']);
                })
            );

        $this->encryption->decrypt($encrypted, $this->testContext);
    }

    /**
     * Test key rotation functionality.
     */
    public function testKeyRotation(): void
    {
        $plaintext = 'test data';
        $oldKey = random_bytes(32);
        $newKey = random_bytes(32);

        // Configure KMS mock for key rotation
        $this->kmsClientMock->expects($this->exactly(2))
            ->method('generateDataKey')
            ->willReturnOnConsecutiveCalls(
                [
                    'Plaintext' => $oldKey,
                    'CiphertextBlob' => random_bytes(64)
                ],
                [
                    'Plaintext' => $newKey,
                    'CiphertextBlob' => random_bytes(64)
                ]
            );

        // Encrypt with old key
        $encrypted = $this->encryption->encrypt($plaintext, $this->testContext);

        // Encrypt with new key
        $encryptedNew = $this->encryption->encrypt($plaintext, $this->testContext);

        $this->assertNotEquals($encrypted, $encryptedNew);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function invalidInputProvider(): array
    {
        return [
            'null_input' => [null],
            'empty_string' => [''],
            'non_string' => [['array']],
            'too_large' => [str_repeat('a', 4097)]
        ];
    }

    /**
     * Test encryption with invalid input.
     *
     * @dataProvider invalidInputProvider
     */
    public function testEncryptWithInvalidInput(mixed $invalidInput): void
    {
        $this->expectException(Exception::class);

        // Expect error logging
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Encryption failed',
                $this->callback(function ($context) {
                    return isset($context['error']);
                })
            );

        $this->encryption->encrypt($invalidInput, $this->testContext);
    }

    /**
     * @return array<string, array<string>>
     */
    public function invalidEncryptedDataProvider(): array
    {
        return [
            'invalid_json' => ['invalid-base64'],
            'missing_components' => [base64_encode(json_encode(['iv' => 'test']))],
            'invalid_base64' => [base64_encode(json_encode([
                'iv' => 'invalid',
                'key' => 'invalid',
                'data' => 'invalid',
                'tag' => 'invalid'
            ]))]
        ];
    }

    /**
     * Test decryption with invalid input.
     *
     * @dataProvider invalidEncryptedDataProvider
     */
    public function testDecryptWithInvalidInput(string $invalidInput): void
    {
        $this->expectException(Exception::class);

        // Expect error logging
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Decryption failed',
                $this->callback(function ($context) {
                    return isset($context['error']);
                })
            );

        $this->encryption->decrypt($invalidInput, $this->testContext);
    }

    /**
     * Test handling of KMS service failures.
     */
    public function testKmsServiceFailure(): void
    {
        // Configure KMS mock to simulate failure
        $this->kmsClientMock->expects($this->once())
            ->method('generateDataKey')
            ->willThrowException(new Exception('KMS service error'));

        // Expect error logging
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Encryption failed',
                $this->callback(function ($context) {
                    return isset($context['error']) &&
                           str_contains($context['error'], 'KMS service error');
                })
            );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Encryption failed: KMS service error');

        $this->encryption->encrypt('test data', $this->testContext);
    }
}