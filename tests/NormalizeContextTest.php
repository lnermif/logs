<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;
use Nermif\Logs\Tests\Support\SimpleStringable;
use Nermif\Logs\Tests\Support\ThrowingStringable;

class NormalizeContextTest extends LogsTestCase
{
    public function testToStringObjectSerialized(): void
    {
        $this->initLogs();
        Logs::info('obj', ['str' => new SimpleStringable()]);
        $logs = $this->readLogs();
        $this->assertSame('hello-stringable', $logs[0]['context']['str']);
    }

    public function testThrowingToStringObjectFallback(): void
    {
        $this->initLogs();
        Logs::info('obj', ['str' => new ThrowingStringable()]);
        $logs = $this->readLogs();
        $this->assertSame(
            'object(' . ThrowingStringable::class . ') (__toString exception)',
            $logs[0]['context']['str']
        );
    }

    public function testJsonSerializableArray(): void
    {
        $this->initLogs();
        Logs::info('json', ['data' => new class implements \JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['a' => 1, 'b' => 2];
            }
        }]);
        $logs = $this->readLogs();
        $this->assertSame(['a' => 1, 'b' => 2], $logs[0]['context']['data']);
    }

    public function testJsonSerializableScalarWrapped(): void
    {
        $this->initLogs();
        Logs::info('json', ['data' => new class implements \JsonSerializable {
            public function jsonSerialize(): string
            {
                return 'scalar-value';
            }
        }]);
        $logs = $this->readLogs();
        $this->assertSame('string', $logs[0]['context']['data']['type']);
        $this->assertSame('scalar-value', $logs[0]['context']['data']['value']);
    }

    public function testPlainObjectShowsClassName(): void
    {
        $this->initLogs();
        Logs::info('obj', ['o' => new \stdClass()]);
        $logs = $this->readLogs();
        $this->assertSame('object(stdClass)', $logs[0]['context']['o']);
    }

    public function testResourceSerialized(): void
    {
        $this->initLogs();
        $handle = fopen($this->tmpDir . '/res.txt', 'w');
        try {
            Logs::info('res', ['r' => $handle]);
            $logs = $this->readLogs();
            $this->assertSame('resource(stream)', $logs[0]['context']['r']);
        } finally {
            fclose($handle);
        }
    }

    public function testLongStringTruncated(): void
    {
        $this->initLogs();
        Logs::info('long', ['data' => str_repeat('a', 9000)]);
        $logs = $this->readLogs();
        $value = $logs[0]['context']['data'];
        $this->assertLessThan(9000, strlen($value));
        $this->assertStringEndsWith('...(truncated)', $value);
    }

    public function testNestedDepthLimited(): void
    {
        $this->initLogs();
        $value = 'leaf';
        for ($i = 0; $i < 7; $i++) {
            $value = [$value];
        }
        Logs::info('deep', ['tree' => $value]);
        $logs = $this->readLogs();
        $this->assertStringContainsString('...(truncated)', json_encode($logs[0]['context'], JSON_UNESCAPED_SLASHES));
        $this->assertStringContainsString('max depth', json_encode($logs[0]['context'], JSON_UNESCAPED_SLASHES));
    }

    public function testSelfReferencingArrayTerminates(): void
    {
        $this->initLogs();
        $value = ['key' => 'value'];
        $value['self'] = &$value;
        Logs::info('recursion', ['arr' => $value]);
        $logs = $this->readLogs();
        $encoded = json_encode($logs[0]['context'], JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('max depth', $encoded);
    }

    public function testScalarValuesPassThrough(): void
    {
        $this->initLogs();
        Logs::info('mixed', ['int' => 42, 'float' => 1.5, 'bool' => true, 'null' => null]);
        $logs = $this->readLogs();
        $this->assertSame(42, $logs[0]['context']['int']);
        $this->assertSame(1.5, $logs[0]['context']['float']);
        $this->assertSame(true, $logs[0]['context']['bool']);
        $this->assertNull($logs[0]['context']['null']);
    }
}
