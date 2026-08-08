<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;

use function Nermif\Logs\Tests\Support\trace_bearer_provider;
use function Nermif\Logs\Tests\Support\trace_provider;
use function Nermif\Logs\Tests\Support\trace_recursion_provider;

class ExceptionTraceTest extends LogsTestCase
{
    public function testTraceContainsFileAndFunction(): void
    {
        $this->initLogs();
        try {
            trace_provider('hello', ['a' => 1], 3);
        } catch (\Throwable $e) {
            Logs::error($e);
        }
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringContainsString('trace_provider', $trace);
        $this->assertStringContainsString('ExceptionTraceTest.php', $trace);
        $this->assertMatchesRegularExpression('/^#0\s/m', $trace);
    }

    public function testTraceArgsShowTypesByDefault(): void
    {
        $this->initLogs();
        try {
            trace_provider('hello', ['password' => 'x'], 3);
        } catch (\Throwable $e) {
            Logs::error($e);
        }
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringContainsString('string(5)', $trace);
        $this->assertStringNotContainsString("'hello'", $trace);
    }

    public function testTraceArgsExpandedWithValues(): void
    {
        $this->initLogs(['expand_trace_args' => true]);
        try {
            trace_provider('hello', ['a' => 1], 3);
        } catch (\Throwable $e) {
            Logs::error($e);
        }
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringContainsString("'hello'", $trace);
        $this->assertStringContainsString("'a' => 1", $trace);
        $this->assertStringContainsString(', 3)', $trace);
    }

    public function testTraceArgsSensitiveKeyMasked(): void
    {
        $this->initLogs(['expand_trace_args' => true]);
        try {
            trace_provider('hello', ['password' => 'hunter2'], 3);
        } catch (\Throwable $e) {
            Logs::error($e);
        }
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringContainsString('***(masked)', $trace);
        $this->assertStringNotContainsString('hunter2', $trace);
    }

    public function testTraceArgsBearerTokenMasked(): void
    {
        $this->initLogs(['expand_trace_args' => true]);
        try {
            trace_bearer_provider('Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0.signature');
        } catch (\Throwable $e) {
            Logs::error($e);
        }
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringContainsString('***(masked string', $trace);
        $this->assertStringNotContainsString('Bearer eyJ', $trace);
    }

    public function testExpandedTraceArgDepthAndArityLimited(): void
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

    public function testExpandedTraceArgRecursionGuarded(): void
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

    public function testJwtNotExpandedInTraceByDefault(): void
    {
        $this->initLogs();
        try {
            trace_bearer_provider('eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0.abc');
        } catch (\Throwable $e) {
            Logs::error($e);
        }
        $logs = $this->readLogs();
        $trace = $logs[0]['context']['exception']['trace'];
        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $trace);
        $this->assertStringContainsString('string(', $trace);
    }
}
