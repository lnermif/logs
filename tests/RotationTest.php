<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;

class RotationTest extends LogsTestCase
{
    public function testFileRotatesWhenSizeExceedsLimit(): void
    {
        Logs::setMaxFileSize(100);
        $this->initLogs();

        $logFile = $this->logFile();
        @mkdir(dirname($logFile), 0755, true);

        file_put_contents($logFile, str_repeat('x', 1000));

        Logs::info('after rotation');

        $logs = $this->readLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('after rotation', $logs[0]['message']);

        $rotated = $this->listRotatedFiles($logFile);
        $this->assertCount(1, $rotated);
    }

    public function testNoRotationWhenFileUnderLimit(): void
    {
        Logs::setMaxFileSize(10000);
        $this->initLogs();

        Logs::info('small log');
        Logs::info('another log');

        $logs = $this->readLogs();
        $this->assertCount(2, $logs);

        $rotated = $this->listRotatedFiles($this->logFile());
        $this->assertEmpty($rotated);
    }

    public function testRotationWithLockFileRecovery(): void
    {
        Logs::setMaxFileSize(100);
        $this->initLogs();

        $logFile = $this->logFile();
        @mkdir(dirname($logFile), 0755, true);

        $lockFile = $logFile . '.rotatelock';
        file_put_contents($lockFile, (string)(time() - 400));

        file_put_contents($logFile, str_repeat('x', 1000));

        Logs::info('after rotation with stale lock');

        $logs = $this->readLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('after rotation with stale lock', $logs[0]['message']);

        $this->assertFileExists($lockFile);
        $this->assertGreaterThan(
            time() - 60,
            (int)file_get_contents($lockFile),
            'Stale lock timestamp should be refreshed after rotation'
        );
    }

    /**
     * @return string[]
     */
    private function listRotatedFiles(string $logFile): array
    {
        $dir = dirname($logFile);
        $base = basename($logFile);
        $all = glob($dir . '/' . $base . '.*');
        if ($all === false) {
            return [];
        }
        return array_values(array_filter($all, function (string $f): bool {
            return substr($f, -11) !== '.rotatelock';
        }));
    }
}
