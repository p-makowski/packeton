<?php

declare(strict_types=1);

namespace Packeton\Webhook;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Package;
use Packeton\Entity\User;
use Packeton\Entity\Version;
use Packeton\Entity\Webhook;
use Packeton\Webhook\Twig\WebhookContext;
use Psr\Log\LogLevel;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HookTestAction
{
    private $executor;
    private $registry;
    private $tokenStorage;
    private $requestStack;

    public function __construct(ManagerRegistry $registry, HookRequestExecutor $executor, TokenStorageInterface $tokenStorage = null, RequestStack $requestStack = null)
    {
        $this->registry = $registry;
        $this->executor = $executor;
        $this->tokenStorage = $tokenStorage;
        $this->requestStack = $requestStack;
    }

    /**
     * @param Webhook $webhook
     * @param array $data
     *
     * @return HookResponse[]
     */
    public function runTest(Webhook $webhook, array $data)
    {
        $this->selectPackage($data);
        $this->selectVersion($data);
        $this->selectUser($data);
        $this->selectPayload($data);

        switch ($data['event']) {
            case Webhook::HOOK_RL_UPDATE:
            case Webhook::HOOK_RL_NEW:
            case Webhook::HOOK_PUSH_UPDATE:
            case Webhook::HOOK_PUSH_NEW:
                $context = [
                    'package' => $data['package'],
                    'versions' => $data['versions']
                ];
                break;
            case Webhook::HOOK_REPO_NEW:
                $context = [
                    'package' => $data['package'],
                ];
                break;
            case Webhook::HOOK_SECURITY_AUDIT:
                $audit = '[{"advisoryId":"PKSA-hr4y-jwk2-1yb9","packageName":"symfony/http-kernel","version": "3.4.10", "affectedVersions":">=2.0.0,<2.1.0|>=2.1.0,<2.2.0|>=2.2.0,<2.3.0|>=2.3.0,<2.4.0|>=2.4.0,<2.5.0|>=2.5.0,<2.6.0|>=2.6.0,<2.7.0|>=2.7.0,<2.8.0|>=2.8.0,<3.0.0|>=3.0.0,<3.1.0|>=3.1.0,<3.2.0|>=3.2.0,<3.3.0|>=3.3.0,<3.4.0|>=3.4.0,<4.0.0|>=4.0.0,<4.1.0|>=4.1.0,<4.2.0|>=4.2.0,<4.3.0|>=4.3.0,<4.4.0|>=4.4.0,<4.4.50|>=5.0.0,<5.1.0|>=5.1.0,<5.2.0|>=5.2.0,<5.3.0|>=5.3.0,<5.4.0|>=5.4.0,<5.4.20|>=6.0.0,<6.0.20|>=6.1.0,<6.1.12|>=6.2.0,<6.2.6","title":"CVE-2022-24894: Prevent storing cookie headers in HttpCache","cve":"CVE-2022-24894","link":"https://symfony.com/cve-2022-24894","reportedAt":"2023-02-01T08:00:00+00:00","sources":[{"name":"GitHub","remoteId":"GHSA-h7vf-5wrv-9fhv"},{"name":"FriendsOfPHP/security-advisories","remoteId":"symfony/http-kernel/CVE-2022-24894.yaml"}]}]';
                $audit = json_decode($audit, true);
                $context = [
                    'package' => $data['package'],
                    'advisories' => $audit,
                    'all_advisories' => $audit,
                ];
                break;
            case Webhook::HOOK_RL_DELETE:
                $versions = array_map(function (Version $version) {
                    return $version->toArray();
                }, $data['versions']);

                $context = [
                    'package' => $data['package'],
                    'versions' => $versions
                ];
                break;
            case Webhook::HOOK_REPO_DELETE:
                $repo = $this->registry->getRepository(Version::class);
                $package = $data['package'] ?? null;
                if ($package instanceof Package) {
                    $package = $package->toArray($repo);
                }
                $context = [
                    'package' => $package,
                ];
                break;
            case Webhook::HOOK_REPO_FAILED:
                $context = [
                    'package' => $data['package'],
                    'message' => 'Exception message'
                ];
                break;
            case Webhook::HOOK_USER_LOGIN:
                $context = [
                    'user' => $data['user'],
                    'ip_address' => $data['ip_address']
                ];
                break;
            case Webhook::HOOK_HTTP_REQUEST:
                $context = [
                    'request' => $data['payload'] ?? null,
                    'ip_address' => $data['ip_address'],
                ];
                break;
            default:
                $context = [];
                break;
        }

        $context['event'] = $data['event'];
        $client = null;
        if (($data['sendReal'] ?? false) !== true) {
            $callback = function () {
                $responseTime = rand(50000, 250000);
                usleep($responseTime);
                return new MockResponse('true', [
                    'total_time' => $responseTime/1000000.0,
                    'response_headers' => [
                        'Content-type' => 'application/json',
                        'Pragma' => 'no-cache',
                        'Server' => 'mock-http-client',
                    ]
                ]);
            };

            $client = new MockHttpClient($callback);
        }

        return $this->processChildWebhook($webhook, $context, $client);
    }

    /**
     * @param Webhook $webhook
     * @param array $context
     * @param HttpClientInterface $client
     * @param int $nestingLevel
     *
     * @return HookResponse[]
     */
    private function processChildWebhook(Webhook $webhook, array $context, HttpClientInterface $client = null, int $nestingLevel = 0)
    {
        if ($nestingLevel >= 3) {
            return [new HookErrorResponse('Maximum webhook nesting level of 3 reached')];
        }

        $runtimeContext = new WebhookContext();
        $this->executor->setContext($runtimeContext);
        $this->executor->setLogger($logger = new WebhookLogger(LogLevel::DEBUG));
        $child = $response = $this->executor->executeWebhook($webhook, $context, $client);
        foreach ($response as $item) {
            $item->setLogs($logger->getLogs());
        }

        $logger->clearLogs();
        $this->executor->setContext(null);

        if (isset($runtimeContext[WebhookContext::CHILD_WEBHOOK])) {
            /** @var Webhook $childHook */
            foreach ($runtimeContext[WebhookContext::CHILD_WEBHOOK] as [$childHook, $childContext]) {
                if (null !== $childHook->getOwner() && $childHook->getVisibility() === Webhook::USER_VISIBLE && $childHook->getOwner() !== $webhook->getOwner()) {
                    $response[] = new HookErrorResponse('You can not call private webhooks of another user owner, please check nesting webhook visibility');
                    continue;
                }

                $context['parentResponse'] = reset($child);
                $child = $this->processChildWebhook($childHook, $childContext, $client, $nestingLevel+1);
                $response = array_merge($response, $child);
            }
        }

        return $response;
    }

    private function selectPackage(array &$data): void
    {
        if (!($data['package'] ?? null) instanceof Package) {
            $data['package'] = $this->registry
                ->getRepository(Package::class)
                ->findOneBy([]);
        }
    }

    private function selectVersion(array &$data): void
    {
        /** @var Package $package */
        if (!$package = $data['package']) {
            $data['versions'] = [];
            return;
        }

        $isStability = in_array($data['event'] ?? '', [Webhook::HOOK_RL_DELETE, Webhook::HOOK_RL_UPDATE, Webhook::HOOK_RL_NEW]);
        $collection = $package->getVersions()->filter(function (Version $version) use ($isStability) {
            return $isStability === false || !$version->isDevelopment();
        });

        if (isset($data['versions'])) {
            $versions = array_map('trim', explode(',', $data['versions']));
            $collection = $collection->filter(function (Version $version) use ($versions) {
                return in_array($version->getVersion(), $versions);
            });
            $data['versions'] = array_values($collection->toArray());
        } elseif ($ver = $collection->first()) {
            $data['versions'] = [$ver];
        } else {
            $data['versions'] = [];
        }
    }

    private function selectUser(array &$data): void
    {
        if (!($data['user'] ?? null) instanceof User) {
            if (null !== $this->tokenStorage) {
                if ($token = $this->tokenStorage->getToken()) {
                    $data['user'] = $token->getUser();
                    return;
                }
            }

            $data['user'] = $this->registry
                ->getRepository(User::class)
                ->findOneBy([]);
        }
    }

    private function selectPayload(array &$data)
    {
        if (isset($data['payload'])) {
            try {
                $payload = @json_decode($data['payload'], true);
                $data['payload'] = $payload ?: $data['payload'];
            } catch (\Throwable $exception) {}
        } else {
            $data['payload'] = [];
        }

        if (!isset($data['ip_address'])) {
            $data['ip_address'] = '127.0.0.1';
            if ($this->requestStack and $req = $this->requestStack->getMainRequest()) {
                $data['ip_address'] = $req->getClientIp();
            }
        }
    }
}
