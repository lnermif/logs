<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

/**
 * 覆盖 src/config.php 示例配置文件（保证 src 目录下所有可执行文件均有测试覆盖）。
 */
class ConfigFileTest extends LogsTestCase
{
    public function testConfigFileReturnsExpectedShape(): void
    {
        $config = require dirname(__DIR__) . '/src/config.php';

        $this->assertIsArray($config);
        $this->assertSame('', $config['base_path']);
        $this->assertSame('info', $config['min_level']);
        $this->assertSame(300, $config['gc_ttl']);
        $this->assertSame(
            ['password', 'passwd', 'secret', 'token', 'authorization', 'api_key', 'access_token', 'refresh_token'],
            $config['sensitive_keys']
        );
        $this->assertSame('contains', $config['sensitive_key_match_mode']);
        $this->assertTrue($config['sanitize_message']);
        $this->assertFalse($config['expand_trace_args']);
        $this->assertFalse($config['auto_mask_high_entropy_strings']);
        $this->assertArrayHasKey('min_level', $config);
    }
}