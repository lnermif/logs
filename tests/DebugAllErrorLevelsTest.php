<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;

class DebugAllErrorLevelsTest extends LogsTestCase
{
    public function testEmergencyLevelDebug(): void
    {
        $this->initLogs(['min_level' => 500]); // Set min level to CRITICAL

        Logs::emergency('emergency message');
        $logs = $this->readLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('EMERGENCY', $logs[0]['level']);
        $this->assertSame('emergency message', $logs[0]['message']);
    }
}