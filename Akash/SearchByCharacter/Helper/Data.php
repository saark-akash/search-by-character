<?php
namespace Akash\SearchByCharacter\Helper;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Helper\Context;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    public const ATTRIBUTE_CODES_FOR_STRING_SEARCH = 'charactersearch_section/general/attribute_codes';
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;
    /**
	 * @var $request
	 * */
	protected $request;
    /**
     * Data constructor.
     * @param Context $context
     * @param StoreManagerInterface $storeManager
     * @param \Magento\Framework\App\Request\Http $request
     */
    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        \Magento\Framework\App\Request\Http $request
    ) {
        $this->request = $request;
        $this->storeManager = $storeManager;
        parent::__construct($context);
    }
    /**
     * Get system configuration
     * @param string $configPath
     * @return mixed||string
     */
    public function getConfigurationValue($configPath, $storeId = null){
        return $this->scopeConfig->getValue(
            $configPath,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
    /**
     * Get attributes codes
     * @return string
     */
    public function getAttributeCodes()
    {
        $configPath = self::ATTRIBUTE_CODES_FOR_STRING_SEARCH;
        return $this->getConfigurationValue($configPath, null);
    }
    /**
     * Get route name
     * */
    public function getRouteName()
	{
		return $this->request->getRouteName();
	}
}