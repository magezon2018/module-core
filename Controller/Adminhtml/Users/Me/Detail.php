<?php
/**
 * Magezon
 *
 * This source file is subject to the Magezon Software License, which is available at https://www.magezon.com/license.
 * Do not edit or add to this file if you wish to upgrade the to newer versions in the future.
 * If you wish to customize this module for your needs.
 * Please refer to https://www.magezon.com for more information.
 *
 * @category  Magezon
 * @package   Magezon_Core
 * @copyright Copyright (C) 2019 Magezon (https://www.magezon.com)
 */

namespace Magezon\Core\Controller\Adminhtml\Users\Me;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magezon\Core\Model\User\Preference\Repository as PreferenceRepository;

/**
 * GET `mgzcore/users/me` — the current admin user's preferences.
 *
 * The WordPress-derived client only calls this when it booted WITHOUT preloaded
 * data; a normal editor boot is served entirely from the SSR `serverData` key.
 * Both paths go through the same repository, so they cannot disagree.
 */
class Detail extends Action implements HttpGetActionInterface
{
    /**
     * @var PreferenceRepository
     */
    private $preferences;

    /**
     * @param Context $context
     * @param PreferenceRepository $preferences
     */
    public function __construct(
        Context $context,
        PreferenceRepository $preferences
    ) {
        parent::__construct($context);
        $this->preferences = $preferences;
    }

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        return $this->resultFactory->create(ResultFactory::TYPE_JSON)->setData([
            'meta' => [
                'persisted_preferences' => $this->preferences->get()
            ]
        ]);
    }
}
