<?php

namespace Nermif\Logs\middleware;

use Nermif\Logs\Logs;
use Ramsey\Uuid\Uuid;
use think\Response;

class ThinkphpLogs
{
    /**
     * 处理请求
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
            $response->header([
                'X-Request-Id' => Logs::getRequestId()
            ]);
        }

        Logs::endRequest();

        return $response;
    }
}