<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;

class DebugContextTest extends LogsTestCase
{
    public function testContextBeforeAndAfterInit(): void
    {
        // Check context before init
        $contextBefore = Logs::context();
        $this->assertArrayHasKey('trace_id', $contextBefore);
        $this->assertArrayHasKey('feat', $contextBefore);
        $this->assertArrayHasKey('created_at', $contextBefore);
        $this->assertNull($contextBefore['trace_id']);
        $this->assertNull($contextBefore['feat']);
        $this->assertNull($contextBefore['created_at']);
        
        // Initialize logs
        $this->initLogs();
        
        // Check context after init
        $contextAfter = Logs::context();
        $this->assertArrayHasKey('trace_id', $contextAfter);
        $this->assertArrayHasKey('feat', $contextAfter);
        $this->assertArrayHasKey('created_at', $contextAfter);
        $this->assertNotNull($contextAfter['trace_id']);
        $this->assertNull($contextAfter['feat']);
        $this->assertGreaterThan(0, $contextAfter['created_at']);
    }
}