<?php

declare(strict_types=1);

/*
 * 测试专用框架桩（think / Webman），仅用于单元测试中执行
 * src/middleware 下的中间件逻辑，不提供任何线上语义。
 */

namespace think;

class Response
{
    protected $headers = [];

    public function getHeaders(): array
    {
        return $this->headers;
    }
}

class DynamicResponse extends Response
{
    public function header($name, $value = null)
    {
        if (is_array($name)) {
            $this->headers = array_merge($this->headers, $name);
        } else {
            $this->headers[$name] = $value;
        }
        return $this;
    }
}

class StringResponse extends Response
{
    public function header(string $name, string $value = null)
    {
        $this->headers[$name] = $value;
        return $this;
    }
}

class ArrayResponse extends Response
{
    public function header(array $headers)
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }
}

class UnionResponse extends Response
{
    public function header(string|array $name, $value = null)
    {
        if (is_array($name)) {
            $this->headers = array_merge($this->headers, $name);
        } else {
            $this->headers[$name] = $value;
        }
        return $this;
    }
}

class ThrowingResponse extends Response
{
    public function header($name, $value = null)
    {
        if ($value !== null) {
            throw new \RuntimeException('string header api unsupported');
        }
        if (is_array($name)) {
            $this->headers = array_merge($this->headers, $name);
        }
        return $this;
    }
}

namespace Webman;

interface MiddlewareInterface
{
}

namespace Webman\Http;

class Request
{
}

class Response
{
    private $headers = [];

    public function header(string $name, string $value = null)
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}