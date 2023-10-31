<?php

declare(strict_types=1);

namespace Packeton\Webhook;

use Packeton\Entity\Webhook;
use Packeton\Webhook\Twig\ContextAwareInterface;
use Packeton\Webhook\Twig\PayloadRenderer;
use Packeton\Webhook\Twig\PlaceholderContext;
use Packeton\Webhook\Twig\PlaceholderExtension;
use Packeton\Webhook\Twig\WebhookContext;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

class RequestResolver implements ContextAwareInterface, LoggerAwareInterface
{
    private $logger;

    public function __construct(private readonly PayloadRenderer $renderer)
    {
    }

    /**
     * @param Webhook $webhook
     * @param array $context
     *
     * @return HookRequest[]
     */
    public function resolveHook(Webhook $webhook, array $context = []): array
    {
        return iterator_to_array($this->doResolveHook($webhook, $context));
    }

    /**
     * @param Webhook $webhook
     * @param array $context
     *
     * @return \Generator|void
     */
    private function doResolveHook(Webhook $webhook, array $context = []): iterable
    {
        $context[PlaceholderExtension::VARIABLE_NAME] = $placeholder = new PlaceholderContext();
        $this->renderer->setLogger($this->logger);

        $content = null;
        if ($payload = $webhook->getPayload()) {
            $legacy = '';
            $this->renderer->setLogHandler(static function ($result) use (&$legacy) {
                if (is_string($result)) {
                    $legacy .= $result;
                }
            });

            $result = $this->renderer->execute(trim($payload), $context);
            $result = is_string($result) ? trim($result) : $result;

            $content = $result === null ? trim($legacy) : $result;
        }

        $content = [$webhook->getUrl(), $webhook->getOptions()['headers'] ?? null, $content === '' ? null : $content];

        foreach ($placeholder->walkContent($content) as $content) {
            [$url, $headers, $content] = $content;
            $options = $webhook->getOptions() ?: [];
            if ($headers) {
                $options['headers'] = $headers;
            }

            yield new HookRequest($url, $webhook->getMethod(), $options, $content);
        }
    }

    /**
     * @inheritDoc
     */
    public function setContext(WebhookContext $context = null): void
    {
        $this->renderer->setContext($context);
    }

    /**
     * @inheritDoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
