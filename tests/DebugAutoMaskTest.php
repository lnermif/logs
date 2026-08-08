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
        var_dump(count($logs));
        var_dump($logs[0]);
    }
}