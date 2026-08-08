<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;
use Nermif\Logs\Tests\Support\HighEntropyException;

class AutoMaskHighEntropyStringsTest extends LogsTestCase
{
    public function testAutoMaskHighEntropyStringsDisabledByDefault(): void
    {
        $this->initLogs();
        Logs::error(null, new HighEntropyException());
        $logs = $this->readLogs();
        $payload = $logs[0]['context']['exception']['extra']['payload'];
        $this->assertStringStartsWith('X9kQz2m', $payload); // Should show actual high entropy value
    }

    public function testAutoMaskHighEntropyStringsEnabled(): void
    {
        $this->initLogs(['auto_mask_high_entropy_strings' => true]);
        Logs::error(null, new HighEntropyException());
        $logs = $this->readLogs();
        $payload = $logs[0]['context']['exception']['extra']['payload'];
        $this->assertSame('***(high entropy)', $payload); // Should show masked value
    }

    public function testAutoMaskHighEntropyStringsDoesNotAffectNormalStrings(): void
    {
        $this->initLogs(['auto_mask_high_entropy_strings' => true]);
        Logs::error(null, new \RuntimeException('normal error', 1));
        $logs = $this->readLogs();
        $message = $logs[0]['context']['message'];
        $this->assertSame('normal error', $message); // Should not be affected
    }

    public function testAutoMaskHighEntropyStringsWithMixedContent(): void
    {
        $this->initLogs(['auto_mask_high_entropy_strings' => true]);
        Logs::error(null, new HighEntropyException());
        $logs = $this->readLogs();
        $extra = $logs[0]['context']['exception']['extra'];
        $this->assertSame('***(high entropy)', $extra['payload']);
        $this->assertSame('this is normal', $logs[0]['context']['message']);
    }
}