<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;

/**
 * 覆盖 gcContexts() 的紧急 TTL 分支与超硬上限兜底清理分支。
 * 通过反射向私有静态 $contexts 注入超过硬上限（2048）的协程上下文，
 * 再触发一次写入让 GC 执行。
 */
class GcContextsEmergencyTest extends LogsTestCase
{
    public function testGcBackstopCullsContextsOverHardLimit(): void
    {
        $errorFile = $this->tmpDir . '/err.log';
        ini_set('error_log', $errorFile);

        Logs::setGcTtl(0);
        Logs::setCustomWriter(static function (string $file, string $content): void {
            // 仅触发 GC，不落地文件
        });

        $prop = new \ReflectionProperty(Logs::class, 'contexts');
        if (PHP_VERSION_ID < 80100) {
            $prop->setAccessible(true);
        }

        $now = time();
        $contexts = [];
        for ($i = 0; $i < 2051; $i++) {
            $contexts['stale-' . $i] = ['trace_id' => 't', 'feat' => 'f', 'created_at' => $now - 700];
        }
        for ($i = 0; $i < 2050; $i++) {
            $contexts['fresh-' . $i] = ['trace_id' => 't', 'feat' => 'f', 'created_at' => $now];
        }
        $contexts['__main__'] = ['trace_id' => 't', 'feat' => 'f', 'created_at' => $now];
        $prop->setValue(null, $contexts);

        Logs::setBasePath($this->tmpDir);
        Logs::info('trigger gc');

        $remaining = $prop->getValue(null);
        $this->assertArrayNotHasKey('stale-0', $remaining);
        $this->assertLessThan(2048, count($remaining));

        $logged = (string) @file_get_contents($errorFile);
        $this->assertStringContainsString('Logs::gcContexts - 上下文数量超过硬上限', $logged);
    }
}