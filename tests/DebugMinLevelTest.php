<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;

class DebugMinLevelTest extends LogsTestCase
{
    public function testSetMinLevelWithIntegerDebug(): void
    {
        $this->initLogs();
        Logs::setMinLevel(300); // WARNING level
        Logs::debug('debug message');
        Logs::info('info message');
        $logs = $this->readLogs();
        var_dump(count($logs));
        var_dump($logs);
    }
}