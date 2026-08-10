<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;
use PHPUnit\Framework\TestCase;

/**
 * 覆盖 write()/buildLogFilePath()/writeFile()/轮转逻辑中的失败与边界分支。
 */
class WriteFailureTest extends LogsTestCase
{
    private function redirectErrorLog(): string
    {
        $path = $this->tmpDir . '/err.log';
        ini_set('error_log', $path);
        return $path;
    }

    public function testMkdirFailureLogsWriteError(): void
    {
        Logs::setBasePath($this->tmpDir);
        $errorFile = $this->redirectErrorLog();

        // 用文件占位当月目录名，令 mkdir 失败，触发 buildLogFilePath 抛出
        file_put_contents($this->tmpDir . '/' . date('Ym'), 'blocks dir');

        $mask = error_reporting(E_ALL & ~E_WARNING);
        try {
            Logs::info('mkdir failure' . bin2hex(random_bytes(2)));
        } finally {
            error_reporting($mask);
        }

        $logged = (string) @file_get_contents($errorFile);
        $this->assertStringContainsString('Log write failed', $logged);
        $this->assertFileDoesNotExist($this->logFile());
    }

    public function testJsonDoubleEncodeFailureWritesObject(): void
    {
        Logs::setSanitizeMessage(false);
        $this->initLogs(['min_level' => 'debug']);

        // 消息与上下文均含非法 UTF-8，两次 json_encode 都失败 → 写入 '{}'
        Logs::info("\xC3\x28", ["\x28\xAA" => "\xFF\xFE"]);

        $raw = (string) file_get_contents($this->logFile());
        $lines = array_values(array_filter(explode("\n", $raw)));
        $this->assertNotEmpty($lines);
        $this->assertSame('{}', end($lines));
    }

    public function testWriteFileThrowsOnDirectoryTarget(): void
    {
        $dir = $this->tmpDir . '/dir-target';
        mkdir($dir, 0755, true);

        $ref = new \ReflectionMethod(Logs::class, 'writeFile');
        if (PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }

        try {
            @$ref->invoke(null, $dir, 'content');
            $this->fail('Expected RuntimeException for directory target');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Unable to write log file', $e->getMessage());
        }
    }

    public function testRotationCollisionAppendsRandomSuffix(): void
    {
        Logs::setMaxFileSize(100);
        $this->initLogs();

        $logFile = $this->logFile();
        @mkdir(dirname($logFile), 0755, true);
        file_put_contents($logFile, str_repeat('x', 1000));

        $collision = $logFile . '.' . date('YmdHis');
        file_put_contents($collision, 'occupied');

        Logs::info('rotate collision');

        $rotated = glob($collision . '.*');
        $this->assertIsArray($rotated);
        $this->assertNotEmpty($rotated);
        $this->assertStringContainsString('rotate collision', (string) file_get_contents($logFile));
    }

    public function testRotationRenameFailureLogged(): void
    {
        Logs::setMaxFileSize(100);
        $this->initLogs();
        $errorFile = $this->redirectErrorLog();

        $logFile = $this->logFile();
        @mkdir(dirname($logFile), 0755, true);
        file_put_contents($logFile, str_repeat('x', 1000));

        // 目标轮转路径已存在同名目录，rename 必失败
        mkdir($logFile . '.' . date('YmdHis'), 0755, true);

        Logs::info('rename fail');

        $logged = (string) @file_get_contents($errorFile);
        $this->assertStringContainsString('Log rotation failed', $logged);
    }

    public function testRotationLockFailureLogged(): void
    {
        Logs::setMaxFileSize(100);
        $this->initLogs();
        $errorFile = $this->redirectErrorLog();

        $logFile = $this->logFile();
        @mkdir(dirname($logFile), 0755, true);
        file_put_contents($logFile, str_repeat('x', 1000));

        // 锁文件路径为目录，fopen('c+') 失败 → 旋转锁获取失败
        mkdir($logFile . '.rotatelock', 0755, true);

        Logs::info('lock fail');

        $logged = (string) @file_get_contents($errorFile);
        $this->assertStringContainsString('Log rotation lock failed', $logged);
    }
}