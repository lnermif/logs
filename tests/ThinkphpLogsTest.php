<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\middleware\ThinkphpLogs;

require_once __DIR__ . '/support/FrameworkStubs.php';

/**
 * 使用 think\Response 桩类覆盖 ThinkphpLogs 中间件在不同框架版本
 * （5.x 数组风格 / 6.x+ 字符串风格）下的响应头设置逻辑。
 */
class ThinkphpLogsTest extends LogsTestCase
{
    public function testHandleWithDynamicResponse(): void
    {
        $this->initLogs();
        $response = new \think\DynamicResponse();
        $middleware = new ThinkphpLogs();

        $result = $middleware->handle(null, static function () use ($response) {
            return $response;
        });

        $this->assertSame($response, $result);
        $this->assertArrayHasKey('X-Trace-Id', $result->getHeaders());
    }

    public function testHandleWithStringTypedResponse(): void
    {
        $this->initLogs();
        $response = new \think\StringResponse();
        $middleware = new ThinkphpLogs();

        $result = $middleware->handle(null, static function () use ($response) {
            return $response;
        });

        $this->assertSame($response, $result);
        $this->assertArrayHasKey('X-Trace-Id', $result->getHeaders());
    }

    public function testHandleWithArrayTypedResponse(): void
    {
        $this->initLogs();
        $response = new \think\ArrayResponse();
        $middleware = new ThinkphpLogs();

        $result = $middleware->handle(null, static function () use ($response) {
            return $response;
        });

        $this->assertSame($response, $result);
        $this->assertArrayHasKey('X-Trace-Id', $result->getHeaders());
    }

    public function testHandleWithUnionTypedResponse(): void
    {
        $this->initLogs();
        $response = new \think\UnionResponse();
        $middleware = new ThinkphpLogs();

        $result = $middleware->handle(null, static function () use ($response) {
            return $response;
        });

        $this->assertSame($response, $result);
        $this->assertArrayHasKey('X-Trace-Id', $result->getHeaders());
    }

    public function testHandleWithThrowingStringApiResponse(): void
    {
        $this->initLogs();
        $response = new \think\ThrowingResponse();
        $middleware = new ThinkphpLogs();

        $result = $middleware->handle(null, static function () use ($response) {
            return $response;
        });

        $this->assertSame($response, $result);
        $this->assertArrayHasKey('X-Trace-Id', $result->getHeaders());
    }

    public function testHandleWhenNextReturnsNonResponse(): void
    {
        $this->initLogs();
        $middleware = new ThinkphpLogs();

        $result = $middleware->handle(null, static function () {
            return 'plain-result';
        });

        $this->assertSame('plain-result', $result);
    }

    public function testHandleWhenResponseHasNoHeaderMethod(): void
    {
        $this->initLogs();
        $response = new \think\Response();
        $middleware = new ThinkphpLogs();

        $result = $middleware->handle(null, static function () use ($response) {
            return $response;
        });

        $this->assertSame($response, $result);
        $this->assertSame([], $result->getHeaders());
    }
}