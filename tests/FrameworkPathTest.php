<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

class FrameworkPathTest extends LogsTestCase
{
    public function testRuntimePathFunctionTakesPriority(): void
    {
        $expected = '/tmp/fw-runtime/logs';
        $code = 'function runtime_path($path = "") { return "/tmp/fw-runtime/" . $path; }';
        $actual = $this->runInSubprocess($code);
        $this->assertSame($expected, $actual);
    }

    public function testRuntimPathConstantUsed(): void
    {
        $expected = '/tmp/thinkphp50/logs';
        $code = "define('RUNTIME_PATH', '/tmp/thinkphp50/');";
        $actual = $this->runInSubprocess($code);
        $this->assertSame($expected, $actual);
    }

    public function testRootPathConstantUsed(): void
    {
        $expected = '/tmp/thinkphp-root/runtime/logs';
        $code = "define('ROOT_PATH', '/tmp/thinkphp-root/');";
        $actual = $this->runInSubprocess($code);
        $this->assertSame($expected, $actual);
    }

    public function testFallbackToProjectRuntimeDir(): void
    {
        $expected = dirname(__DIR__, 3) . '/runtime/logs';
        $actual = $this->runInSubprocess('');
        $this->assertSame($expected, $actual);
    }

    /**
     * @return string 子进程输出的默认路径（无尾随换行）
     */
    private function runInSubprocess(string $prologue): string
    {
        if (!function_exists('shell_exec')) {
            $this->markTestSkipped('shell_exec is disabled');
        }
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $code = <<<'PHP'
require $argv[1];
$prologue;
$method = new ReflectionMethod(Nermif\Logs\Logs::class, 'getDefaultBasePath');
if (PHP_VERSION_ID < 80100) {
    $method->setAccessible(true);
}
echo $method->invoke(null);
PHP;
        $code = str_replace('$prologue;', $prologue === '' ? '' : $prologue, $code);
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg($autoload) . ' 2>&1');
        $this->assertIsString($output);
        return rtrim($output, "\r\n");
    }
}
