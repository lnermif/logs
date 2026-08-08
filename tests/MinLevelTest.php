<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;

class MinLevelTest extends LogsTestCase
{
    public function testSetMinLevelWithStringEnum(): void
    {
        $this->initLogs();
        Logs::setMinLevel('debug');
        Logs::debug('debug message');
        Logs::info('info message');
        $logs = $this->readLogs();
        $this->assertSame(1, $logs[0]['level'] === 'DEBUG' ? 1 : 0);
        $this->assertSame(2, $logs[1]['level'] === 'INFO' ? 1 : 0);
    }

    public function testSetMinLevelWithInteger(): void
    {
        $this->initLogs();
        Logs::setMinLevel(300); // WARNING level
        Logs::debug('debug message');
        Logs::info('info message');
        Logs::warning('warning message');
        Logs::error('error message');
        $logs = $this->readLogs();
        $this->assertCount(2, $logs); // Should have 2 logs (WARNING and ERROR)
        $this->assertSame('WARNING', $logs[0]['level']);
        $this->assertSame('ERROR', $logs[1]['level']);
    }

    public function testSetMinLevelWithNumericString(): void
    {
        $this->initLogs();
        Logs::setMinLevel('100'); // DEBUG level
        Logs::debug('debug message');
        $logs = $this->readLogs();
        $this->assertSame(1, $logs[0]['level'] === 'DEBUG' ? 1 : 0); // Should pass
    }

    public function testSetMinLevelInvalidThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Logs::setMinLevel('invalid_level');
    }

    public function testSetMinLevelZeroIsValid(): void
    {
        $this->initLogs();
        Logs::setMinLevel(0);
        Logs::debug('debug message');
        $logs = $this->readLogs();
        $this->assertSame(1, $logs[0]['level'] === 'DEBUG' ? 1 : 0); // Should pass
    }
}