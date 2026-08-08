<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;
use Nermif\Logs\Tests\Support\CustomException;

class LogWriteTest extends LogsTestCase
{
    public function testWritesJsonLineWithAllFields(): void
    {
        $this->initLogs();
        Logs::feat('order');
        Logs::info('order created', ['id' => 1001]);

        $logs = $this->readLogs();
        $this->assertCount(1, $logs);
        $entry = $logs[0];
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $entry['datetime']);
        $this->assertSame('INFO', $entry['level']);
        $this->assertSame('order created', $entry['message']);
        $this->assertSame('order', $entry['feat']);
        $this->assertSame(1001, $entry['context']['id']);
        $this->assertSame(Logs::getTraceId(), $entry['trace_id']);
    }

    public function testTraceIdNullWithoutInit(): void
    {
        Logs::setBasePath($this->tmpDir);
        Logs::info('no init');
        $logs = $this->readLogs();
        $this->assertNull($logs[0]['trace_id']);
        $this->assertNull($logs[0]['feat']);
    }

    public function testMinLevelFiltersDebugByDefault(): void
    {
        $this->initLogs();
        Logs::debug('hidden');
        Logs::info('visible');
        $logs = $this->readLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('INFO', $logs[0]['level']);
        $this->assertSame('visible', $logs[0]['message']);
    }

    public function testSetMinLevelAllowsDebug(): void
    {
        $this->initLogs(['min_level' => 'debug']);
        Logs::debug('now visible');
        $logs = $this->readLogs();
        $this->assertSame('DEBUG', $logs[0]['level']);
    }

    public function testSetMinLevelAcceptsNumericString(): void
    {
        Logs::setMinLevel('100');
        Logs::setBasePath($this->tmpDir);
        Logs::init();
        Logs::debug('numeric level');
        $logs = $this->readLogs();
        $this->assertCount(1, $logs);
    }

    public function testUnknownLevelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Logs::setMinLevel('bogus');
    }

    public function testEndClearsContext(): void
    {
        $this->initLogs();
        $traceBefore = Logs::getTraceId();
        $this->assertNotNull($traceBefore);
        Logs::end();
        $this->assertNull(Logs::getTraceId());
    }

    public function testExceptionAsMessage(): void
    {
        $this->initLogs();
        $e = new \RuntimeException('payment failed', 42);
        Logs::error($e);

        $logs = $this->readLogs();
        $this->assertSame('payment failed', $logs[0]['message']);
        $ctx = $logs[0]['context'];
        $this->assertSame(\RuntimeException::class, $ctx['exception']['class']);
        $this->assertSame(42, $ctx['exception']['code']);
        $this->assertIsString($ctx['exception']['trace']);
    }

    public function testExceptionAsContext(): void
    {
        $this->initLogs();
        Logs::error('custom message', new \RuntimeException('boom'));
        $logs = $this->readLogs();
        $this->assertSame('custom message', $logs[0]['message']);
        $this->assertSame(\RuntimeException::class, $logs[0]['context']['exception']['class']);
    }

    public function testContextArrayWithException(): void
    {
        $this->initLogs();
        Logs::error('multi', ['a' => 1, 'exception' => new \RuntimeException('inner')]);
        $logs = $this->readLogs();
        $this->assertSame(1, $logs[0]['context']['a']);
        $this->assertSame('inner', $logs[0]['context']['exception']['message']);
    }

    public function testExceptionExtraPropertiesExtracted(): void
    {
        $this->initLogs();
        Logs::error(new CustomException('custom', 7));
        $logs = $this->readLogs();
        $extra = $logs[0]['context']['exception']['extra'];
        $this->assertSame(42, $extra['userId']);
        $this->assertSame('***', $extra['pas***']);
        $this->assertSame('***(sensitive string)', $extra['opaque']);
    }

    public function testSanitizeMessageRemovesControlChars(): void
    {
        $this->initLogs();
        Logs::info("bad\x00message\r\nnext line");
        $logs = $this->readLogs();
        $this->assertStringNotContainsString("\x00", $logs[0]['message']);
        $this->assertStringContainsString("message\nnext line", $logs[0]['message']);
    }

    public function testSanitizeCanBeDisabled(): void
    {
        $this->initLogs(['sanitize_message' => false]);
        Logs::info("keep\x00null");
        $logs = $this->readLogs();
        $this->assertStringContainsString("\x00", $logs[0]['message']);
    }

    public function testFeatFiltersPathSeparators(): void
    {
        $this->initLogs();
        Logs::feat('a/b\\c');
        Logs::info('feat sanitized');
        $logs = $this->readLogs();
        $this->assertSame('a_b_c', $logs[0]['feat']);
    }

    public function testFeatNullClearsFeat(): void
    {
        $this->initLogs();
        Logs::feat('order');
        Logs::feat(null);
        Logs::info('feat cleared');
        $logs = $this->readLogs();
        $this->assertNull($logs[0]['feat']);
    }

    public function testLevelNamesMappedForAllLevels(): void
    {
        $this->initLogs(['min_level' => 100]);
        Logs::debug('d');
        Logs::info('i');
        Logs::notice('n');
        Logs::warning('w');
        Logs::error('e');
        Logs::critical('c');
        Logs::alert('a');
        Logs::emergency('m');
        $logs = $this->readLogs();
        $this->assertSame(['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], array_column($logs, 'level'));
    }

    public function testSetMinLevelAcceptsInt(): void
    {
        Logs::setMinLevel(500);
        Logs::setBasePath($this->tmpDir);
        Logs::init();
        Logs::warning('filtered');
        Logs::critical('kept');
        $logs = $this->readLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('CRITICAL', $logs[0]['level']);
    }

    public function testNonStringMessageCoerced(): void
    {
        $this->initLogs();
        Logs::info(12345);
        $logs = $this->readLogs();
        $this->assertSame('12345', $logs[0]['message']);
    }

    public function testJsonEncodeFailureFallsBackToJsonError(): void
    {
        $this->initLogs();
        Logs::info('valid message', ['bad' => "\xB1\x31"]);
        $logs = $this->readLogs();
        $this->assertArrayHasKey('json_error', $logs[0]['context']);
        $this->assertIsString($logs[0]['context']['json_error']);
        $this->assertSame('valid message', $logs[0]['message']);
    }

    public function testCustomWriterReceivesLogPathAndContent(): void
    {
        $this->initLogs();
        $writes = [];
        Logs::setCustomWriter(static function (string $file, string $content) use (&$writes): void {
            $writes[] = [$file, $content];
        });

        Logs::info('custom writer');

        $this->assertCount(1, $writes);
        $this->assertSame(realpath($this->tmpDir) . '/' . date('Ym') . '/' . date('Ymd') . '.log', $writes[0][0]);
        $this->assertSame('custom writer', json_decode(trim($writes[0][1]), true)['message']);
        $this->assertFileDoesNotExist($this->logFile());
    }

    public function testEndRequestAliasClearsContext(): void
    {
        $this->initLogs();
        $this->assertNotNull(Logs::getTraceId());
        Logs::endRequest();
        $this->assertNull(Logs::getTraceId());
    }

    public function testWakeupThrows(): void
    {
        $instance = (new \ReflectionClass(Logs::class))->newInstanceWithoutConstructor();
        $this->expectException(\RuntimeException::class);
        $instance->__wakeup();
    }
}
