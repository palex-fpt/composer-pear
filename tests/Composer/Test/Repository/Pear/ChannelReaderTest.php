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

use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\Link;
use Composer\Package\MemoryPackage;
use Composer\Test\Mock\RemoteFilesystemMock;

class ChannelReaderTest extends \PHPUnit_Framework_TestCase
{
    public function testShouldBuildPackagesFromPearSchema()
    {
        $rfs = new RemoteFilesystemMock(array(
            'http://pear.net/channel.xml' => file_get_contents(__DIR__ . '/Fixtures/channel.1.1.xml'),
            'http://test.loc/rest11/c/categories.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.1/categories.xml'),
            'http://test.loc/rest11/c/Default/packagesinfo.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.1/packagesinfo.xml'),
        ));

        $reader = new \Composer\Repository\Pear\ChannelReader($rfs);

        /** @var $packages \Composer\Package\PackageInterface[] */
        $packages = $reader->read('http://pear.net/');

        $this->assertCount(2, $packages);
        $this->assertEquals('pear-pear.net/http_client', $packages[0]->getName());
        $this->assertEquals('pear-pear.net/http_request', $packages[1]->getName());
    }

    public function testShouldSelectCorrectReader()
    {
        $rfs = new RemoteFilesystemMock(array(
            'http://pear.1.0.net/channel.xml' => file_get_contents(__DIR__ . '/Fixtures/channel.1.0.xml'),
            'http://test.loc/rest10/p/packages.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.0/packages.xml'),
            'http://test.loc/rest10/p/http_client/info.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.0/http_client_info.xml'),
            'http://test.loc/rest10/p/http_request/info.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.0/http_request_info.xml'),
            'http://pear.1.1.net/channel.xml' => file_get_contents(__DIR__ . '/Fixtures/channel.1.1.xml'),
            'http://test.loc/rest11/c/categories.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.1/categories.xml'),
            'http://test.loc/rest11/c/Default/packagesinfo.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.1/packagesinfo.xml'),
        ));

        $reader = new \Composer\Repository\Pear\ChannelReader($rfs);

        $reader->read('http://pear.1.0.net/');
        $reader->read('http://pear.1.1.net/');
    }

    public function testShouldCreatePackages()
    {
        $reader = $this->getMockBuilder('\Composer\Repository\Pear\ChannelReader')
            ->disableOriginalConstructor()
            ->getMock();

        $ref = new \ReflectionMethod($reader, 'buildComposerPackages');
        $ref->setAccessible(true);

        $packageInfo = array(
            'channel' => 'test.loc',
            'package' => 'sample',
            'license' => 'license',
            'shortDescription' => 'shortDescription',
            'description' => 'description',
            'versions' => array(
                '1.0.0.1' => array(
                    'dependencies' => array(
                        array(
                            'type' => 'required',
                            'constraint' => '>5.2',
                            'channel' => 'php',
                            'name' => '',
                        ),
                        array(
                            'type' => 'optional',
                            'constraint' => '*',
                            'channel' => 'ext',
                            'name' => 'xml',
                        ),
                        array(
                            'type' => 'conflicts',
                            'constraint' => '2.5.6',
                            'channel' => 'pear.php.net',
                            'name' => 'broken',
                        ),
                    ),
                ),
            ),
        );

        $packages = $ref->invoke($reader, 'test.loc', 'test', array($packageInfo));

        $expectedPackage = new MemoryPackage('pear-test.loc/sample', '1.0.0.1' , '1.0.0.1');
        $expectedPackage->setType('library');
        $expectedPackage->setDistType('pear');
        $expectedPackage->setDescription('description');
        $expectedPackage->setDistUrl("http://test.loc/get/sample-1.0.0.1.tgz");
        $expectedPackage->setAutoload(array('classmap' => array('')));
        $expectedPackage->setIncludePaths(array('/'));
        $expectedPackage->setRequires(array(
            new Link('pear-test.loc/sample', 'php', new VersionConstraint('>', '5.2.0.0'), 'required', '>5.2'),
        ));
        $expectedPackage->setConflicts(array(
            new Link('pear-test.loc/sample', 'pear-pear.php.net/broken', new VersionConstraint('==', '2.5.6.0'), 'conflicts', '2.5.6'),
        ));
        $expectedPackage->setSuggests(array(
            'ext-xml',
        ));
        $expectedPackage->setReplaces(array(
            new Link('pear-test.loc/sample', 'pear-test/sample', new VersionConstraint('==', '1.0.0.1'), 'replaces', '== 1.0.0.1'),
        ));

        $this->assertCount(1, $packages);
        $this->assertEquals($expectedPackage, $packages[0]);
//        $this->assertSame(print_r($expectedPackage, true), print_r($packages[0], true));
    }
}
