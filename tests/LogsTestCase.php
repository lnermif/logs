<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;
use PHPUnit\Framework\TestCase;

abstract class LogsTestCase extends TestCase
{
    protected string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/logs-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0755, true);
        self::resetLogsState();
    }

    protected function tearDown(): void
    {
        Logs::end();
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    protected function initLogs(array $config = []): void
    {
        Logs::init(array_merge(['base_path' => $this->tmpDir], $config));
    }

    protected function logFile(): string
    {
        return $this->tmpDir . '/' . date('Ym') . '/' . date('Ymd') . '.log';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function readLogs(): array
    {
        $file = $this->logFile();
        if (!is_file($file)) {
            return [];
        }
        $lines = array_filter(file($file, FILE_IGNORE_NEW_LINES));
        return array_map(static function (string $line): array {
            $decoded = json_decode($line, true);
            return is_array($decoded) ? $decoded : [];
        }, $lines);
    }

    private static function resetLogsState(): void
    {
        $ref = new \ReflectionClass(Logs::class);
        $defaults = $ref->getDefaultProperties();
        $names = [
            'contexts',
            'basePath',
            'minLevel',
            'gcTtl',
            'sensitiveKeys',
            'sanitizeMessage',
            'expandTraceArgs',
            'autoMaskHighEntropyStrings',
            'sensitiveKeyMatchMode',
            'maxFileSize',
            'lastGcTime',
            'customWriter',
        ];
        foreach ($names as $name) {
            $prop = $ref->getProperty($name);
            if (PHP_VERSION_ID < 80100) {
                $prop->setAccessible(true);
            }
            $prop->setValue(null, $defaults[$name]);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
