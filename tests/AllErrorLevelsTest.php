<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;

class AllErrorLevelsTest extends LogsTestCase
{
    public function testAllErrorLevelsAreMappedCorrectly(): void
    {
        $this->initLogs(['min_level' => 100]); // Set min level to DEBUG
        
        // Test all levels
        Logs::debug('debug message');
        Logs::info('info message');
        Logs::notice('notice message');
        Logs::warning('warning message');
        Logs::error('error message');
        Logs::critical('critical message');
        Logs::alert('alert message');
        Logs::emergency('emergency message');
        
        $logs = $this->readLogs();
        $this->assertCount(8, $logs); // Should have 8 log entries
        
        $levels = array_column($logs, 'level');
        $this->assertContains('DEBUG', $levels);
        $this->assertContains('INFO', $levels);
        $this->assertContains('NOTICE', $levels);
        $this->assertContains('WARNING', $levels);
        $this->assertContains('ERROR', $levels);
        $this->assertContains('CRITICAL', $levels);
        $this->assertContains('ALERT', $levels);
        $this->assertContains('EMERGENCY', $levels);
    }

    public function testEmergencyLevelIsHighest(): void
    {
        $this->initLogs(['min_level' => 500]); // Set min level to CRITICAL
        
        Logs::emergency('emergency message');
        $logs = $this->readLogs();
        $this->assertCount(1, $logs); // Should only have emergency log
        $this->assertSame('EMERGENCY', $logs[0]['level']);
    }

    public function testDebugLevelRespectsMinLevel(): void
    {
        $this->initLogs(['min_level' => 200]); // Set min level to INFO
        Logs::debug('debug message');
        $logs = $this->readLogs();
        $this->assertCount(0, $logs); // Debug should be filtered out
    }

    public function testEmergencyLevelRespectsMinLevel(): void
    {
        $this->initLogs(['min_level' => 600]); // Set min level to EMERGENCY
        Logs::emergency('emergency message');
        $logs = $this->readLogs();
        $this->assertCount(1, $logs); // Emergency should be logged
        $this->assertSame('EMERGENCY', $logs[0]['level']);
    }
}