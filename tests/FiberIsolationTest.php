<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;

class FiberIsolationTest extends LogsTestCase
{
    protected function setUp(): void
    {
        if (PHP_VERSION_ID < 80100 || !class_exists(\Fiber::class, false)) {
            $this->markTestSkipped('Fiber requires PHP >= 8.1');
        }
        parent::setUp();
    }

    public function testFiberTraceIdIsolated(): void
    {
        $this->initLogs();
        $mainTrace = Logs::getTraceId();
        $fiberTrace = null;

        $fiber = new \Fiber(function () use (&$fiberTrace): void {
            Logs::init(['base_path' => $this->tmpDir]);
            $fiberTrace = Logs::getTraceId();
            Logs::info('inside fiber');
            Logs::end();
        });
        $fiber->start();

        $this->assertNotNull($fiberTrace);
        $this->assertNotSame($mainTrace, $fiberTrace);
        $this->assertSame($mainTrace, Logs::getTraceId());

        Logs::info('outside fiber');
        $logs = $this->readLogs();
        $this->assertCount(2, $logs);
        $this->assertNotSame($logs[0]['trace_id'], $logs[1]['trace_id']);
        $this->assertSame($fiberTrace, $logs[0]['trace_id']);
        $this->assertSame($mainTrace, $logs[1]['trace_id']);
    }

    public function testFiberFeatIsolated(): void
    {
        $this->initLogs();
        Logs::feat('main-feat');

        $fiber = new \Fiber(function (): void {
            Logs::init(['base_path' => $this->tmpDir]);
            Logs::feat('fiber-feat');
            Logs::info('fiber log');
            Logs::end();
        });
        $fiber->start();

        Logs::info('main log');
        $logs = $this->readLogs();
        $this->assertSame('fiber-feat', $logs[0]['feat']);
        $this->assertSame('main-feat', $logs[1]['feat']);
    }

    public function testUninitializedFiberGetsOwnContextWithNullTrace(): void
    {
        Logs::setBasePath($this->tmpDir);
        Logs::init();
        $mainTrace = Logs::getTraceId();

        $fiber = new \Fiber(function (): void {
            Logs::info('fiber without init');
        });
        $fiber->start();

        $logs = $this->readLogs();
        $this->assertNotNull($mainTrace);
        $this->assertNull($logs[0]['trace_id']);
    }
}
