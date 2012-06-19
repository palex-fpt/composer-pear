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
 * Read PEAR packages using REST 1.0 interface
 *
 * @author Alexey Prilipko <palex@farpost.com>
 */
class PackageDependencyParser
{
    /**
     * Builds dependency information from package.xml 1.0 format
     *
     * http://pear.php.net/manual/en/guide.developers.package2.dependencies.php
     *
     * @param $depArray array Dependency data in package.xml 1.0 format
     * @return array of { 'type', 'constraint', 'channel', 'name' }
     */
    private function buildDependency10Info($depArray)
    {
        static $dep10rels = array('has'=>'==', 'eq' => '==', 'ge' => '>=', 'gt' => '>', 'le' => '<=', 'lt' => '<', 'not' => 'conflicts');

        $result = array();

        foreach ($depArray as $depItem) {
            if (empty($depItem['rel']) || !array_key_exists($depItem['rel'], $dep10rels)) {
                // 'unknown rel type:' . $depItem['rel'];
                continue;
            }

            $depType = !empty($depItem['optional']) && 'yes' == $depItem['optional']
                ? 'optional'
                : 'required';
            $depType = 'not' == $depItem['rel']
                ? 'conflicts'
                : $depType;

            $depVersion = !empty($depItem['version']) ? $this->parseVersion($depItem['version']) : '*';

            $depVersionConstraint = ('has' == $depItem['rel'] || 'not' == $depItem['rel']) && '*' == $depVersion
                ? '*'
                : $dep10rels[$depItem['rel']] . $this->parseVersion($depVersion);

            switch ($depItem['type']) {
                case 'php':
                    $depChannelName = 'php';
                    $depPackageName = '';
                    break;
                case 'pkg':
                    $depChannelName = !empty($depItem['channel']) ? $depItem['channel'] : 'pear.php.net';
                    $depPackageName = $depItem['name'];
                    break;
                case 'ext':
                    $depChannelName = 'ext';
                    $depPackageName = $depItem['name'];
                    break;
                case 'os':
                case 'sapi':
                    $depChannelName = '';
                    $depPackageName = '';
                break;
                default:
                    $depChannelName = '';
                    $depPackageName = '';
                    break;
            }

            if ('' != $depChannelName) {
                $result[] = array(
                    'type' => $depType,
                    'constraint' => $depVersionConstraint,
                    'channel' => $depChannelName,
                    'name' => $depPackageName,
                );
            }
        }

        return $result;
    }

    /**
     * Builds dependency information from package.xml 2.0 format
     *
     * @param $depArray array Dependency data in package.xml 1.0 format
     * @return array of { 'type', 'constraint', 'channel', 'name' }
     */
    private function buildDependency20Info($depArray)
    {
        $result = array();
        foreach ($depArray as $depType => $depTypeGroup) {
            if (!is_array($depTypeGroup)) {
                continue;
            }
            if ('required' == $depType || 'optional' == $depType) {
                foreach ($depTypeGroup as $depItemType => $depItem) {
                    switch ($depItemType) {
                        case 'php':
                            $result[] = array(
                                'type' => $depType,
                                'constraint' => $this->parse20VersionConstraint($depItem),
                                'channel' => 'php',
                                'name' => '',
                            );
                            break;
                        case 'package':
                            $deps = $this->buildDepPackageConstraints($depItem, $depType);
                            $result = array_merge($result, $deps);
                            break;
                        case 'extension':
                            $deps = $this->buildDepExtensionConstraints($depItem, $depType);
                            $result = array_merge($result, $deps);
                            break;
                        case 'subpackage':
                            $deps = $this->buildDepPackageConstraints($depItem, 'replaces');
                            $result = array_merge($result, $deps);
                            break;
                        case 'os':
                        case 'pearinstaller':
                            break;
                        default:
                            break;
                    }
                }
            } elseif ('group' == $depType) {
                if ($this->isHash($depTypeGroup)) {
                    $depTypeGroup = array($depTypeGroup);
                }

                foreach ($depTypeGroup as $depItem) {
                    $item = isset($depItem['subpackage']) ? $depItem['subpackage'] : $depItem['package'];
                    $deps = $this->buildDepPackageConstraints($item, 'replaces');
                    $result = array_merge($result, $deps);
                }
            }
        }

        return $result;
    }

    /**
     * Builds dependency constraint of 'extension' type
     *
     * @param $depItem array dependency constraint or array of dependency constraints
     * @param $depType string target type of building constraint.
     * @return array of { 'type', 'constraint', 'channel', 'name' }
     */
    private function buildDepExtensionConstraints($depItem, $depType)
    {
        if ($this->isHash($depItem)) {
            $depItem = array($depItem);
        }

        $result = array();
        foreach ($depItem as $subDepItem) {
            $depChannelName = 'ext';
            $depPackageName = $subDepItem['name'];
            $depVersionConstraint = $this->parse20VersionConstraint($subDepItem);

            $result[] = array(
                'type' => $depType,
                'constraint' => $depVersionConstraint,
                'channel' => $depChannelName,
                'name' => $depPackageName,
            );
        }

        return $result;
    }

    /**
     * Builds dependency constraint of 'package' type
     *
     * @param $depItem array dependency constraint or array of dependency constraints
     * @param $depType string target type of building constraint.
     * @return array of { 'type', 'constraint', 'channel', 'name' }
     */
    private function buildDepPackageConstraints($depItem, $depType)
    {
        if ($this->isHash($depItem)) {
            $depItem = array($depItem);
        }

        $result = array();
        foreach ($depItem as $subDepItem) {
            $depChannelName = $subDepItem['channel'];
            $depPackageName = $subDepItem['name'];
            $depVersionConstraint = $this->parse20VersionConstraint($subDepItem);
            if (isset($subDepItem['conflicts'])) {
                $depType = 'conflicts';
            }

            $result[] = array(
                'type' => $depType,
                'constraint' => $depVersionConstraint,
                'channel' => $depChannelName,
                'name' => $depPackageName,
            );
        }

        return $result;
    }

    /**
     * Builds dependency information. It detects used package.xml format.
     *
     * @param $depArray array
     * @return array of { 'type', 'constraint', 'channel', 'name' }
     */
    public function buildDependencyInfo($depArray)
    {
        if (false === $depArray) {
            return array();
        } elseif (!$this->isHash($depArray)) {
            return $this->buildDependency10Info($depArray);
        } else {
            return $this->buildDependency20Info($depArray);
        }
    }

    /**
     * Parses version constraint
     *
     * @param  array  $data
     * @return string
     */
    private function parse20VersionConstraint(array $data)
    {
        static $dep20rels = array('has'=>'==', 'min' => '>=', 'max' => '<=', 'exclude' => '<>');

        $versions = array();
        $values = array_intersect_key($data, $dep20rels);
        if (0 == count($values)) {
            return '*';
        } elseif (isset($values['min']) && isset($values['exclude']) && $data['min'] == $data['exclude']) {
            $versions[] = '>' . $this->parseVersion($values['min']);
        } elseif (isset($values['max']) && isset($values['exclude']) && $data['max'] == $data['exclude']) {
            $versions[] = '<' . $this->parseVersion($values['max']);
        } else {
            foreach ($values as $op => $version) {
                if ('exclude' == $op && is_array($version)) {
                    foreach ($version as $versionPart) {
                        $versions[] = $dep20rels[$op] . $this->parseVersion($versionPart);
                    }
                } else {
                    $versions[] = $dep20rels[$op] . $this->parseVersion($version);
                }
            }
        }

        return implode(',', $versions);
    }

    /**
     * Softened version parser
     *
     * @param $version
     * @return bool|string
     */
    private function parseVersion($version)
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

    /**
     * Test if array is associative or hash type
     *
     * @param  array $array
     * @return bool
     */
    private function isHash(array $array)
    {
        return !array_key_exists(1, $array) && !array_key_exists(0, $array);
    }
}
