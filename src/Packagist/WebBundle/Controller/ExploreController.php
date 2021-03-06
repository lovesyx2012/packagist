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

namespace Packagist\WebBundle\Controller;

use Doctrine\DBAL\ConnectionException;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\PackageRepository;
use Packagist\WebBundle\Entity\VersionRepository;
use Pagerfanta\Adapter\FixedAdapter;
use Pagerfanta\Pagerfanta;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @Route("/explore")
 */
class ExploreController extends Controller
{
    /**
     * @Template()
     * @Route("/", name="browse")
     */
    public function exploreAction()
    {
        /** @var PackageRepository $pkgRepo */
        $pkgRepo = $this->getDoctrine()->getRepository('PackagistWebBundle:Package');
        /** @var VersionRepository $verRepo */
        $verRepo = $this->get('packagist.version_repository');
        $newSubmitted = $pkgRepo->getQueryBuilderForNewestPackages()->setMaxResults(10)
            ->getQuery()->useResultCache(true, 900, 'new_submitted_packages')->getResult();
        $newReleases = $verRepo->getLatestReleases(10);
        $randomIds = $this->getDoctrine()->getConnection()->fetchAll('SELECT id FROM package ORDER BY RAND() LIMIT 10');
        $random = $pkgRepo->createQueryBuilder('p')->where('p.id IN (:ids)')->setParameter('ids', $randomIds)->getQuery()->getResult();
        try {
            $popular = array();
            $popularIds = $this->get('snc_redis.default')->zrevrange('downloads:trending', 0, 9);
            if ($popularIds) {
                $popular = $pkgRepo->createQueryBuilder('p')->where('p.id IN (:ids)')->setParameter('ids', $popularIds)
                    ->getQuery()->useResultCache(true, 900, 'popular_packages')->getResult();
                usort($popular, function ($a, $b) use ($popularIds) {
                    return array_search($a->getId(), $popularIds) > array_search($b->getId(), $popularIds) ? 1 : -1;
                });
            }
        } catch (ConnectionException $e) {
            $popular = array();
        }

        $data = array(
            'newlySubmitted' => $newSubmitted,
            'newlyReleased' => $newReleases,
            'random' => $random,
            'popular' => $popular,
        );

        return $data;
    }

    /**
     * @Template()
     * @Route("/popular.{_format}", name="browse_popular", defaults={"_format"="html"})
     * @Cache(smaxage=900)
     */
    public function popularAction(Request $req)
    {
        try {
            $redis = $this->get('snc_redis.default');
            $perPage = $req->query->getInt('per_page', 15);
            if ($perPage <= 0 || $perPage > 100) {
                if ($req->getRequestFormat() === 'json') {
                    return new JsonResponse(array(
                        'status' => 'error',
                        'message' => 'The optional packages per_page parameter must be an integer between 1 and 100 (default: 15)',
                    ), 400);
                }

                $perPage = max(0, min(100, $perPage));
            }

            $popularIds = $redis->zrevrange(
                'downloads:trending',
                ($req->get('page', 1) - 1) * $perPage,
                $req->get('page', 1) * $perPage - 1
            );
            $popular = $this->getDoctrine()->getRepository('PackagistWebBundle:Package')
                ->createQueryBuilder('p')->where('p.id IN (:ids)')->setParameter('ids', $popularIds)
                ->getQuery()->useResultCache(true, 900, 'popular_packages')->getResult();
            usort($popular, function ($a, $b) use ($popularIds) {
                return array_search($a->getId(), $popularIds) > array_search($b->getId(), $popularIds) ? 1 : -1;
            });

            $packages = new Pagerfanta(new FixedAdapter($redis->zcard('downloads:trending'), $popular));
            $packages->setMaxPerPage($perPage);
            $packages->setCurrentPage($req->get('page', 1), false, true);
        } catch (ConnectionException $e) {
            $packages = new Pagerfanta(new FixedAdapter(0, array()));
        }

        $data = array(
            'packages' => $packages,
        );
        $data['meta'] = $this->getPackagesMetadata($data['packages']);

        if ($req->getRequestFormat() === 'json') {
            $result = array(
                'packages' => array(),
                'total' => $packages->getNbResults(),
            );

            /** @var Package $package */
            foreach ($packages as $package) {
                $url = $this->generateUrl('view_package', array('name' => $package->getName()), UrlGeneratorInterface::ABSOLUTE_URL);

                $result['packages'][] = array(
                    'name' => $package->getName(),
                    'description' => $package->getDescription() ?: '',
                    'url' => $url,
                    'downloads' => $data['meta']['downloads'][$package->getId()],
                    'favers' => $data['meta']['favers'][$package->getId()],
                );
            }

            if ($packages->hasNextPage()) {
                $params = array(
                    '_format' => 'json',
                    'page' => $packages->getNextPage(),
                );
                if ($perPage !== 15) {
                    $params['per_page'] = $perPage;
                }
                $result['next'] = $this->generateUrl('browse_popular', $params, UrlGeneratorInterface::ABSOLUTE_URL);
            }

            return new JsonResponse($result);
        }

        return $data;
    }
}
