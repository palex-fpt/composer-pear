<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Repository\Pear;

/**
 * Read PEAR packages using REST 1.1 interface
 *
 * At version 1.1 we read package descriptions from:
 *  {baseUrl}/c/categories.xml
 *  {baseUrl}/c/{category}/packagesinfo.xml
 *
 * @author Alexey Prilipko <palex@farpost.com>
 */
class ChannelRest11Reader extends BaseChannelReader
{
    private $dependencyReader;

    public function __construct($rfs)
    {
        parent::__construct($rfs);

        $this->dependencyReader = new PackageDependencyParser();
    }

    /**
     * Reads package descriptions using PEAR Rest 1.1 interface
     *
     * @param $baseUrl  string base Url interface
     *
     * @return array of package info
     */
    public function read($baseUrl)
    {
        return $this->readChannelPackages($baseUrl);
    }

    /**
     * Read list of channel categories from
     *  {baseUrl}/c/categories.xml
     *
     * @param $baseUrl string
     * @return array of package info
     */
    private function readChannelPackages($baseUrl)
    {
        $result = array();

        $xml = $this->requestXml($baseUrl, "/c/categories.xml");
        $xml->registerXPathNamespace('ns', self::allCategoriesNS);
        foreach ($xml->xpath('ns:c') as $node) {
            $categoryName = (string) $node;
            $categoryPackages = $this->readCategoryPackages($baseUrl, $categoryName);
            $result = array_merge($result, $categoryPackages);
        }

        return $result;
    }

    /**
     * Read packages from
     *  {baseUrl}/c/{category}/packagesinfo.xml
     *
     * @param $baseUrl      string
     * @param $categoryName string
     * @return array of package info
     */
    private function readCategoryPackages($baseUrl, $categoryName)
    {
        $result = array();

        $categoryPath = '/c/'.urlencode($categoryName).'/packagesinfo.xml';
        $xml = $this->requestXml($baseUrl, $categoryPath);
        $xml->registerXPathNamespace('ns', self::categoryPackagesInfoNS);
        foreach ($xml->xpath('ns:pi') as $node) {
            $packageInfo = $this->parsePackage($node);
            $result[] = $packageInfo;
        }

        return $result;
    }

    /**
     * Parses package node.
     *
     * @param $packageInfo  \SimpleXMLElement   xml element describing package
     * @return array package info
     */
    private function parsePackage($packageInfo)
    {
        $result = array();

        $packageInfo->registerXPathNamespace('ns', self::categoryPackagesInfoNS);
        $channelName = (string) $packageInfo->p->c;
        $packageName = (string) $packageInfo->p->n;
        $license = (string) $packageInfo->p->l;
        $shortDescription = (string) $packageInfo->p->s;
        $description = (string) $packageInfo->p->d;

        $versions = array();
        foreach ($packageInfo->xpath('ns:a/ns:r') as $node) {
            $releaseVersion = (string) $node->v;
            $releaseStability = (string) $node->s;

            $versions[$releaseVersion] = array(
                'stability' => $releaseStability,
                'dependencies' => false,
            );
        }
        foreach ($packageInfo->xpath('ns:deps') as $node) {
            $dependencyVersion = (string) $node->v;
            $dependencyArray = unserialize((string) $node->d);

            $dependencyInfo = $this->dependencyReader->buildDependencyInfo($dependencyArray);

            $versions[$dependencyVersion]['dependencies'] = $dependencyInfo;
        }

        return array(
            'channel' => $channelName,
            'package' => $packageName,
            'license' => $license,
            'shortDescription' => $shortDescription,
            'description' => $description,
            'versions' => $versions,
        );
    }
}
