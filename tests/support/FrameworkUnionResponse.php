<?php

declare(strict_types=1);

/*
 * PHP 8.0+ 专属桩（联合类型语法，PHP < 8.0 无法解析）。
 * 仅在 PHP_VERSION_ID >= 80000 时由测试文件 require，
 * 用于覆盖 ThinkphpLogs::setResponseHeader() 对联合类型参数
 * 的反射探测分支（ReflectionUnionType / __toString）。
 */

namespace think;

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