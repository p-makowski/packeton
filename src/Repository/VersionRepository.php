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

namespace Packeton\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;
use Doctrine\DBAL\Connection;
use Packeton\Entity\Package;
use Packeton\Entity\Version;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class VersionRepository extends EntityRepository
{
    protected $supportedLinkTypes = [
        'require',
        'conflict',
        'provide',
        'replace',
        'devRequire',
        'suggest',
    ];

    public function remove(Version|array $versions)
    {
        $versions = !is_array($versions) ? [$versions] : $versions;
        if (empty($versions)) {
            return;
        }

        $param = ['id' => []];
        $em = $this->getEntityManager();
        $conn = $em->getConnection();
        $package = $versions[0]->getPackage();

        foreach ($versions as $ver) {
            $param['id'][] = $ver->getId();
            $package->getVersions()->removeElement($ver);
            $em->remove($ver);
        }

        $types = ['id' => ArrayParameterType::INTEGER];

        $conn->executeQuery('DELETE FROM version_author WHERE version_id IN (:id)', $param, $types);
        $conn->executeQuery('DELETE FROM version_tag WHERE version_id IN (:id)', $param, $types);
        $conn->executeQuery('DELETE FROM link_suggest WHERE version_id IN (:id)', $param, $types);
        $conn->executeQuery('DELETE FROM link_conflict WHERE version_id IN (:id)', $param, $types);
        $conn->executeQuery('DELETE FROM link_replace WHERE version_id IN (:id)', $param, $types);
        $conn->executeQuery('DELETE FROM link_provide WHERE version_id IN (:id)', $param, $types);
        $conn->executeQuery('DELETE FROM link_require_dev WHERE version_id IN (:id)', $param, $types);
        $conn->executeQuery('DELETE FROM link_require WHERE version_id IN (:id)', $param, $types);
    }

    public function refreshVersions($versions)
    {
        $versionIds = [];
        foreach ($versions as $version) {
            $versionIds[] = $version->getId();
            $this->getEntityManager()->detach($version);
        }

        $refreshedVersions = $this->findBy(['id' => $versionIds]);
        $versionsById = [];
        foreach ($refreshedVersions as $version) {
            $versionsById[$version->getId()] = $version;
        }

        $refreshedVersions = [];
        foreach ($versions as $version) {
            $refreshedVersions[] = $versionsById[$version->getId()];
        }

        return $refreshedVersions;
    }

    public function getVersionData(array $versionIds)
    {
        $links = [
            'require' => 'link_require',
            'devRequire' => 'link_require_dev',
            'suggest' => 'link_suggest',
            'conflict' => 'link_conflict',
            'provide' => 'link_provide',
            'replace' => 'link_replace',
        ];

        $result = [];
        foreach ($versionIds as $id) {
            $result[$id] = [
                'require' => [],
                'devRequire' => [],
                'suggest' => [],
                'conflict' => [],
                'provide' => [],
                'replace' => [],
                'keywords' => []
            ];
        }

        foreach ($links as $link => $table) {
            $rows = $this->getConn()->fetchAllAssociative(
                'SELECT version_id, packageName as name, packageVersion as version FROM '.$table.' WHERE version_id IN (:ids)',
                ['ids' => $versionIds],
                ['ids' => ArrayParameterType::INTEGER]
            );
            foreach ($rows as $row) {
                $result[$row['version_id']][$link][] = $row;
            }
        }

        $rows = $this->getConn()->fetchAllAssociative(
            'SELECT va.version_id, name, email, homepage, role FROM author a JOIN version_author va ON va.author_id = a.id WHERE va.version_id IN (:ids)',
            ['ids' => $versionIds],
            ['ids' => ArrayParameterType::INTEGER]
        );
        foreach ($rows as $row) {
            $versionId = $row['version_id'];
            unset($row['version_id']);
            $result[$versionId]['authors'][] = array_filter($row);
        }

        $keywords = $this->createQueryBuilder('v')
            ->resetDQLPart('select')
            ->select(['v.id as version_id', 'tag.name'])
            ->leftJoin('v.tags', 'tag')
            ->where('v.id  IN (:ids)')
            ->setParameter('ids', $versionIds)
            ->getQuery()->getArrayResult();
        foreach ($keywords as $row) {
            $result[$row['version_id']]['keywords'][] = $row['name'];
        }

        return $result;
    }

    public function getVersionMetadataForUpdate(Package $package)
    {
        $rows = $this->getConn()->fetchAllAssociative(
            'SELECT id, normalizedVersion as normalized_version, source, dist, softDeletedAt as soft_deleted_at FROM package_version v WHERE v.package_id = :id',
            ['id' => $package->getId()]
        );

        $versions = [];
        foreach ($rows as $row) {
            $row['source'] = $row['source'] ? json_decode($row['source'], true) : null;
            $row['dist'] = $row['dist'] ? json_decode($row['dist'], true) : null;
            $versions[strtolower($row['normalized_version'])] = $row;
        }

        return $versions;
    }

    public function getFullVersion($versionId)
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('v', 't', 'a')
            ->from(Version::class, 'v')
            ->leftJoin('v.tags', 't')
            ->leftJoin('v.authors', 'a')
            ->where('v.id = :id')
            ->setParameter('id', $versionId);

        return $qb->getQuery()->getSingleResult();
    }

    /**
     * Returns the latest versions released
     *
     * @param string $vendor optional vendor filter
     * @param string $package optional vendor/package filter
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function getQueryBuilderForLatestVersionWithPackage($vendor = null, $package = null)
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('v')
            ->from(Version::class, 'v')
            ->where('v.development = false')
            ->andWhere('v.releasedAt <= ?0')
            ->orderBy('v.releasedAt', 'DESC');
        $qb->setParameter(0, date('Y-m-d H:i:s'));

        if ($vendor || $package) {
            $qb->innerJoin('v.package', 'p')
                ->addSelect('p');
        }

        if ($vendor) {
            $qb->andWhere('p.name LIKE ?1');
            $qb->setParameter(1, $vendor.'/%');
        } elseif ($package) {
            $qb->andWhere('p.name = ?1')
                ->setParameter(1, $package);
        }

        return $qb;
    }

    public function getLatestReleases($count = 10, ?array $allowed = null)
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('v.name, v.version, v.description')
            ->from(Version::class, 'v')
            ->where('v.development = false')
            ->andWhere('v.releasedAt < :now')
            ->orderBy('v.releasedAt', 'DESC')
            ->setMaxResults($count)
            ->setParameter('now', date('Y-m-d H:i:s'));
        if (null !== $allowed) {
            $qb->andWhere('IDENTITY(v.package) IN (:ids)')->setParameter('ids', $allowed ?: [-1]);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @param string $package
     * @param string $version
     *
     * @return string|null
     */
    public function getPreviousRelease(string $package, string $version)
    {
        $versions = $this->createQueryBuilder('v')
            ->resetDQLPart('select')
            ->select('v.version')
            ->where('v.development = false')
            ->andWhere('v.name = :name')
            ->setParameter('name', $package)
            ->getQuery()
            ->getResult();
        $result = null;
        $versions = $versions ? array_column($versions, 'version') : null;
        foreach ($versions as $candidate) {
            if (version_compare($version, $candidate) <= 0) {
                continue;
            }
            if ($result === null || version_compare($result, $candidate) < 0) {
                $result = $candidate;
            }
        }

        return $result;
    }

    public function getVersionStatisticsByMonthAndYear()
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb
            ->select(
                [
                    'COUNT(v.id) as vcount',
                    'YEAR(v.releasedAt) as year',
                    'MONTH(v.releasedAt) as month'
                ]
            )
            ->from(Version::class, 'v')
            ->groupBy('year, month');

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Connection
     */
    protected function getConn(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
