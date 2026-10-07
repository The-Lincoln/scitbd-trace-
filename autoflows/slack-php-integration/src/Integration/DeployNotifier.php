<?php
/**
 * DeployNotifier - Automated deployment notification integration
 * Posts deployment events to Slack channels
 */

namespace SCITBD\Slack\Integration;

use SCITBD\Slack\Message\MessageBuilder;

class DeployNotifier
{
    private SlackIntegration $integration;

    public function __construct(SlackIntegration $integration)
    {
        $this->integration = $integration;
    }

    /**
     * Notify on deployment start
     */
    public function started(string $buildId, string $branch, string $deployer): array
    {
        return $this->integration->send('#deployments', "🚀 Deployment Started: {$buildId}", [
            MessageBuilder::header("🚀 Deploying: {$buildId}"),
            MessageBuilder::divider(),
            MessageBuilder::multiText([
                "*Branch:* {$branch}",
                "*Deployer:* {$deployer}",
                "*Status:* ⏳ In Progress",
                "*Time:* " . date('Y-m-d H:i:s'),
            ]),
            MessageBuilder::divider(),
            ['type' => 'actions', 'elements' => [
                MessageBuilder::button('View Logs', 'view_logs', 'secondary'),
                MessageBuilder::button('Cancel', 'cancel_deploy', 'danger'),
            ]],
        ]);
    }

    /**
     * Notify on successful deployment
     */
    public function success(string $buildId, string $branch, string $deployer): array
    {
        return $this->integration->notifyDeployment($buildId, 'success', $branch, $deployer);
    }

    /**
     * Notify on failed deployment
     */
    public function failed(string $buildId, string $branch, string $deployer, string $error = ''): array
    {
        $blocks = [
            MessageBuilder::header("❌ Deployment Failed: {$buildId}"),
            MessageBuilder::divider(),
            MessageBuilder::multiText([
                "*Branch:* {$branch}",
                "*Deployer:* {$deployer}",
                "*Status:* ❌ Failed",
                "*Time:* " . date('Y-m-d H:i:s'),
            ]),
        ];
        if (!empty($error)) {
            $blocks[] = MessageBuilder::divider();
            $blocks[] = MessageBuilder::text("*Error:* ```{$error}```");
        }
        return $this->integration->send('#deployments', "Deployment #{$buildId} Failed", $blocks);
    }

    /**
     * Send rollback notification
     */
    public function rolledBack(string $buildId, string $branch, string $deployer): array
    {
        return $this->integration->send('#deployments', "🔄 Rollback: {$buildId}", [
            MessageBuilder::header("🔄 Rollback Initiated"),
            MessageBuilder::divider(),
            MessageBuilder::multiText([
                "*From Build:* {$buildId}",
                "*Branch:* {$branch}",
                "*Deployer:* {$deployer}",
                "*Time:* " . date('Y-m-d H:i:s'),
            ]),
        ]);
    }
}
