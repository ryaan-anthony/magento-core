<?php
/**
 * Copyright (c) 2013-2014 eBay Enterprise, Inc.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @copyright   Copyright (c) 2013-2014 eBay Enterprise, Inc. (http://www.ebayenterprise.com/)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Radial_Core_Test_Model_SequenceTest extends EcomDev_PHPUnit_Test_Case
{
    protected $_sequence;

    /**
     * setUp method
     */
    public function setUp()
    {
        parent::setUp();
        $this->_sequence = Mage::getModel('radial_core/sequence');
    }

    public function providerBuildSequence()
    {
        return array(
            array(glob(__DIR__ . '/SequenceTest/fixtures/*.xml'))
        );
    }

    /**
     * test buildSequence method
     *
     * @dataProvider providerBuildSequence
     */
    public function testBuildSequence($feeds)
    {
        $this->assertSame(3, count($this->_sequence->buildSequence($feeds)));
    }
}
