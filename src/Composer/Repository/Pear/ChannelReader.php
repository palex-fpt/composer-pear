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

use Composer\Util\RemoteFilesystem;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\Link;

/**
 * PEAR Channel package reader.
 *
 * Reads channel packages info from and builds MemoryPackage's
 *
 * @author Alexey Prilipko <palex@farpost.com>
 */
class ChannelReader extends BaseChannelReader
{
    /** @var array of ('xpath test' => 'rest implementation') */
    private $readerMap;

    public function __construct(RemoteFilesystem $rfs)
    {
        parent::__construct($rfs);

        $rest10reader = new ChannelRest10Reader($rfs);
        $rest11reader = new ChannelRest11Reader($rfs);

        $this->readerMap = array(
            'REST1.3' => $rest11reader,
            'REST1.2' => $rest11reader,
            'REST1.1' => $rest11reader,
            'REST1.0' => $rest10reader,
        );
    }

    /**
     * Reads channel supported REST interfaces and selects one of them
     *
     * @param $channelXml \SimpleXMLElement
     * @param $supportedVersions string[] supported PEAR REST protocols
     * @return array|bool hash with selected version and baseUrl
     */
    private function selectRestVersion($channelXml, $supportedVersions)
    {
        $channelXml->registerXPathNamespace('ns', self::channelNS);

        foreach ($supportedVersions as $version) {
            $xpathTest = "ns:servers/ns:primary/ns:rest/ns:baseurl[@type='{$version}']";
            $testResult = $channelXml->xpath($xpathTest);
            if (count($testResult) > 0) {
                return array('version' => $version, 'baseUrl' => (string) $testResult[0]);
            }
        }

        return false;
    }

    /**
     * Reads PEAR channel through REST interface and builds list of packages
     *
     * @param $url string PEAR Channel url
     * @return array of Composer\Package\PackageInterface
     */
    public function read($url)
    {
        $xml = $this->requestXml($url, "/channel.xml");

        $channelName = (string) $xml->name;
        $channelSummary = (string) $xml->summary;
        $channelAlias = (string) $xml->suggestedalias;

        $supportedVersions = array_keys($this->readerMap);
        $selectedRestVersion = $this->selectRestVersion($xml, $supportedVersions);
        if (false === $selectedRestVersion) {
            throw new \UnexpectedValueException(sprintf('PEAR repository $s does not supports any of %s protocols.', $url, implode(', ', $supportedVersions)));
        }

        $reader = $this->readerMap[$selectedRestVersion['version']];
        $packageDefinitions = $reader->read($selectedRestVersion['baseUrl']);

        return $this->buildComposerPackages($channelName, $channelAlias, $packageDefinitions);
    }

    /**
     * Builds MemoryPackages from PEAR package definition data.
     *
     * @param $channelName          string channel name
     * @param $channelAlias         string channel alias
     * @param $packageDefinitions   array  package definition
     * @return array
     */
    private function buildComposerPackages($channelName, $channelAlias, $packageDefinitions)
    {
        $versionParser = new \Composer\Package\Version\VersionParser();
        $result = array();
        foreach ($packageDefinitions as $packageDefinition) {
            $packageChannelName = $packageDefinition['channel'];
            $packageName = $packageDefinition['package'];
            $license = $packageDefinition['license'];
            $shortDescription = $packageDefinition['shortDescription'];
            $description = $packageDefinition['description'];
            $versions = $packageDefinition['versions'];

            foreach ($versions as $version => $versionData) {
                if (false === $versionData['dependencies']) {
                    continue; // skip packages without releases
                }

                $normalizedVersion = $this->parseVersion($version);
                if (false === $normalizedVersion) {
                    continue; // skip packages with unparsable versions
                }

                $composerPackageName = $this->buildComposerPackageName($packageChannelName, $packageName);

                // distribution url must be read from /r/{packageName}/{version}.xml::/r/g:text()
                $distUrl = "http://{$channelName}/get/{$packageName}-{$version}.tgz";

                $requires = array();
                $suggests = array();
                $conflicts = array();
                $replaces = array();

                // alias package only when its channel matches repository channel,
                // cause we've know only repository channel alias
                if ($channelName == $packageChannelName) {
                    $composerPackageAlias = $this->buildComposerPackageName($channelAlias, $packageName);
                    $aliasConstraint = new VersionConstraint('==', $normalizedVersion);
                    $aliasLink = new Link($composerPackageName, $composerPackageAlias, $aliasConstraint, 'replaces', (string) $aliasConstraint);
                    $replaces[] = $aliasLink;
                }

                foreach ($versionData['dependencies'] as $dependencyInfo) {
                    $dependencyPackageName = $this->buildComposerPackageName($dependencyInfo['channel'], $dependencyInfo['name']);
                    $constraint = $versionParser->parseConstraints($dependencyInfo['constraint']);
                    $link = new Link($composerPackageName, $dependencyPackageName, $constraint, $dependencyInfo['type'], $dependencyInfo['constraint']);
                    switch ($dependencyInfo['type']) {
                        case 'required':
                            $requires[] = $link;
                            break;
                        case 'conflicts':
                            $conflicts[] = $link;
                            break;
                        case 'replaces':
                            $replaces[] = $link;
                            break;
                        case 'optional':
                            $suggests[] = $dependencyPackageName;
                            break;
                    }
                }

                $package = new \Composer\Package\MemoryPackage($composerPackageName, $normalizedVersion, $version);
                $package->setType('library');
                $package->setDescription($description);
                $package->setDistType('pear');
                $package->setDistUrl($distUrl);
                $package->setAutoload(array('classmap' => array('')));
                $package->setIncludePaths(array('/'));
                $package->setRequires($requires);
                $package->setConflicts($conflicts);
                $package->setSuggests($suggests);
                $package->setReplaces($replaces);
                $result[] = $package;
            }
        }

        return $result;
    }

    private function buildComposerPackageName($pearChannelName, $pearPackageName)
    {
        if ($pearChannelName == 'php') {
            return "php";
        } elseif ($pearChannelName == 'ext') {
            return "ext-{$pearPackageName}";
        } else

            return "pear-{$pearChannelName}/{$pearPackageName}";
    }

    protected function parseVersion($version)
    {
        if (preg_match('{^v?(\d{1,3})(\.\d+)?(\.\d+)?(\.\d+)?}i', $version, $matches)) {
            $version = $matches[1]
                .(!empty($matches[2]) ? $matches[2] : '.0')
                .(!empty($matches[3]) ? $matches[3] : '.0')
                .(!empty($matches[4]) ? $matches[4] : '.0');

            return $version;
        } else {
            return false;
        }
    }
}
