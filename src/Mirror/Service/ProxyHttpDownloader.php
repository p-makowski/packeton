<?php

declare(strict_types=1);

namespace Packeton\Mirror\Service;

use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Pcre\Preg;
use Composer\Util\HttpDownloader;
use Packeton\Composer\PackagistFactory;
use Packeton\Mirror\Model\ProxyOptions;

class ProxyHttpDownloader
{
    public function __construct(private readonly PackagistFactory $packagistFactory)
    {
    }

    public function getHttpClient(ProxyOptions $options, ?IOInterface &$io = null): HttpDownloader
    {
        $config = $this->packagistFactory->createConfig();
        $io ??= new NullIO();

        if ($composer = $options->getComposerAuth()) {
            $config->merge(['config' => $composer]);
        }

        $http = new HttpDownloader($io, $config);
        $origin = \parse_url($options->getUrl(), \PHP_URL_HOST);

        if ($basic = $options->getAuthBasic()) {
            $io->setAuthentication($origin, $basic['username'], $basic['password']);
        }

        // capture username/password from URL if there is one
        if (Preg::isMatchStrictGroups('{^https?://([^:/]+):([^@/]+)@([^/]+)}i', $options->getUrl(), $match)) {
            $io->setAuthentication($origin, \rawurldecode($match[1]), \rawurldecode($match[2]));
        }

        $http->setOptions($options->http());

        return $http;
    }
}
