<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;
use Nermif\Logs\Tests\Support\HighEntropyException;

class DebugAutoMaskTest extends LogsTestCase
{
    public function testDebugExceptionStructure(): void
    {
        $this->initLogs();
        Logs::error(null, new HighEntropyException());
        $logs = $this->readLogs();
        $this->assertCount(1, $logs);
        $this->assertSame(HighEntropyException::class, $logs[0]['context']['exception']['class']);
        $this->assertArrayHasKey('extra', $logs[0]['context']['exception']);
        $this->assertArrayHasKey('payload', $logs[0]['context']['exception']['extra']);
        $this->assertStringStartsWith('X9kQz2m', $logs[0]['context']['exception']['extra']['payload']);
    }
}