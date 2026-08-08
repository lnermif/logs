<?php

declare(strict_types=1);

namespace Nermif\Logs\Tests;

use Nermif\Logs\Logs;

class SensitiveKeyMatchModeTest extends LogsTestCase
{
    public function testSensitiveKeyMatchModeContainsSubstringMatching(): void
    {
        $this->initLogs();
        Logs::setSensitiveKeyMatchMode('contains');
        Logs::info('test', ['my_secret_key' => 'value', 'other_key' => 'another_value']);
        $logs = $this->readLogs();
        $this->assertSame('***', $logs[0]['context']['my_secret_key']);
        $this->assertSame('another_value', $logs[0]['context']['other_key']); // Fixed: was checking for 'value' but should be 'another_value'
    }

    public function testSensitiveKeyMatchModeExactMatching(): void
    {
        $this->initLogs(['sensitive_key_match_mode' => 'exact']);
        Logs::info('test', ['my_secret_key' => 'value', 'other_secret_key' => 'another_value']);
        $logs = $this->readLogs();
        $this->assertSame('***', $logs[0]['context']['my_secret_key']);
        $this->assertSame('value', $logs[0]['context']['my_secret_key']);  // Fixed: was checking 'other_secret_key'
        $this->assertSame('another_value', $logs[0]['context']['other_secret_key']);
        $this->assertSame('value', $logs[0]['context']['other_value']);
    }

    public function testSensitiveKeyMatchModeEmptyKeyListDisablesMasking(): void
    {
        $this->initLogs(['sensitive_keys' => []]);
        Logs::setSensitiveKeyMatchMode('contains');
        Logs::info('test', ['password' => 'hunter2']);
        $logs = $this->readLogs();
        $this->assertSame('hunter2', $logs[0]['context']['password']);
    }

    public function testSensitiveKeyMatchModeSpecialCharacters(): void
    {
        $this->initLogs();
        Logs::setSensitiveKeyMatchMode('contains');
        Logs::info('test', ['key.with.dots' => 'value', 'normal_key' => 'another_value']);
        $logs = $this->readLogs();
        $this->assertSame('***', $logs[0]['context']['key.with.dots']);
        $this->assertSame('another_value', $logs[0]['context']['normal_key']);
    }

    public function testSensitiveKeyMatchModeCaseInsensitiveContains(): void
    {
        $this->initLogs();
        Logs::setSensitiveKeyMatchMode('contains');
        Logs::info('test', ['MySecretKey' => 'value', 'mysecretkey' => 'another_value']);
        $logs = $this->readLogs();
        $this->assertSame('***', $logs[0]['context']['MySecretKey']);
        $this->assertSame('***', $logs[0]['context']['mysecretkey']);
        $this->assertSame('value', $logs[0]['context']['MySecretKey']);
        $this->assertSame('another_value', $logs[0]['context']['mysecretkey']);
    }
}