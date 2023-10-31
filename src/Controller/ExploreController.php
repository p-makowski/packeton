<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packeton\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Package;
use Packeton\Entity\Version;
use Packeton\Model\DownloadManager;
use Packeton\Model\FavoriteManager;
use Packeton\Repository\PackageRepository;
use Packeton\Repository\VersionRepository;
use Packeton\Service\SubRepositoryHelper;
use Pagerfanta\Adapter\FixedAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/explore')]
class ExploreController extends AbstractController
{
    use ControllerTrait;

    public function __construct(
        protected ManagerRegistry $registry,
        protected DownloadManager $downloadManager,
        protected FavoriteManager $favoriteManager,
        protected SubRepositoryHelper $subRepositoryHelper,
    ) {
    }

    #[Route('', name: 'browse')]
    public function exploreAction(\Redis $redis): Response
    {
        /** @var PackageRepository $pkgRepo */
        $pkgRepo = $this->registry->getRepository(Package::class);
        /** @var VersionRepository $verRepo */
        $verRepo = $this->registry->getRepository(Version::class);
        $allowed = $this->subRepositoryHelper->allowedPackageIds();

        $newSubmitted = $this->subRepositoryHelper
            ->applySubRepository($pkgRepo->getQueryBuilderForNewestPackages())
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $newReleases = $verRepo->getLatestReleases(10, $allowed);
        $maxId = $this->getEM()->getConnection()->fetchOne('SELECT max(id) FROM package');
        $random = $this->subRepositoryHelper
            ->applySubRepository($pkgRepo->createQueryBuilder('p'))
            ->andWhere('p.id >= :randId')
            ->andWhere('p.abandoned = :abandoned')
            ->setParameter('randId', rand(1, $maxId))
            ->setParameter('abandoned', false)
            ->setMaxResults(10)
            ->getQuery()->getResult();

        $popular = [];
        $popularIds = $redis->zrevrange('downloads:trending', 0, 9);
        if ($popularIds) {
            $popular = $this->subRepositoryHelper
                ->applySubRepository($pkgRepo->createQueryBuilder('p'))
                ->andWhere('p.id IN (:ids)')
                ->setParameter('ids', $popularIds)
                ->getQuery()->getResult();
            usort($popular, function ($a, $b) use ($popularIds) {
                return array_search($a->getId(), $popularIds) > array_search($b->getId(), $popularIds) ? 1 : -1;
            });
        }

        return $this->render('explore/explore.html.twig', [
            'newlySubmitted' => $newSubmitted,
            'newlyReleased' => $newReleases,
            'random' => $random,
            'popular' => $popular,
        ]);
    }


    #[Route('popular.{_format}', name: 'browse_popular', defaults: ['_format' => 'html'])]
    public function popularAction(Request $req, \Redis $redis): Response
    {
        $perPage = $req->query->getInt('per_page', 15);
        if ($perPage <= 0 || $perPage > 100) {
            if ($req->getRequestFormat() === 'json') {
                return new JsonResponse([
                    'status' => 'error',
                    'message' => 'The optional packages per_page parameter must be an integer between 1 and 100 (default: 15)',
                ], 400);
            }

            $perPage = max(0, min(100, $perPage));
        }

        $popularIds = $redis->zrevrange(
            'downloads:trending',
            ($req->get('page', 1) - 1) * $perPage,
            $req->get('page', 1) * $perPage - 1
        );
        $qb = $this->registry->getRepository(Package::class)->createQueryBuilder('p');
        $popular = $this->subRepositoryHelper->applySubRepository($qb)
            ->andWhere('p.id IN (:ids)')
            ->setParameter('ids', $popularIds)
            ->getQuery()->getResult();
        usort($popular, function ($a, $b) use ($popularIds) {
            return array_search($a->getId(), $popularIds) > array_search($b->getId(), $popularIds) ? 1 : -1;
        });

        $packages = new Pagerfanta(new FixedAdapter($redis->zcard('downloads:trending'), $popular));
        $packages->setMaxPerPage((int)$perPage);
        $packages->setCurrentPage((int)$req->get('page', 1));

        $data = ['packages' => $packages];
        $data['meta'] = $this->getPackagesMetadata($data['packages']);

        if ($req->getRequestFormat() === 'json') {
            $result = [
                'packages' => [],
                'total' => $packages->getNbResults(),
            ];

            /** @var Package $package */
            foreach ($packages as $package) {
                $url = $this->generateUrl('view_package', ['name' => $package->getName()], UrlGeneratorInterface::ABSOLUTE_URL);
                $result['packages'][] = [
                    'name' => $package->getName(),
                    'description' => $package->getDescription() ?: '',
                    'url' => $url,
                    'downloads' => $data['meta']['downloads'][$package->getId()],
                    'favers' => $data['meta']['favers'][$package->getId()],
                ];
            }

            if ($packages->hasNextPage()) {
                $params = [
                    '_format' => 'json',
                    'page' => $packages->getNextPage(),
                ];
                if ($perPage !== 15) {
                    $params['per_page'] = $perPage;
                }
                $result['next'] = $this->generateUrl('browse_popular', $params, UrlGeneratorInterface::ABSOLUTE_URL);
            }

            return new JsonResponse($result);
        }

        return $this->render('explore/popular.html.twig', $data);
    }
}
