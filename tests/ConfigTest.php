<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;

class ConfigTest extends LogsTestCase
{
    public function testConfigureAppliesAllOptions(): void
    {
        Logs::configure([
            'base_path' => $this->tmpDir,
            'min_level' => 'debug',
            'gc_ttl' => 0,
            'sensitive_keys' => ['mytoken'],
            'sanitize_message' => false,
            'expand_trace_args' => true,
            'auto_mask_high_entropy_strings' => true,
            'sensitive_key_match_mode' => 'exact',
            'max_file_size' => 12345,
        ]);
        Logs::init();
        Logs::debug('debug should pass');
        $this->assertFileExists($this->logFile());

        $prop = new \ReflectionProperty(Logs::class, 'basePath');
        if (PHP_VERSION_ID < 80100) {
            $prop->setAccessible(true);
        }
        $this->assertSame(realpath($this->tmpDir), $prop->getValue(null));
        $maxProp = new \ReflectionProperty(Logs::class, 'maxFileSize');
        if (PHP_VERSION_ID < 80100) {
            $maxProp->setAccessible(true);
        }
        $this->assertSame(12345, $maxProp->getValue(null));
        $logs = $this->readLogs();
        $this->assertSame('DEBUG', $logs[0]['level']);
    }

    public function testConfigureIgnoresUnknownKeys(): void
    {
        Logs::configure(['base_path' => $this->tmpDir, 'unknown_option' => 'x']);
        $this->initLogs();
        Logs::info('works');
        $this->assertCount(1, $this->readLogs());
    }

    public function testSetGcTtlClampsNegative(): void
    {
        Logs::setGcTtl(-5);
        $prop = new \ReflectionProperty(Logs::class, 'gcTtl');
        if (PHP_VERSION_ID < 80100) {
            $prop->setAccessible(true);
        }
        $this->assertSame(0, $prop->getValue(null));
    }

    public function testGcRemovesEmptyContextsOverThreshold(): void
    {
        $prop = new \ReflectionProperty(Logs::class, 'contexts');
        if (PHP_VERSION_ID < 80100) {
            $prop->setAccessible(true);
        }
        $contexts = [];
        for ($i = 0; $i < 600; $i++) {
            $contexts['fake_' . $i] = ['trace_id' => null, 'feat' => null, 'created_at' => time()];
        }
        $contexts['stale'] = ['trace_id' => 'abc', 'feat' => null, 'created_at' => time() - 400];
        $prop->setValue(null, $contexts);

        Logs::init(['base_path' => $this->tmpDir]);

        $remaining = $prop->getValue(null);
        $this->assertCount(1, $remaining);
        $this->assertArrayHasKey('__main__', $remaining);
    }

    public function testHardLimitFallbackKeepsHalf(): void
    {
        $prop = new \ReflectionProperty(Logs::class, 'contexts');
        if (PHP_VERSION_ID < 80100) {
            $prop->setAccessible(true);
        }
        $contexts = [];
        for ($i = 0; $i < 2500; $i++) {
            $contexts['fake_' . $i] = ['trace_id' => (string)$i, 'feat' => null, 'created_at' => time()];
        }
        $prop->setValue(null, $contexts);
        Logs::setGcTtl(0);

        Logs::init(['base_path' => $this->tmpDir]);

        $remaining = $prop->getValue(null);
        $this->assertCount(1251, $remaining);
    }
}
