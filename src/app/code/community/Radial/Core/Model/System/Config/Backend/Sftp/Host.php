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

class Radial_Core_Model_System_Config_Backend_Sftp_Host extends Mage_Core_Model_Config_Data
{
    /**
     * strip any trailing white space before attempting to save the SFTP Host.
     * @return self
     */
    public function _beforeSave()
    {
        parent::_beforeSave();
        $this->setValue(trim($this->getValue()));
        return $this;
    }
}
