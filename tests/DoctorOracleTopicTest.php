<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

/**
 * The `doctor oracle` topic: the release log's plain-language Oracle connection check, on demand from a terminal.
 */
final class DoctorOracleTopicTest extends SquireTestCase
{
    public function testNoOracleSettingsIsSilentAndExitsZero(): void
    {
        $result = $this->runSquire(['doctor', 'oracle', '--path=' . $this->app()]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame('', $result['stdout']);
    }

    public function testTheDotEnvFeedsTheCheckAndAClosedPortGetsItsSentence(): void
    {
        $app = $this->app([
            '.env' => "ORACLE_HOST=127.0.0.1\nORACLE_PORT=1\nORACLE_SERVICE_NAME=x\nORACLE_USERNAME=u\nORACLE_PASSWORD=p\n",
        ]);
        $result = $this->runSquire(['doctor', 'oracle', '--path=' . $app]);

        self::assertSame(1, $result['exit'], $result['stdout']);
        $lines = explode("\n", trim($result['stdout']));
        self::assertCount(1, $lines);
        self::assertSame(
            "[oracle] Oracle did not answer at the host and port in your settings within 3 seconds. On Citadel: on the app's dashboard, tick the Oracle service under Services Available and click Request Access Changes. Locally: check the VPN.",
            $lines[0],
        );
        self::assertStringNotContainsString('ORACLE_PASSWORD=', $result['stdout']);
    }

    public function testAnUnknownTopicExitsTwoWithTheAllowedTopics(): void
    {
        $result = $this->runSquire(['doctor', 'banana', '--path=' . $this->app()]);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('Unknown topic "banana". Allowed topics: oracle.', $result['stderr']);
    }

    public function testTheOracleTopicPrintsTextOnly(): void
    {
        $result = $this->runSquire(['doctor', 'oracle', '--format=json', '--path=' . $this->app()]);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('The oracle topic prints text only.', $result['stderr']);
    }
}
