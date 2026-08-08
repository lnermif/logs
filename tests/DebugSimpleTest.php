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
        var_dump(count($logs));
        var_dump($logs[0] ?? 'no logs');
    }
}