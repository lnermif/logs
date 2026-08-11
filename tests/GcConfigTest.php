<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;

/**
 * 覆盖 GC 阈值配置（硬上限 / 应急 TTL）的默认值、configure() 注入、
 * setter 边界钳制，以及自定义阈值下应急清理与兜底清理的实际行为。
 */
class GcConfigTest extends LogsTestCase
{
    private function readStatic(string $name)
    {
        $prop = new \ReflectionProperty(Logs::class, $name);
        if (PHP_VERSION_ID < 80100) {
            $prop->setAccessible(true);
        }
        return $prop->getValue(null);
    }

    public function testDefaultsMatchPreviousHardcodedValues(): void
    {
        $this->assertSame(2048, $this->readStatic('gcHardLimit'));
        $this->assertSame(300, $this->readStatic('gcEmergencyTtl'));
    }

    public function testConfigureAppliesGcThresholds(): void
    {
        Logs::configure([
            'base_path' => $this->tmpDir,
            'gc_hard_limit' => 123,
            'gc_emergency_ttl' => 45,
        ]);

        $this->assertSame(123, $this->readStatic('gcHardLimit'));
        $this->assertSame(45, $this->readStatic('gcEmergencyTtl'));
    }

    public function testSetGcHardLimitClampsToAtLeastOne(): void
    {
        Logs::setGcHardLimit(0);
        $this->assertSame(1, $this->readStatic('gcHardLimit'));
    }

    public function testSetGcEmergencyTtlClampsNegative(): void
    {
        Logs::setGcEmergencyTtl(-10);
        $this->assertSame(0, $this->readStatic('gcEmergencyTtl'));
    }

    public function testCustomEmergencyTtlCullsOldContextsOverLimit(): void
    {
        Logs::setGcTtl(0);
        Logs::setGcHardLimit(40);
        Logs::setGcEmergencyTtl(5);
        Logs::setCustomWriter(static function (string $file, string $content): void {
        });

        $prop = new \ReflectionProperty(Logs::class, 'contexts');
        if (PHP_VERSION_ID < 80100) {
            $prop->setAccessible(true);
        }

        $now = time();
        $contexts = [];
        for ($i = 0; $i < 30; $i++) {
            $contexts['old-' . $i] = ['trace_id' => 't', 'feat' => 'f', 'created_at' => $now - 600];
        }
        for ($i = 0; $i < 30; $i++) {
            $contexts['fresh-' . $i] = ['trace_id' => 't', 'feat' => 'f', 'created_at' => $now];
        }
        $prop->setValue(null, $contexts);

        Logs::setBasePath($this->tmpDir);
        Logs::info('trigger gc');

        $remaining = $prop->getValue(null);
        $this->assertArrayNotHasKey('old-0', $remaining);
        $this->assertArrayHasKey('fresh-0', $remaining);
        $this->assertCount(31, $remaining); // 30 fresh + __main__
    }

    public function testCustomHardLimitFallbackKeepsHalf(): void
    {
        Logs::setGcTtl(0);
        Logs::setGcHardLimit(40);
        Logs::setGcEmergencyTtl(300);
        Logs::setCustomWriter(static function (string $file, string $content): void {
        });

        $prop = new \ReflectionProperty(Logs::class, 'contexts');
        if (PHP_VERSION_ID < 80100) {
            $prop->setAccessible(true);
        }

        $now = time();
        $contexts = [];
        for ($i = 0; $i < 60; $i++) {
            $contexts['c-' . $i] = ['trace_id' => 't', 'feat' => 'f', 'created_at' => $now];
        }
        $prop->setValue(null, $contexts);

        Logs::setBasePath($this->tmpDir);
        Logs::info('trigger gc fallback');

        $remaining = $prop->getValue(null);
        $this->assertCount(31, $remaining); // 60 * 0.5 = 30 kept + __main__
    }
}
