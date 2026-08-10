<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;

/**
 * 覆盖依赖外部环境 / 全局状态的分支（getDefaultBasePath 的 runtime_path、
 * RUNTIME_PATH、ROOT_PATH 分支，setBasePath 的 ROOT_PATH 告警，init() 的
 * UUID 降级路径）。每个测试运行在独立子进程中，避免常量/函数污染主进程。
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DefaultPathBranchTest extends LogsTestCase
{
    public function testRuntimePathFunctionBranch(): void
    {
        if (!\function_exists('runtime_path')) {
            eval('function runtime_path($path = "") { return "/tmp/sepruntime/" . $path; }');
        }
        Logs::setMinLevel('debug');
        Logs::info('runtime function branch');
        $files = glob('/tmp/sepruntime/logs/' . date('Ym') . '/*.log');
        $this->assertIsArray($files);
        $this->assertNotEmpty($files);
    }

    public function testRuntimePathConstantBranch(): void
    {
        if (!defined('RUNTIME_PATH')) {
            define('RUNTIME_PATH', '/tmp/tp50-runtime/');
        }
        Logs::setMinLevel('debug');
        Logs::info('runtime const branch');
        $files = glob('/tmp/tp50-runtime/logs/' . date('Ym') . '/*.log');
        $this->assertIsArray($files);
        $this->assertNotEmpty($files);
    }

    public function testRootPathConstantBranch(): void
    {
        if (!defined('ROOT_PATH')) {
            define('ROOT_PATH', '/tmp/tproot/');
        }
        Logs::setMinLevel('debug');
        Logs::info('root path branch');
        $files = glob('/tmp/tproot/runtime/logs/' . date('Ym') . '/*.log');
        $this->assertIsArray($files);
        $this->assertNotEmpty($files);
    }

    public function testSetBasePathWarnsWhenOutsideRootPath(): void
    {
        if (!defined('ROOT_PATH')) {
            define('ROOT_PATH', '/usr');
        }
        $errorFile = $this->tmpDir . '/err.log';
        ini_set('error_log', $errorFile);

        Logs::setBasePath($this->tmpDir);

        $logged = (string) @file_get_contents($errorFile);
        $this->assertStringContainsString('Logs::setBasePath - 日志目录', $logged);
        $this->assertStringContainsString($this->tmpDir, $logged);
    }

    public function testInitUsesUuid7WhenAvailable(): void
    {
        eval(<<<'PHP'
namespace Ramsey\Uuid;

class Uuid
{
    public $value = 'fake';

    public static function uuid7(): self
    {
        $instance = new self();
        $instance->value = 'fake-uuid-7';
        return $instance;
    }

    public static function uuid4(): self
    {
        $instance = new self();
        $instance->value = 'fake-uuid-4';
        return $instance;
    }

    public function toString(): string
    {
        return $this->value;
    }
}
PHP);

        $this->assertTrue(class_exists(\Ramsey\Uuid\Uuid::class, false));

        Logs::init();

        $this->assertSame('fake-uuid-7', Logs::getTraceId());
    }

    public function testInitFallsBackToNativeUuidWhenVendorMissing(): void
    {
        // 临时移除 Composer 类加载器，模拟缺少 ramsey/uuid 的环境。
        // 仅挂起（而非卸载全部加载器），避免破坏 PHPUnit 隔离运行的类加载。
        $composerLoader = null;
        foreach (spl_autoload_functions() as $loader) {
            if (is_array($loader) && isset($loader[0]) && $loader[0] instanceof \Composer\Autoload\ClassLoader) {
                $composerLoader = $loader;
                spl_autoload_unregister($loader);
            }
        }

        try {
            Logs::init();
        } finally {
            if ($composerLoader !== null) {
                spl_autoload_register($composerLoader);
            }
        }

        $traceId = Logs::getTraceId();
        $this->assertNotNull($traceId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $traceId
        );
    }
}