<?php

declare(strict_types=1);

namespace Utopia\Storage;

use Exception;

/**
 * @phpstan-type UploadMetadata array{parts?: array<int, bool|string>, chunks?: int, content_type?: string, uploadId?: string}
 */
abstract class Device
{
    /**
     * Default max chunk size while transferring file from one device to another
     */
    public const TRANSFER_CHUNK_SIZE = 20000000; // 20 MB

    /**
     * Sets the maximum number of keys returned to the response. By default, the action returns up to 1,000 key names.
     */
    protected const MAX_PAGE_SIZE = PHP_INT_MAX;

    /**
     * Get Name.
     *
     * Get storage device name
     */
    abstract public function getName(): string;

    /**
     * Get Type.
     *
     * Get storage device type
     */
    abstract public function getType(): DeviceType;

    /**
     * Get Description.
     *
     * Get storage device description and purpose.
     */
    abstract public function getDescription(): string;

    /**
     * Get Root.
     *
     * Get storage device root path
     */
    abstract public function getRoot(): string;

    /**
     * Get Path.
     *
     * Each device hold a complex directory structure that is being build in this method.
     */
    abstract public function getPath(string $filename, ?string $prefix = null): string;

    /**
     * Upload.
     *
     * Upload a file to desired destination in the selected disk
     * return number of chunks uploaded or 0 if it fails.
     *
     * @param  UploadMetadata  $metadata
     *
     * @throws Exception
     */
    abstract public function upload(string $source, string $path, int $chunk = 1, int $chunks = 1, array &$metadata = []): int;

    /**
     * Prepare Upload.
     *
     * Initialize adapter-specific upload state without transferring a chunk body.
     *
     * @param  UploadMetadata  $metadata
     *
     * @throws Exception
     */
    abstract public function prepareUpload(string $path, string $contentType, int $chunks = 1, array &$metadata = []): void;

    /**
     * Upload Chunk.
     *
     * Upload exactly one chunk without finalizing the full upload.
     *
     * @param  UploadMetadata  $metadata
     *
     * @throws Exception
     */
    abstract public function uploadChunk(string $source, string $path, int $chunk = 1, int $chunks = 1, array &$metadata = []): int;

    /**
     * Finalize Upload.
     *
     * Complete a prepared upload once all chunks are known to be present.
     *
     * @param  UploadMetadata  $metadata
     *
     * @throws Exception
     */
    abstract public function finalizeUpload(string $path, int $chunks = 1, array &$metadata = []): bool;

    /**
     * Upload Data.
     *
     * Upload file contents to desired destination in the selected disk.
     * return number of chunks uploaded or 0 if it fails.
     *
     * @param  UploadMetadata  $metadata
     *
     * @throws Exception
     */
    abstract public function uploadData(string $data, string $path, string $contentType, int $chunk = 1, int $chunks = 1, array &$metadata = []): int;

    /**
     * Abort Chunked Upload
     */
    abstract public function abort(string $path, string $extra = ''): bool;

    /**
     * Read file by given path.
     *
     * @param  int<0, max>  $offset
     * @param  int<0, max>|null  $length
     */
    abstract public function read(string $path, int $offset = 0, ?int $length = null): string;

    /**
     * Transfer
     * Transfer a file from current device to destination device.
     *
     * @param  positive-int  $chunkSize
     */
    abstract public function transfer(string $path, string $destination, Device $device, int $chunkSize = self::TRANSFER_CHUNK_SIZE): bool;

    /**
     * Write file by given path.
     */
    abstract public function write(string $path, string $data, string $contentType): bool;

    /**
     * Move file from given source to given path, return true on success and false on failure.
     *
     * @see http://php.net/manual/en/function.filesize.php
     */
    public function move(string $source, string $target): bool
    {
        if ($source === $target) {
            return false;
        }

        if ($this->transfer($source, $target, $this)) {
            return $this->delete($source);
        }

        return false;
    }

    /**
     * Delete file in given path return true on success and false on failure.
     *
     * @see http://php.net/manual/en/function.filesize.php
     */
    abstract public function delete(string $path, bool $recursive = false): bool;

    /**
     * Delete files in given path, path must be a directory. return true on success and false on failure.
     */
    abstract public function deletePath(string $path): bool;

    /**
     * Check if file exists
     */
    abstract public function exists(string $path): bool;

    /**
     * Returns given file path its size.
     *
     * @see http://php.net/manual/en/function.filesize.php
     */
    abstract public function getFileSize(string $path): int;

    /**
     * Returns given file path its mime type.
     *
     * @see http://php.net/manual/en/function.mime-content-type.php
     */
    abstract public function getFileMimeType(string $path): string;

    /**
     * Returns given file path its MD5 hash value.
     *
     * @see http://php.net/manual/en/function.md5-file.php
     */
    abstract public function getFileHash(string $path): string;

    /**
     * Create a directory at the specified path.
     *
     * Returns true on success or if the directory already exists and false on error
     */
    abstract public function createDirectory(string $path): bool;

    /**
     * Get directory size in bytes.
     *
     * Return -1 on error
     *
     * Based on http://www.jonasjohn.de/snippets/php/dir-size.htm
     */
    abstract public function getDirectorySize(string $path): int;

    /**
     * Get Partition Free Space.
     *
     * disk_free_space — Returns available space on filesystem or disk partition
     */
    abstract public function getPartitionFreeSpace(): float;

    /**
     * Get Partition Total Space.
     *
     * disk_total_space — Returns the total size of a filesystem or disk partition
     */
    abstract public function getPartitionTotalSpace(): float;

    /**
     * Get all files and directories inside a directory.
     *
     * @param  string  $dir  Directory to scan
     * @return array<mixed>
     */
    abstract public function getFiles(string $dir, int $max = self::MAX_PAGE_SIZE, string $continuationToken = ''): array;

    /**
     * Get the absolute path by resolving strings like ../, .., //, /\ and so on.
     *
     * Works like the realpath function but works on files that does not exist
     *
     * Reference https://www.php.net/manual/en/function.realpath.php#84012
     */
    public function getAbsolutePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), fn(string $part): bool => $part !== '');

        $absolutes = [];
        foreach ($parts as $part) {
            if ($part == '.') {
                continue;
            }
            if ($part == '..') {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }

        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $absolutes);
    }
}
