<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;

use function Nermif\Logs\Tests\Support\trace_bearer_provider;
use function Nermif\Logs\Tests\Support\trace_provider;
use function Nermif\Logs\Tests\Support\trace_recursion_provider;

class ExpandTraceArgsTest extends LogsTestCase
{
    public function testExpandTraceArgsDisabledByDefault(): void
    {
        $this->initLogs();
        try {
            trace_provider('hunter2', ['password' => 'abc'], 3);
        } catch (\Throwable $e) {
            Logs::error($e);
        }
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringContainsString('string(', $trace);
        $this->assertStringNotContainsString("'hunter2'", $trace);
        $this->assertStringNotContainsString("'password'", $trace);
    }

    public function testExpandTraceArgsEnabledShowsArguments(): void
    {
        $this->initLogs(['expand_trace_args' => true]);
        try {
            trace_provider('hunter2', ['username' => 'john_doe'], 3);
        } catch (\Throwable $e) {
            Logs::error($e);
        }
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringContainsString("'hunter2'", $trace);
        $this->assertStringContainsString("'username' => 'john_doe'", $trace);
    }

    public function testExpandTraceArgsSensitiveKeyMasking(): void
    {
        $this->initLogs(['expand_trace_args' => true]);
        try {
            trace_provider('hello', ['password' => 'hunter2', 'secret' => 'abc'], 3);
        } catch (\Throwable $e) {
            Logs::error($e);
        }
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringContainsString('***(masked)', $trace);
        $this->assertStringNotContainsString('hunter2', $trace);
        $this->assertStringNotContainsString('abc', $trace);
    }

    public function testExpandTraceArgsJwtNotExpandedByDefault(): void
    {
        $this->initLogs();
        try {
            trace_bearer_provider('Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0.abc');
        } catch (\Throwable $e) {
            Logs::error($e);
        }
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringContainsString('string(', $trace);
        $this->assertStringNotContainsString('Bearer', $trace);
        $this->assertStringNotContainsString('eyJ', $trace);
    }

    public function testExpandTraceArgsJwtExpandedWhenEnabled(): void
    {
        $this->initLogs(['expand_trace_args' => true]);
        try {
            trace_bearer_provider('Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0.abc');
        } catch (\Throwable $e) {
            Logs::error($e);
        }
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringContainsString('***(masked string', $trace);
        $this->assertStringNotContainsString('Bearer', $trace);
        $this->assertStringNotContainsString('eyJ', $trace);
    }

    public function testExpandTraceArgsComplexNestedStructure(): void
    {
        $this->initLogs(['expand_trace_args' => true]);
        try {
            trace_provider('hello', [
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
            ], 3);
        } catch (\Throwable $e) {
            Logs::error($e);
        }
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
        try {
            trace_provider('hello', range(1, 20), 3);
        } catch (\Throwable $e) {
            Logs::error($e);
        }
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringContainsString('…(15 more)', $trace);
        $this->assertStringNotContainsString("'9'", $trace);
    }

    public function testExpandTraceArgsRecursionGuarded(): void
    {
        $this->initLogs(['expand_trace_args' => true]);
        try {
            $obj = new \stdClass();
            trace_recursion_provider($obj, $obj);
        } catch (\Throwable $e) {
            Logs::error($e);
        }
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringContainsString('[RECURSION]', $trace);
    }
}