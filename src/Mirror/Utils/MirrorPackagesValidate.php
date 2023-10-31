<?php

declare(strict_types=1);

namespace Packeton\Mirror\Utils;

use Packeton\Mirror\RemoteProxyRepository;
use Packeton\Mirror\Service\FetchPackageMetadataService;
use Packeton\Model\ProviderManager;

class MirrorPackagesValidate
{
    public function __construct(
        private readonly FetchPackageMetadataService $fetchMetadataService,
        private readonly ProviderManager $providerManager
    ) {
    }

    public function checkPackages(RemoteProxyRepository $repo, array $packages, array $enabled): array
    {
        $waiting = $valid = [];
        $excluded = $this->providerManager->getPackageNames();
        foreach ($packages as $package) {
            if ($meta = $this->getData($repo->findPackageMetadata($package)?->decodeJson(), $package)) {
                $valid[$package] = $meta;
            } else {
                $waiting[] = $package;
            }
        }

        if ($waiting) {
            $resolved = $this->fetchMetadataService->fetchPackageMetadata($waiting, $repo, providers: $repo->lookupAllProviders());
            foreach ($resolved as $package => $meta) {
                if ($meta = $this->getData($meta, $package)) {
                    $valid[$package] = $meta;
                }
            }
        }
        $validNames = \array_keys($valid);

        $errors = $newData = $updateData = [];
        if ($intersect = \array_intersect($validNames, $excluded)) {
            $errors[] = \sprintf(
                '<b>IMPORTANT</b> The packages "%s" has been already registered in the your private repository. '
                . 'The attacker may add backdoors in your dependencies, please review or remove this package form approve list.',
                \implode(',', $intersect)
            );
        }

        foreach ($valid as $package => $meta) {
            if (\in_array($package, $enabled, true)) {
                $updateData[] = $meta;
            } else {
                $newData[] = $meta;
            }
        }

        return [
            'validData' => \array_values($valid),
            'invalid' => \array_values(\array_diff($packages, \array_keys($valid))),
            'valid' => \array_keys($valid),
            'errors' => $errors,
            'newData' => $newData,
            'updateData' => $updateData,
        ];
    }

    private function getData(?array $data, string $package): ?array
    {
        if (isset($data['packages'][$package])) {
            $data = $data['packages'][$package];
        }
        if (!is_array($data)) {
            return null;
        }

        $item = $data['dev-master'] ?? end($data);

        return [
            'name' => $package,
            'license' => \json_encode($item['license'] ?? null),
            'description' => $item['description'] ?? null,
        ];
    }
}
