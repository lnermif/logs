<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;
use Nermif\Logs\Tests\Support\SimpleStringable;
use Nermif\Logs\Tests\Support\ThrowingStringable;

/**
 * 通过反射覆盖私有方法中依赖特殊参数类型/状态的边界分支，
 * 这些分支难以通过公开 API 稳定触发。
 */
class EdgeCaseReflectionTest extends LogsTestCase
{
    private function invoke(string $method, array $args = [])
    {
        $ref = new \ReflectionMethod(Logs::class, $method);
        if (PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        return $ref->invoke(null, ...$args);
    }

    public function testDescribeArgTypeCoversAllBranches(): void
    {
        $this->assertSame('null', $this->invoke('describeArgType', [null]));
        $this->assertSame('bool', $this->invoke('describeArgType', [true]));
        $this->assertSame('int', $this->invoke('describeArgType', [7]));
        $this->assertSame('float', $this->invoke('describeArgType', [1.5]));
        $this->assertSame('string(3)', $this->invoke('describeArgType', ['abc']));
        $this->assertSame('array(2)', $this->invoke('describeArgType', [[1, 2]]));
        $this->assertSame('object(stdClass)', $this->invoke('describeArgType', [new \stdClass()]));

        $resource = fopen('php://memory', 'r+');
        $this->assertSame('resource(stream)', $this->invoke('describeArgType', [$resource]));
        fclose($resource);
        $this->assertSame('resource (closed)', $this->invoke('describeArgType', [$resource]));
    }

    public function testFormatArgStringEdgeBranches(): void
    {
        $this->assertSame('null', $this->invoke('formatArg', [null]));
        $this->assertSame('...', $this->invoke('formatArg', ['x', 4]));
        $this->assertSame("'abc'", $this->invoke('formatArg', ['abc']));

        $this->assertStringContainsString('…', $this->invoke('formatArg', [str_repeat('a', 101)]));
        $this->assertSame("'(binary)'", $this->invoke('formatArg', ["\xC3\x28"]));
        $this->assertSame('[2 elements]', $this->invoke('formatArg', [[1, 2], 3]));
    }

    public function testFormatArgObjectEdgeBranches(): void
    {
        $this->assertStringContainsString('boom', $this->invoke('formatArg', [new \RuntimeException('boom')]));
        $this->assertSame('object(RuntimeException)', $this->invoke('formatArg', [new \RuntimeException('')]));

        $this->assertSame(
            'object(Nermif\Logs\Tests\Support\SimpleStringable) "hello-stringable"',
            $this->invoke('formatArg', [new SimpleStringable()])
        );
        $this->assertStringContainsString(
            '__toString exception',
            $this->invoke('formatArg', [new ThrowingStringable()])
        );
    }

    public function testFormatArgResourceEdgeBranches(): void
    {
        $resource = fopen('php://memory', 'r+');
        $this->assertSame('resource(stream)', $this->invoke('formatArg', [$resource]));
        fclose($resource);
        $this->assertMatchesRegularExpression('/^Resource id #\d+$/', $this->invoke('formatArg', [$resource]));
    }

    public function testWhitespaceStringNotSensitiveInTrace(): void
    {
        $this->assertSame("'   '", $this->invoke('formatArg', ['   ', 0, null, true]));
    }

    public function testIsProductionEnvReflection(): void
    {
        $this->assertIsBool($this->invoke('isProductionEnv'));
    }

    public function testGetDefaultBasePathFallback(): void
    {
        $expected = dirname(__DIR__, 3) . '/runtime/logs';
        $this->assertSame($expected, $this->invoke('getDefaultBasePath'));
    }

    public function testPrivateConstructorAndCloneRuntimeGuard(): void
    {
        $class = new \ReflectionClass(Logs::class);
        $instance = $class->newInstanceWithoutConstructor();
        $constructor = $class->getConstructor();
        if (PHP_VERSION_ID < 80100) {
            $constructor->setAccessible(true);
        }
        $constructor->invoke($instance);
        $this->assertInstanceOf(Logs::class, $instance);

        $clone = $class->getMethod('__clone');
        if (PHP_VERSION_ID < 80100) {
            $clone->setAccessible(true);
        }
        $clone->invoke($instance);
        $this->addToAssertionCount(1);
    }
}