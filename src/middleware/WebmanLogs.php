<?php

namespace Nermif\Logs\middleware;

use Nermif\Logs\Logs;
use Ramsey\Uuid\Uuid;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class WebmanLogs implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        Logs::init();

        $response = $handler($request);

        if ($response instanceof Response) {
            $response->header('X-Trace-Id', Logs::getTraceId());
        }

        Logs::endRequest();

        return $response;
    }
}