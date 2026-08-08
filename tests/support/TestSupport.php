<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests\Support;

final class SimpleStringable
{
    public function __toString(): string
    {
        return 'hello-stringable';
    }
}

final class ThrowingStringable
{
    public function __toString(): string
    {
        throw new \RuntimeException('boom on __toString');
    }
}

final class CustomException extends \Exception
{
    private $userId = 42;
    public $password = 'hunter2';
    protected $opaque = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0.abc123abc123abc123';
}

final class HighEntropyException extends \Exception
{
    public $payload = 'X9kQz2mLp7vR8tW3nB5cF1dG0hJ4sA6zY8uI2oP5eR7tQ';
}

function trace_provider(string $secret, array $payload, int $count): void
{
    throw new \RuntimeException('trace me');
}

function trace_recursion_provider(\stdClass $obj, \stdClass $sameObj): void
{
    throw new \RuntimeException('recursive');
}

function trace_bearer_provider(string $auth): void
{
    throw new \RuntimeException('unauthorized');
}
