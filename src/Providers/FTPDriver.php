<?php

declare(strict_types=1);

namespace StoragePlatform\Providers;

/**
 * FTPDriver — Storage provider over FTP protocol.
 */
class FTPDriver implements StorageProviderInterface
{
    protected string $host;
    protected int $port;
    protected string $username;
    protected string $password;
    protected string $root;

    public function __construct(array $config)
    {
        $this->host = $config['host'] ?? '127.0.0.1';
        $this->port = (int)($config['port'] ?? 21);
        $this->username = $config['username'] ?? 'anonymous';
        $this->password = $config['password'] ?? '';
        $this->root = rtrim($config['root'] ?? '/', '/');
    }

    protected function connect()
    {
        $conn = @ftp_connect($this->host, $this->port, 10);
        if (!$conn) {
            throw new \RuntimeException("FTP connection failed to {$this->host}:{$this->port}");
        }
        if (!@ftp_login($conn, $this->username, $this->password)) {
            ftp_close($conn);
            throw new \RuntimeException("FTP login failed for user {$this->username}");
        }
        ftp_pasv($conn, true);
        return $conn;
    }

    public function put(string $bucket, string $key, string $source, array $options = []): string
    {
        $conn = $this->connect();
        $remotePath = $this->root . '/' . $bucket . '/' . $key;
        $dir = dirname($remotePath);

        @ftp_mkdir($conn, $dir);
        if (!@ftp_put($conn, $remotePath, $source, FTP_BINARY)) {
            ftp_close($conn);
            throw new \RuntimeException("FTP upload failed for {$key}");
        }
        ftp_close($conn);
        return $key;
    }

    public function get(string $bucket, string $key): string
    {
        $conn = $this->connect();
        $remotePath = $this->root . '/' . $bucket . '/' . $key;
        $temp = sys_get_temp_dir() . '/ftp_' . bin2hex(random_bytes(6));

        if (!@ftp_get($conn, $temp, $remotePath, FTP_BINARY)) {
            ftp_close($conn);
            throw new \RuntimeException("FTP download failed for {$key}");
        }
        ftp_close($conn);
        $content = file_get_contents($temp);
        @unlink($temp);
        return $content ?: '';
    }

    public function delete(string $bucket, string $key): bool
    {
        $conn = $this->connect();
        $res = @ftp_delete($conn, $this->root . '/' . $bucket . '/' . $key);
        ftp_close($conn);
        return $res;
    }

    public function exists(string $bucket, string $key): bool
    {
        $conn = $this->connect();
        $size = @ftp_size($conn, $this->root . '/' . $bucket . '/' . $key);
        ftp_close($conn);
        return $size !== -1;
    }

    public function copy(string $bucket, string $fromKey, string $toKey): bool
    {
        $data = $this->get($bucket, $fromKey);
        $temp = sys_get_temp_dir() . '/ftp_copy_' . bin2hex(random_bytes(6));
        file_put_contents($temp, $data);
        $this->put($bucket, $toKey, $temp);
        @unlink($temp);
        return true;
    }

    public function move(string $bucket, string $fromKey, string $toKey): bool
    {
        if ($this->copy($bucket, $fromKey, $toKey)) {
            return $this->delete($bucket, $fromKey);
        }
        return false;
    }

    public function metadata(string $bucket, string $key): array
    {
        $conn = $this->connect();
        $path = $this->root . '/' . $bucket . '/' . $key;
        $size = @ftp_size($conn, $path);
        $mtime = @ftp_mdtm($conn, $path);
        ftp_close($conn);

        return [
            'size' => $size > 0 ? $size : 0,
            'mime_type' => 'application/octet-stream',
            'last_modified' => $mtime > 0 ? $mtime : time(),
            'etag' => '"' . md5($bucket . $key . $size) . '"',
        ];
    }

    public function listObjects(string $bucket, string $prefix = ''): array
    {
        $conn = $this->connect();
        $dir = $this->root . '/' . $bucket;
        $files = @ftp_nlist($conn, $dir);
        ftp_close($conn);

        if (!$files) {
            return [];
        }
        $results = [];
        foreach ($files as $f) {
            $basename = basename($f);
            if ($prefix === '' || str_starts_with($basename, $prefix)) {
                $results[] = $basename;
            }
        }
        return $results;
    }

    public function streamRead(string $bucket, string $key)
    {
        $content = $this->get($bucket, $key);
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $content);
        rewind($stream);
        return $stream;
    }

    public function streamWrite(string $bucket, string $key, $resource, array $options = []): bool
    {
        $temp = sys_get_temp_dir() . '/ftp_sw_' . bin2hex(random_bytes(6));
        $out = fopen($temp, 'wb');
        stream_copy_to_stream($resource, $out);
        fclose($out);

        try {
            $this->put($bucket, $key, $temp, $options);
            @unlink($temp);
            return true;
        } catch (\Throwable $e) {
            @unlink($temp);
            return false;
        }
    }

    public function temporaryUrl(string $bucket, string $key, int $expiry = 3600): string
    {
        return 'http://localhost:8080/object/' . urlencode($bucket) . '/' . urlencode($key);
    }

    public function listBuckets(): array
    {
        $conn = $this->connect();
        $dirs = @ftp_nlist($conn, $this->root);
        ftp_close($conn);
        return array_map('basename', $dirs ?: []);
    }

    public function createBucket(string $name, array $options = []): bool
    {
        $conn = $this->connect();
        $res = @ftp_mkdir($conn, $this->root . '/' . $name);
        ftp_close($conn);
        return (bool)$res;
    }

    public function deleteBucket(string $name): bool
    {
        $conn = $this->connect();
        $res = @ftp_rmdir($conn, $this->root . '/' . $name);
        ftp_close($conn);
        return (bool)$res;
    }

    public function health(): array
    {
        try {
            $conn = $this->connect();
            ftp_close($conn);
            return ['status' => 'healthy', 'error' => null];
        } catch (\Throwable $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }
}
