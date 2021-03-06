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

/**
 * Session Model for Eb2cCore - responsible for tracking changes to quotes
 * throughout a users session and flagging various services for being updated.
 *
 * All data that is to persist _must_ be in magic $_data. This requires far more
 * information to be public from this class than desired. Only the methods
 * explicitly defined as public methods in this class should be considered
 * actually public methods of the class. Any other "magic" public getters and
 * setters should only be used from within the class - consider them private.
 *
 * Magic data used by this class:
 * quote_changes => array of quote data that has changed since the last check
 * current_quote_data => array of data extracted from the quote during the last check
 * tax_update_required_flag => flag indicating that tax needs to be updated
 * quantity_update_required_flag => flag indicating that inventory quantity needs to be updated
 * details_update_required_flag => flag indicating that inventory details needs to be updated
 */
class Radial_Core_Model_Session extends Mage_Core_Model_Session_Abstract
{
    /** @var EbayEnterprise_MageLog_Helper_Data $_logger */
    protected $_logger;
    /** @var EbayEnterprise_MageLog_Helper_Context */
    protected $_context;

    /**
     * Class constructor - initialize the session namespace.
     * This method is going to be nigh impossible to cover - calling it in the test
     * suite will cause a bunch of "controller" errors so it must always be disabled.
     * @codeCoverageIgnore
     */
    public function __construct()
    {
        $this->_logger = Mage::helper('ebayenterprise_magelog');
        $this->_context = Mage::helper('ebayenterprise_magelog/context');
        $this->init('radial_core');
    }
    /**
     * Get sku and qty data for a given quote
     * @param  Mage_Sales_Model_Quote $quote Quote object to extract data from
     * @return array                         Array of 'sku' => qty
     */
    protected function _extractQuoteSkuData(Mage_Sales_Model_Quote $quote)
    {
        $skuData = [];
        // use getAllVisibleItems to prevent dupes due to parent config + child used both being included
        foreach ($quote->getAllVisibleItems() as $item) {
            // before item's have been saved, getAllVisible items won't properly
            // filter child items...this extra check fixes it
            if ($item->getParentItem()) {
                continue;
            }
            $skuData[$item->getSku()] = [
                'item_id' => $item->getId(),
                'managed' => Mage::helper('radial_core/quote_item')->isItemInventoried($item),
                'virtual' => $item->getIsVirtual(),
                'qty' => $item->getQty(),
            ];
        }
        return $skuData;
    }
    /**
     * Extract array of address data - street, city, region code, etc. from an address object
     * @param  Mage_Customer_Model_Address_Abstract $address Address object to pull data from
     * @return array Extracted data
     */
    protected function _extractAddressData(Mage_Customer_Model_Address_Abstract $address)
    {
        return array(
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'region_code' => $address->getRegionCode(),
            'country_id' => $address->getCountryId(),
            'postcode' => $address->getPostcode(),
        );
    }
    /**
     * Extract shipping data from a quote - the shipping method and address for each shipping address
     * in the quote.
     * @param  Mage_Sales_Model_Quote $quote The quote to extract the data from
     * @return array                         Map of shipping method and address for each shipping address in the quote
     */
    protected function _extractQuoteShippingData(Mage_Sales_Model_Quote $quote)
    {
        $shippingData = array();
        foreach ($quote->getAllShippingAddresses() as $address) {
            $shippingData[] = array(
                'method' => $address->getShippingMethod(),
                'address' => $this->_extractAddressData($address),
            );
        }
        return $shippingData;
    }
    /**
     * Get the coupon code applied to the quote.
     * @param  Mage_Sales_Model_Quote $quote Quote object to get data from
     * @return string|null Coupon code applied to the quote
     */
    protected function _extractQuoteCouponData(Mage_Sales_Model_Quote $quote)
    {
        return $quote->getCouponCode();
    }
    /**
     * Return array of billing address data if available, otherwise, an empty array
     * @param  Mage_Sales_Model_Quote $quote Quote object to extract data from
     * @return array Array of billing address data
     */
    protected function _extractQuoteBillingData(Mage_Sales_Model_Quote $quote)
    {
        $address = $quote->getBillingAddress();
        return $address ?
            $this->_extractAddressData($address) :
            array();
    }
    /**
     * Extract quote amounts from each address.
     * @param  Mage_Sales_Model_Quote $quote
     * @return array
     */
    protected function _extractQuoteAmounts(Mage_Sales_Model_Quote $quote)
    {
        return array_map(
            function ($address) {
                return array(
                    'subtotal' => round($address->getSubtotal(), 4) ?: 0.0000,
                    'discount' => round($address->getDiscountAmount(), 4) ?: 0.0000,
                    'ship_amount' => round($address->getShippingAmount(), 4) ?: 0.0000,
                    'ship_discount' => round($address->getShippingDiscountAmount(), 4) ?: 0.0000,
                    'giftwrap_amount' => round($address->getGwPrice() + $address->getGwItemsPrice(), 4) ?: 0.0000,
                );
            },
            $quote->getAllShippingAddresses()
        );
    }
    /**
     * Extract coupon, shipping and sku/quantity data from a quote. Should return an array (map)
     * keys: 'coupon' containing the current coupon code in use, 'shipping' containing
     * all shipping addresses and methods, 'billing' containing the current billing address,
     * and 'skus' containing all item skus and quantites
     * @param  Mage_Sales_Model_Quote $quote object to extract data from
     * @return array                         extracted quote data
     */
    protected function _extractQuoteData(Mage_Sales_Model_Quote $quote)
    {
        return array(
            'billing' => $this->_extractQuoteBillingData($quote),
            'coupon' => $this->_extractQuoteCouponData($quote),
            'shipping' => $this->_extractQuoteShippingData($quote),
            'skus' => $this->_extractQuoteSkuData($quote),
            'amounts' => $this->_extractQuoteAmounts($quote),
        );
    }

    /**
     * Diff billing address data between the two. Any change to a billing address
     * should force the entire address to be considered to be different
     *
     * @param array $oldAddress Array of address data extracted from a quote
     * @param array $newAddress Array of address data extracted from a quote
     * @return array Array containing new address data if address has changed, empty array of addresses match
     */
    protected function _diffBilling(array $oldAddress, array $newAddress)
    {
        return ($oldAddress !== $newAddress) ?
            array('billing' => $newAddress) :
            array();
    }
    /**
     * Diff the old coupon to the new coupon. Return array of coupon data if coupon
     * has changed, empty array otherwise.
     *
     * @param  array $oldCoupon previous coupon data
     * @param  array $newCoupon current coupon data
     * @return array Array of coupon data if coupon has changed, empty array otherwise
     */
    protected function _diffCoupon($oldCoupon, $newCoupon)
    {
        return ($oldCoupon !== $newCoupon) ?
            array('coupon' => $newCoupon) :
            array();
    }
    /**
     * Diff the old shipping data to the new shipping data. Any change to shipping
     * data should invalidate the entire set of address data.
     * @param  array $oldShipping previous shipping data
     * @param  array $newShipping current shipping data
     * @return array Array of shipping data if data has changed, empty array otherwise
     */
    protected function _diffShipping($oldShipping, $newShipping)
    {
        return ($oldShipping !== $newShipping) ?
            array('shipping' => $newShipping) :
            array();
    }
    /**
     * Diff quote item quantities between the old items and new items. Diff should only check for
     * changes to item quantities as no other data in the item data (managed and virtual) should
     * ever change between requests.
     * @param  array $oldItems Item data extracted from a quote
     * @param  array $newItems Item data extracted from a quote
     * @return array Array of item data if any items have changed, empty array otherwise
     */
    protected function _diffSkus($oldItems, $newItems)
    {
        $skuDiff = [];
        foreach ($newItems as $sku => $details) {
            // only care if item qty changes or the item was previously removed from the quote and was added back, hence making its item id change.
            // None of the other item details, managed & virtual, should change between requests.
            if (!isset($oldItems[$sku]) || $oldItems[$sku]['qty'] !== $details['qty'] || $oldItems[$sku]['item_id'] !== $details['item_id']) {
                $skuDiff[$sku] = $details;
            }
        }
        foreach ($oldItems as $sku => $details) {
            if (!isset($newItems[$sku])) {
                $skuDiff[$sku] = ['managed' => $details['managed'], 'virtual' => $details['virtual'], 'qty' => 0, 'item_id' => $details['item_id']];
            }
        }
        return $skuDiff ? ['skus' => $skuDiff] : $skuDiff;
    }
    /**
     * Diff the quote amounts between the old items and new items.
     * @param  array $oldAmounts
     * @param  array $newAmounts
     * @return array
     */
    protected function _diffAmounts($oldAmounts, $newAmounts)
    {
        return ($oldAmounts !== $newAmounts) ? array('amounts' => $newAmounts) : array();
    }
    /**
     * Diff the new quote to the old quote. May contain keys for 'billing', 'coupon', 'shipping'
     * and 'skus'. For more details on the type of changes detected for each key, see the
     * responsible methods for diffing those sets of data.
     * @param  array  $oldQuote Array of data extracted from a quote
     * @param  array  $newQuote Array of data extracted from a quote
     * @return array Array of changes made between the two sets of quote data
     */
    protected function _diffQuoteData($oldQuote, $newQuote)
    {
        if (empty($oldQuote)) {
            return $newQuote;
        }
        return $this->_diffBilling($oldQuote['billing'], $newQuote['billing']) +
            $this->_diffCoupon($oldQuote['coupon'], $newQuote['coupon']) +
            $this->_diffShipping($oldQuote['shipping'], $newQuote['shipping']) +
            $this->_diffSkus($oldQuote['skus'], $newQuote['skus']) +
            $this->_diffAmounts($oldQuote['amounts'], $newQuote['amounts']);
    }
    /**
     * Check the set of items to have an item with the given key set to a
     * truthy value.
     * @param  array  $items array of item data
     * @param  string $key   array key to check
     * @return bool true if any item has a truthy value at the given key
     */
    protected function _anyItem($items, $key)
    {
        foreach ($items as $item) {
            if (isset($item[$key]) && $item[$key]) {
                return true;
            }
        }
        return false;
    }
    /**
     * Check the array of items data for virtual items
     * @param  array $items Array of items data
     * @return bool True if the data contains virtual items, false if not
     */
    protected function _itemsIncludeVirtualItem($items)
    {
        return $this->_anyItem($items, 'virtual');
    }
    /**
     * Check the array of item data for managed stock items
     * @param  array $items Array of items data
     * @return bool True if the data contains virtual items, false if not
     */
    protected function _itemsIncludeManagedItem($items)
    {
        return $this->_anyItem($items, 'managed');
    }

    /**
     * Check if changes to the quote require tax data to be updated. Current conditions
     * which required tax to be updated:
     * - coupon code changes
     * - billing address changes for a quote with virtual items
     * - shipping address changes
     * - item quantities change
     * - quote amounts change
     * @param  array $quoteData Array of data extracted from the newest quote object
     * @param  array $quoteDiff Array of changes made to the quote
     * @return bool true iff a tax request should be made
     */
    protected function _changeRequiresTaxUpdate($quoteData, $quoteDiff)
    {
        return (isset($quoteData['skus'])) && (
            (isset($quoteDiff['skus'])) ||
            (isset($quoteDiff['shipping'])) ||
            (isset($quoteDiff['coupon'])) ||
            (isset($quoteDiff['billing']) && $this->_itemsIncludeVirtualItem($quoteData['skus'])) ||
            (isset($quoteDiff['amounts']))
        );
    }
    /**
     * Check if changes to the quote require inventory details to be updated.
     * Current conditions which require inventory details to be updated:
     * - shipping data changes for quote with managed stock items
     * - items with managed stock change
     * @param  array $quoteData Array of data extracted from the newest quote object
     * @param  array $quoteDiff Array of changes made to the quote
     * @return bool true iff an inventory details request should be made
     */
    protected function _changeRequiresDetailsUpdate($quoteData, $quoteDiff)
    {
        return isset($quoteData['skus']) && (
            (isset($quoteDiff['skus']) && $this->_itemsIncludeManagedItem($quoteDiff['skus'])) ||
            (isset($quoteDiff['shipping']) && $this->_itemsIncludeManagedItem($quoteData['skus']))
        );
    }

    /**
     * Update the tax flag.
     * NOTE: this method cannot set the flag to be false if the flag was already set to be true.
     * @param $value
     * @return self
     */
    public function setTaxUpdateRequired($value)
    {
        return $this->setData('tax_update_required_flag', $value || $this->getTaxUpdateRequiredFlag());
    }

    /**
     * Update the inventory quantity flag.
     * NOTE: this method cannot set the flag to be false if the flag was already set to be true.
     * @param $value
     * @return self
     */
    public function setQuantityUpdateRequired($value)
    {
        return $this->setData('quantity_update_required_flag', $value || $this->getQuantityUpdateRequiredFlag());
    }

    /**
     * Update the inventory details flag.
     * NOTE: this method cannot set the flag to be false if the flag was already set to be true.
     * @param $value
     * @return self
     */
    public function setDetailsUpdateRequired($value)
    {
        return $this->setData('details_update_required_flag', $value || $this->getDetailsUpdateRequiredFlag());
    }
    /**
     * Get that flag indicating that changes to the quote require tax data to be updated
     * @return bool Should tax details be recollected
     */
    public function isTaxUpdateRequired()
    {
        return $this->getTaxUpdateRequiredFlag();
    }
    /**
     * Get the flag indicating that changes to the quote require inventory details to be recollected.
     * @return bool Should inventory details be recollected
     */
    public function isDetailsUpdateRequired()
    {
        return $this->getDetailsUpdateRequiredFlag();
    }
    /**
     * Reset the tax flag
     * @return self
     */
    public function resetTaxUpdateRequired()
    {
        return $this->unsTaxUpdateRequiredFlag();
    }
    /**
     * Reset the inventory details flag
     * @return self
     */
    public function resetDetailsUpdateRequired()
    {
        return $this->unsDetailsUpdateRequiredFlag();
    }
    /**
     * Get any quote changes that were detected the last time the quote data was updated.
     * NOTE: method exists for explicitness.
     * @return array Array of changes made to the quote
     */
    public function getQuoteChanges()
    {
        return $this->getData('quote_changes');
    }
    /**
     * Update session data with a new quote object. Method should get a diff of the
     * current/old quote data and diff it with the new quote data. This data should
     * then be used to update flags as needed. Finally, the new data should replace
     * existing data.
     * @param  Mage_Sales_Model_Quote $quote New quote object
     * @return self
     */
    public function updateWithQuote(Mage_Sales_Model_Quote $quote)
    {
        $oldData = $this->getCurrentQuoteData();
        $newData = $this->_extractQuoteData($quote);
        // Copy over the last_updated timestamp from the old quote data. This will
        // persist the timestamp from one set of data to the next preventing
        // the new data from auto expiring.
        $newData['last_updated'] = $oldData['last_updated'];
        $this->_logger->debug(
            'Comparing quote data',
            $this->_context->getMetaData(__CLASS__, [
                'old' => json_encode($oldData),
                'new' => json_encode($newData),
            ])
        );
        $quoteDiff = $this->_diffQuoteData($oldData, $newData);
        // if nothing has changed in the quote, no need to update flags, or
        // quote data as none of them will change
        if (!empty($quoteDiff)) {
            $changes = implode(', ', array_keys($quoteDiff));
            $logData = ['changes' => $changes, 'diff' => json_encode($quoteDiff)];
            $logMessage = 'Changes found in quote for: {changes}';
            $this->_logger->debug($logMessage, $this->_context->getMetaData(__CLASS__, $logData));
            $this
                // set the update required flags - any flags that are already true should remain true
                // flags should only be unset explicitly by the reset methods
                ->setTaxUpdateRequiredFlag($this->_changeRequiresTaxUpdate($newData, $quoteDiff))
                ->setDetailsUpdateRequiredFlag($this->_changeRequiresDetailsUpdate($newData, $quoteDiff))
                ->setCurrentQuoteData($newData);
        } else {
            $this->_logger->debug('No changes in quote.', $this->_context->getMetaData(__CLASS__));
        }
        // always update the changes - could go from having changes to no changes
        $this->setQuoteChanges($quoteDiff);

        return $this;
    }
    /**
     * Update just the inventory data with the given quote. This should not
     * set/reset any flags, just update the current data set with any changes made
     * to the quote while checking inventory. This method should also update the
     * timestamp on the current quote data.
     * @param Mage_Sales_Model_Quote $quote The quote to update inventory data with
     * @return self
     */
    public function updateQuoteInventory(Mage_Sales_Model_Quote $quote)
    {
        $quoteData = $this->getCurrentQuoteData();
        $quoteData['skus'] = $this->_extractQuoteSkuData($quote);
        $quoteData['last_updated'] = gmdate('c');
        $this->setCurrentQuoteData($quoteData);
        return $this;
    }
}
