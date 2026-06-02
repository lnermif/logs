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
        Logs::initRequest();

        $response = $handler($request);

        if ($response instanceof Response) {
            $response->header('X-Request-Id', Logs::getRequestId());
        }

        Logs::endRequest();

        return $response;
    }
}