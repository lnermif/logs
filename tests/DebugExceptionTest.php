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
        var_dump(count($logs));
        var_dump($logs);
    }
}