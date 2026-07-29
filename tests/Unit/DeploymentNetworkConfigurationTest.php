<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DeploymentNetworkConfiguration;
use Tests\TestCase;

final class DeploymentNetworkConfigurationTest extends TestCase
{
    public function test_railway_hosts_are_normalized_escaped_and_anchored(): void
    {
        $this->assertSame([
            '^app\.example\.test$',
            '^admin\.example\.test$',
            '^panel\.example\.test$',
            '^healthcheck\.railway\.app$',
            '^syncsus\.up\.railway\.app$',
        ], DeploymentNetworkConfiguration::trustedHostPatterns(
            'https://app.example.test:8443',
            implode(',', [
                'admin.example.test',
                'https://panel.example.test/path',
                '^invalid-regex.example$',
            ]),
            'service-id',
            'syncsus.up.railway.app',
        ));
        $this->assertSame('*', DeploymentNetworkConfiguration::trustedProxies(
            '',
            'service-id',
            'syncsus.up.railway.app',
        ));
    }

    public function test_self_hosted_installation_uses_only_explicit_proxies(): void
    {
        $this->assertSame(
            ['^syncsus\.local$'],
            DeploymentNetworkConfiguration::trustedHostPatterns(
                'https://syncsus.local',
                '',
                '',
                '',
            ),
        );
        $this->assertSame(
            ['10.0.0.0/8', '192.168.0.0/16'],
            DeploymentNetworkConfiguration::trustedProxies(
                '10.0.0.0/8,192.168.0.0/16',
                '',
                '',
            ),
        );
    }
}
