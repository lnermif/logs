<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;
use Nermif\Logs\Tests\Support\SimpleStringable;
use Nermif\Logs\Tests\Support\ThrowingStringable;

class ExpandTraceArgsTest extends LogsTestCase
{
    public function testExpandTraceArgsDisabledByDefault(): void
    {
        $this->initLogs();
        Logs::error(null, new \RuntimeException('test error'));
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringNotContainsString("'password'", $trace);
        $this->assertStringNotContainsString("'secret'", $trace);
    }

    public function testExpandTraceArgsEnabledShowsArguments(): void
    {
        $this->initLogs(['expand_trace_args' => true]);
        Logs::error(null, new \RuntimeException('test error', 1), ['password' => 'hunter2', 'secret' => 'abc']);
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringContainsString("'password' => 'hunter2'", $trace);
        $this->assertStringContainsString("'secret' => 'abc'", $trace);
    }

    public function testExpandTraceArgsSensitiveKeyMasking(): void
    {
        $this->initLogs(['expand_trace_args' => true]);
        Logs::error(null, new \RuntimeException('test error', 1), ['password' => 'hunter2', 'secret' => 'abc']);
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringContainsString('***(masked)', $trace);
        $this->assertStringNotContainsString('hunter2', $trace);
        $this->assertStringNotContainsString('abc', $trace);
    }

    public function testExpandTraceArgsJwtNotExpandedByDefault(): void
    {
        $this->initLogs();
        Logs::error(null, new \RuntimeException('test error'), ['authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0.abc']);
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringNotContainsString('Bearer', $trace);
        $this->assertStringNotContainsString('eyJ', $trace);
    }

    public function testExpandTraceArgsJwtExpandedWhenEnabled(): void
    {
        $this->initLogs(['expand_trace_args' => true]);
        Logs::error(null, new \RuntimeException('test error'), ['authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0.abc']);
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringContainsString('***(masked string', $trace);
        $this->assertStringNotContainsString('Bearer', $trace);
        $this->assertStringNotContainsString('eyJ', $trace);
    }

    public function testExpandTraceArgsComplexNestedStructure(): void
    {
        $this->initLogs(['expand_trace_args' => true]);
        Logs::error(null, new \RuntimeException('test error'), [
            'user' => [
                'id' => 123,
                'username' => 'john_doe',
                'tokens' => [
                    'access' => 'abc123',
                    'refresh' => 'def456',
                    'sensitive_api_key' => 'super_secret_key_789'
                ]
            ],
            'request' => [
                'method' => 'POST',
                'path' => '/api/users',
                'headers' => [
                    'content-type' => 'application/json',
                    'authorization' => 'Bearer xyz789'
                ]
            ]
        ]);
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        
        // Check that sensitive keys are masked
        $this->assertStringContainsString('***(masked)', $trace);
        $this->assertStringNotContainsString('super_secret_key_789', $trace);
        $this->assertStringNotContainsString('xyz789', $trace);
        
        // Check that non-sensitive data is shown
        $this->assertStringContainsString('123', $trace);
        $this->assertStringContainsString('john_doe', $trace);
        $this->assertStringContainsString('POST', $trace);
        $this->assertStringContainsString('/api/users', $trace);
    }

    public function testExpandTraceArgsDepthAndArityLimited(): void
    {
        $this->initLogs(['expand_trace_args' => true]);
        Logs::error(null, new \RuntimeException('test error'), ['args' => range(1, 30)]);
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringContainsString('…(15 more)', $trace);
        $this->assertStringNotContainsString("'9'", $trace);
    }

    public function testExpandTraceArgsRecursionGuarded(): void
    {
        $this->initLogs(['expand_trace_args' => true]);
        Logs::error(null, new \RuntimeException('test error', 1));
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringContainsString('[RECURSION]', $trace);
    }
}