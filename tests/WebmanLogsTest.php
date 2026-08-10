<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\middleware\WebmanLogs;

require_once __DIR__ . '/support/FrameworkStubs.php';

/**
 * 使用 Webman 桩类覆盖 WebmanLogs 中间件的请求/响应处理逻辑。
 */
class WebmanLogsTest extends LogsTestCase
{
    public function testProcessWithResponse(): void
    {
        $this->initLogs();
        $response = new \Webman\Http\Response();
        $middleware = new WebmanLogs();

        $result = $middleware->process(
            new \Webman\Http\Request(),
            static function () use ($response) {
                return $response;
            }
        );

        $this->assertSame($response, $result);
        $this->assertArrayHasKey('X-Trace-Id', $result->getHeaders());
    }
}