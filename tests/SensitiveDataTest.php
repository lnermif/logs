<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;
use Nermif\Logs\Tests\Support\HighEntropyException;

class SensitiveDataTest extends LogsTestCase
{
    public function testPasswordKeyMaskedInContext(): void
    {
        $this->initLogs();
        Logs::info('login', ['password' => 'hunter2']);
        $logs = $this->readLogs();
        $this->assertSame('***', $logs[0]['context']['password']);
    }

    public function testContainsModeMatchesSubstrings(): void
    {
        $this->initLogs();
        Logs::info('order', ['my_secret_key' => 'abc']);
        $logs = $this->readLogs();
        $this->assertSame('***', $logs[0]['context']['my_secret_key']);
    }

    public function testExactModeIgnoresSubstrings(): void
    {
        $this->initLogs(['sensitive_key_match_mode' => 'exact']);
        Logs::info('order', [
            'access_token' => 'tok-1',
            'callback_token_count' => 3,
        ]);
        $logs = $this->readLogs();
        $this->assertSame('***', $logs[0]['context']['access_token']);
        $this->assertSame(3, $logs[0]['context']['callback_token_count']);
    }

    public function testEmptySensitiveKeysDisablesMasking(): void
    {
        $this->initLogs(['sensitive_keys' => []]);
        Logs::info('login', ['password' => 'hunter2']);
        $logs = $this->readLogs();
        $this->assertSame('hunter2', $logs[0]['context']['password']);
    }

    public function testSensitiveKeysNormalizedToLowercase(): void
    {
        $this->initLogs();
        Logs::setSensitiveKeys(['MyToken']);
        Logs::info('login', ['mytoken' => 'secret']);
        $logs = $this->readLogs();
        $this->assertSame('***', $logs[0]['context']['mytoken']);
    }

    public function testInvalidMatchModeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Logs::setSensitiveKeyMatchMode('regex');
    }

    public function testNestedSensitiveKeysMaskedRecursively(): void
    {
        $this->initLogs();
        Logs::info('nested', [
            'user' => ['credentials' => ['password' => 'hunter2'], 'id' => 7],
        ]);
        $logs = $this->readLogs();
        $this->assertSame('***', $logs[0]['context']['user']['credentials']['password']);
        $this->assertSame(7, $logs[0]['context']['user']['id']);
    }

    public function testHighEntropyAutoMasking(): void
    {
        $this->initLogs(['auto_mask_high_entropy_strings' => true]);
        Logs::error(new HighEntropyException());
        $logs = $this->readLogs();
        $this->assertSame('***(high entropy)', $logs[0]['context']['exception']['extra']['payload']);
    }

    public function testHighEntropyNotMaskedByDefault(): void
    {
        $this->initLogs();
        Logs::error(new HighEntropyException());
        $logs = $this->readLogs();
        $this->assertStringStartsWith('X9kQz2m', $logs[0]['context']['exception']['extra']['payload']);
    }
}
