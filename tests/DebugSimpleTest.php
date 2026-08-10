<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;

class DebugSimpleTest extends LogsTestCase
{
    public function testSimpleLog(): void
    {
        $this->initLogs();
        Logs::info('simple test');
        $logs = $this->readLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('INFO', $logs[0]['level']);
        $this->assertSame('simple test', $logs[0]['message']);
    }
}