<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;

/**
 * 覆盖关键指标统计：默认关闭、configure() 开启、写入延迟、上下文数量（含峰值）、
 * 成功轮转与轮转锁失败计数、指标重置。
 */
class MetricsTest extends LogsTestCase
{
    public function testMetricsDisabledByDefault(): void
    {
        $metrics = Logs::getMetrics();
        $this->assertFalse($metrics['enabled']);
        $this->assertSame(0, $metrics['write']['count']);
        $this->assertSame(0.0, $metrics['write']['total_dur_us']);
        $this->assertSame(0, $metrics['rotation']['count']);
        $this->assertSame(0, $metrics['rotation']['lock_failures']);
        $this->assertSame(0, $metrics['since']);
    }

    public function testConfigureEnablesMetrics(): void
    {
        Logs::configure(['base_path' => $this->tmpDir, 'enable_metrics' => true]);
        $this->assertTrue(Logs::getMetrics()['enabled']);
    }

    public function testWriteLatencyRecorded(): void
    {
        Logs::setMetricsEnabled(true);
        $this->initLogs();

        Logs::setCustomWriter(static function (string $file, string $content): void {
            usleep(1000);
        });

        Logs::info('one');
        Logs::info('two');

        $metrics = Logs::getMetrics();
        $this->assertSame(2, $metrics['write']['count']);
        $this->assertGreaterThan(0, $metrics['write']['total_dur_us']);
        $this->assertGreaterThan(0, $metrics['write']['avg_dur_us']);
    }

    public function testContextPeakAndCurrentTracked(): void
    {
        if (PHP_VERSION_ID < 80100 || !class_exists(\Fiber::class, false)) {
            $this->markTestSkipped('Fiber requires PHP >= 8.1');
        }
        Logs::setMetricsEnabled(true);
        $this->initLogs();

        $fiber = new \Fiber(function (): void {
            Logs::init(['base_path' => $this->tmpDir]);
            \Fiber::suspend();
        });
        $fiber->start();

        $metrics = Logs::getMetrics();
        $this->assertSame(2, $metrics['contexts']['current']);
        $this->assertGreaterThanOrEqual(2, $metrics['contexts']['peak']);

        $fiber->resume();
    }

    public function testRotationCountIncrementsOnRotate(): void
    {
        Logs::setMetricsEnabled(true);
        Logs::setMaxFileSize(100);
        $this->initLogs();

        $logFile = $this->logFile();
        @mkdir(dirname($logFile), 0755, true);
        file_put_contents($logFile, str_repeat('x', 1000));

        Logs::info('after rotation');

        $metrics = Logs::getMetrics();
        $this->assertSame(1, $metrics['rotation']['count']);
        $this->assertSame(0, $metrics['rotation']['lock_failures']);
    }

    public function testRotationLockFailureIncrements(): void
    {
        Logs::setMetricsEnabled(true);
        Logs::setMaxFileSize(100);
        $this->initLogs();

        $logFile = $this->logFile();
        @mkdir(dirname($logFile), 0755, true);
        file_put_contents($logFile, str_repeat('x', 1000));
        mkdir($logFile . '.rotatelock', 0755, true);

        Logs::info('lock fail');

        $metrics = Logs::getMetrics();
        $this->assertSame(0, $metrics['rotation']['count']);
        $this->assertSame(1, $metrics['rotation']['lock_failures']);
    }

    public function testResetMetricsClearsCounters(): void
    {
        Logs::setMetricsEnabled(true);
        $this->initLogs();
        Logs::info('one');

        Logs::resetMetrics();

        $metrics = Logs::getMetrics();
        $this->assertTrue($metrics['enabled']);
        $this->assertSame(0, $metrics['write']['count']);
        $this->assertGreaterThan(0, $metrics['since']);
    }
}
