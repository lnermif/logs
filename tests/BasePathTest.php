<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;

class BasePathTest extends LogsTestCase
{
    public function testAcceptsExistingWritableDirectory(): void
    {
        Logs::setBasePath($this->tmpDir);
        $prop = new \ReflectionProperty(Logs::class, 'basePath');
        if (PHP_VERSION_ID < 80100) {
            $prop->setAccessible(true);
        }
        $this->assertSame(realpath($this->tmpDir), $prop->getValue(null));
    }

    public function testCreatesMissingDirectory(): void
    {
        $target = $this->tmpDir . '/not-yet-created/nested';
        Logs::setBasePath($target);
        $this->assertDirectoryExists($target);
    }

    public function testNormalizesTrailingSlash(): void
    {
        Logs::setBasePath($this->tmpDir . '/');
        $prop = new \ReflectionProperty(Logs::class, 'basePath');
        if (PHP_VERSION_ID < 80100) {
            $prop->setAccessible(true);
        }
        $this->assertSame(rtrim(realpath($this->tmpDir), '/\\'), $prop->getValue(null));
    }

    public function testRejectsEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Logs::setBasePath('');
    }

    public function testRejectsFilePath(): void
    {
        $file = $this->tmpDir . '/not-a-dir.txt';
        file_put_contents($file, 'x');
        $this->expectException(\InvalidArgumentException::class);
        Logs::setBasePath($file);
    }

    public function testRejectsNonWritableDirectory(): void
    {
        $target = $this->tmpDir . '/readonly';
        mkdir($target, 0755, true);
        chmod($target, 0555);
        if (is_writable($target)) {
            chmod($target, 0755);
            $this->markTestSkipped('Running as root, cannot simulate non-writable directory');
        }
        try {
            $this->expectException(\InvalidArgumentException::class);
            Logs::setBasePath($target);
        } finally {
            chmod($target, 0755);
        }
    }

    public function testWritesLogIntoConfiguredBasePath(): void
    {
        Logs::setBasePath($this->tmpDir);
        Logs::init();
        Logs::info('hello base path');
        $this->assertFileExists($this->logFile());
    }
}
