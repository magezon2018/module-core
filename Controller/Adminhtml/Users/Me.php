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

namespace Magezon\Core\Controller\Adminhtml\Users;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPutActionInterface;

/**
 * Method router for `mgzcore/users/me`, mirroring the WordPress REST resource
 * the client was written against: one path, GET reads and PUT writes.
 *
 * Forwarding keeps the action name as the router resolves it, so `me_detail`
 * lands on `Me\Detail` and `me_update` on `Me\Update`.
 *
 * BOTH method interfaces are required. Magento's HttpMethodValidator rejects a
 * request whenever an action declares any method interface but not the one in
 * hand — declaring only one here would 404 the other verb.
 */
class Me extends Action implements HttpGetActionInterface, HttpPutActionInterface
{
    /**
     * @return void
     */
    public function execute()
    {
        $request = $this->getRequest();

        // HEAD is mapped to HttpGetActionInterface by the framework, so it passes
        // the method validator and lands here — it must be routed like GET, or a
        // HEAD would answer 404 where the matching GET answers 200.
        if ($request->isGet() || $request->isHead()) {
            $this->_forward('me_detail');
            return;
        }

        if ($request->isPut()) {
            $this->_forward('me_update');
            return;
        }

        // Unreachable today, and the reasons differ per verb — measured against
        // all six on 2026-07-29: DELETE and PATCH are 404'd by HttpMethodValidator
        // before execute() runs (this action declares Get+Put, so any other
        // MAPPED verb is rejected), while POST never reaches the validator at all
        // — the admin CSRF layer 302s a form-key-less POST first. Kept as the
        // honest fallback should the framework ever map a new verb onto one of
        // the two interfaces above.
        $this->_forward('noroute');
    }
}
