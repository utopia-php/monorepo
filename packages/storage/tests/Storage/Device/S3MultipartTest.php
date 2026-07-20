<?php

declare(strict_types=1);

namespace Utopia\Tests\Storage\Device;

use PHPUnit\Framework\TestCase;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\S3\Response as S3Response;

/**
 * Testable S3 subclass that exposes protected helpers.
 */
class TestableS3 extends S3
{
    /**
     * @var array<string>
     */
    public array $calls = [];

    public string $completedBody = '';

    /**
     * @var array<string, array<string, string>>
     */
    public array $headersByOperation = [];

    private bool $objectExists = false;

    #[\Override]
    protected function call(string $operation, string $method, string $uri, string $data = '', array $parameters = [], array $headers = [], array $amzHeaders = [], bool $decode = true): S3Response
    {
        $this->calls[] = $operation;
        $this->headersByOperation[$operation] = $headers;

        if ($operation === 's3:info') {
            if (! $this->objectExists) {
                throw new \Exception('Not found');
            }

            return new S3Response(code: 200, headers: ['content-length' => '1'], body: '');
        }

        if ($operation === 's3:createMultipartUpload') {
            return new S3Response(code: 200, headers: [], body: ['UploadId' => 'upload-123']);
        }

        if ($operation === 's3:uploadPart') {
            return new S3Response(code: 200, headers: ['etag' => 'etag-' . $parameters['partNumber']], body: '');
        }

        if ($operation === 's3:completeMultipartUpload') {
            $this->completedBody = $data;
            $this->objectExists = true;

            return new S3Response(code: 200, headers: [], body: '');
        }

        return new S3Response(code: 200, headers: [], body: '');
    }
}

final class S3MultipartTest extends TestCase
{
    private TestableS3 $s3;

    protected function setUp(): void
    {
        $this->s3 = new TestableS3(
            root: '/root',
            accessKey: 'test-key',
            secretKey: 'test-secret',
            host: 'https://s3.example.com',
            region: 'us-east-1',
        );
    }

    public function testPrepareUploadCreatesMultipartMetadata(): void
    {
        $metadata = [];

        $this->s3->prepareUpload('/root/file.txt', 'text/plain', 2, $metadata);

        $this->assertSame('upload-123', $metadata['uploadId'] ?? null);
        $this->assertSame([], $metadata['parts'] ?? null);
        $this->assertSame(0, $metadata['chunks'] ?? null);
        $this->assertSame(['s3:createMultipartUpload'], $this->s3->calls);
    }

    public function testUploadChunkRecordsPartWithoutCompleting(): void
    {
        $metadata = [];
        $source = __DIR__ . '/s3-chunk.part';
        file_put_contents($source, 'aaa');

        $this->s3->prepareUpload('/root/file.txt', 'text/plain', 2, $metadata);
        $chunks = $this->s3->uploadChunk($source, '/root/file.txt', 1, 2, $metadata);

        $this->assertSame(1, $chunks);
        $this->assertSame('etag-1', ($metadata['parts'] ?? [])[1] ?? null);
        $this->assertNotContains('s3:completeMultipartUpload', $this->s3->calls);

        unlink($source);
    }

    public function testSingleChunkUploadDataDoesNotFinalizeOrCheckExists(): void
    {
        $metadata = [];

        $this->assertSame(1, $this->s3->uploadData('aaa', '/root/file.txt', 'text/plain', 1, 1, $metadata));
        $this->assertSame(['s3:write'], $this->s3->calls);
        $this->assertSame([1 => true], $metadata['parts'] ?? null);
        $this->assertSame(1, $metadata['chunks'] ?? null);
    }

    public function testFinalizeUploadRequiresAllS3Parts(): void
    {
        $metadata = [
            'uploadId' => 'upload-123',
            'parts' => [1 => 'etag-1'],
            'chunks' => 1,
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing chunk 2');
        $this->s3->finalizeUpload('/root/file.txt', 2, $metadata);
    }

    public function testFinalizeUploadCompletesS3PartsInNumericOrder(): void
    {
        $metadata = [
            'uploadId' => 'upload-123',
            'parts' => [
                10 => 'etag-10',
                9 => 'etag-9',
                8 => 'etag-8',
                7 => 'etag-7',
                6 => 'etag-6',
                5 => 'etag-5',
                4 => 'etag-4',
                3 => 'etag-3',
                2 => 'etag-2',
                1 => 'etag-1',
            ],
            'chunks' => 10,
        ];

        $this->assertTrue($this->s3->finalizeUpload('/root/file.txt', 10, $metadata));

        $part1 = strpos($this->s3->completedBody, '<PartNumber>1</PartNumber>');
        $part2 = strpos($this->s3->completedBody, '<PartNumber>2</PartNumber>');
        $part10 = strpos($this->s3->completedBody, '<PartNumber>10</PartNumber>');

        $this->assertNotFalse($part1);
        $this->assertNotFalse($part2);
        $this->assertNotFalse($part10);
        $this->assertLessThan($part2, $part1);
        $this->assertLessThan($part10, $part2);
    }

    public function testFinalizeUploadSendsCompleteBodyAsXml(): void
    {
        $metadata = [
            'uploadId' => 'upload-123',
            'parts' => [1 => 'etag-1', 2 => 'etag-2'],
            'chunks' => 2,
        ];

        $this->assertTrue($this->s3->finalizeUpload('/root/file.txt', 2, $metadata));
        $this->assertSame('application/xml', $this->s3->headersByOperation['s3:completeMultipartUpload']['content-type']);
    }
}
