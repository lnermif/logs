<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;
use Nermif\Logs\Tests\Support\HighEntropyException;

class DebugExceptionTest extends LogsTestCase
{
    public function testExceptionLogStructure(): void
    {
        $this->initLogs();
        $exception = new HighEntropyException();
        Logs::error($exception);

        $logs = $this->readLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('ERROR', $logs[0]['level']);
        $this->assertSame(HighEntropyException::class, $logs[0]['context']['exception']['class']);
        $this->assertArrayHasKey('trace', $logs[0]['context']['exception']);
        $this->assertArrayHasKey('extra', $logs[0]['context']['exception']);
        $this->assertArrayHasKey('payload', $logs[0]['context']['exception']['extra']);
    }
}