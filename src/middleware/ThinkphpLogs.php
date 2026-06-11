<?php

namespace Nermif\Logs\middleware;

use Nermif\Logs\Logs;
use think\Response;

class ThinkphpLogs
{
    /**
     * 处理请求（兼容 ThinkPHP 5.0 / 5.1 / 6.x / 8.x）
     *
     * - 5.x：Response::header(array $header)  或  header($name, $value) 均可
     * - 6.x / 8.x：Response::header(string $name, string $value = null)  第一个参数为字符串
     * 这里通过反射探测 header() 方法第一个参数的类型，选择兼容的调用方式。
     *
     * @param \think\Request $request
     * @param \Closure $next
     * @return Response
     */
    public function handle($request, \Closure $next)
    {
        Logs::initRequest();

        $response = $next($request);

        if ($response instanceof Response) {
            $requestId = (string) Logs::getRequestId();
            if ($requestId !== '') {
                self::setResponseHeader($response, 'X-Request-Id', $requestId);
            }
        }

        Logs::endRequest();

        return $response;
    }

    /**
     * 以跨版本兼容的方式为 think\Response 设置响应头。
     */
    private static function setResponseHeader(Response $response, string $name, string $value): void
    {
        try {
            $reflection = new \ReflectionMethod($response, 'header');
            $parameters = $reflection->getParameters();
            if (isset($parameters[0])) {
                $firstParam = $parameters[0];
                // PHP 7.1+ 支持 getType()，这里做类型检测
                $typeName = '';
                if ($firstParam->hasType()) {
                    $type = $firstParam->getType();
                    if ($type instanceof \ReflectionNamedType) {
                        $typeName = $type->getName();
                    } elseif (method_exists($type, '__toString')) {
                        $typeName = (string) $type;
                    }
                }
                // 第一个参数声明为 string → think 6.x/8.x 风格：header(name, value)
                if ($typeName === 'string') {
                    $response->header($name, $value);
                    return;
                }
                // 第一个参数声明为 array → think 5.x 风格：header(['name' => 'value'])
                if ($typeName === 'array') {
                    $response->header([$name => $value]);
                    return;
                }
            }
            // 无法通过反射判断，尝试 think 6.x/8.x 风格（大多数生产环境），失败则回退到数组风格
            try {
                $response->header($name, $value);
            } catch (\Throwable $e) {
                $response->header([$name => $value]);
            }
        } catch (\Throwable $e) {
            // 反射失败时退而求其次：使用 header() 函数直接发送（仅 FPM/CLI-Server 可用）
            if (!headers_sent()) {
                header($name . ': ' . $value);
            }
        }
    }
}