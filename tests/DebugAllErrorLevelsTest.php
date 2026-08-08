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
        var_dump(count($logs));
        var_dump($logs[0] ?? 'no logs');
    }
}