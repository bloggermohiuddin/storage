<?php

declare(strict_types=1);

namespace StoragePlatform\Tests;

use PHPUnit\Framework\TestCase;
use StoragePlatform\SDK\Storage;
use StoragePlatform\StorageEngine\HashedLocalEngine;

class StorageTest extends TestCase
{
    public function testStorageSdkLocalDriver(): void
    {
        $storage = Storage::driver('local');
        $tmp = sys_get_temp_dir() . '/phpunit_test.txt';
        file_put_contents($tmp, 'PHPUnit Object Storage Content');

        $storage->bucket('uploads')->put('phpunit_test.txt', $tmp);
        $this->assertTrue($storage->exists('phpunit_test.txt'));

        $content = $storage->get('phpunit_test.txt');
        $this->assertEquals('PHPUnit Object Storage Content', $content);

        $storage->delete('phpunit_test.txt');
        $this->assertFalse($storage->exists('phpunit_test.txt'));
        @unlink($tmp);
    }

    public function testHashedEngine(): void
    {
        $dir = sys_get_temp_dir() . '/test_hashed_engine_' . bin2hex(random_bytes(4));
        $engine = new HashedLocalEngine($dir);

        $tmp = sys_get_temp_dir() . '/test_h.txt';
        file_put_contents($tmp, 'Hashed Engine Content');

        $engine->write('uploads', 'file.txt', $tmp);
        $this->assertTrue($engine->exists('uploads', 'file.txt'));

        $read = $engine->read('uploads', 'file.txt');
        $this->assertEquals('Hashed Engine Content', $read);

        $engine->delete('uploads', 'file.txt');
        $this->assertFalse($engine->exists('uploads', 'file.txt'));
        @unlink($tmp);
    }
}
