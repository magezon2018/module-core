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
use Magento\Framework\App\Action\HttpPutActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magezon\Core\Model\User\Preference\Repository as PreferenceRepository;

/**
 * PUT `mgzcore/users/me` — persist the current admin user's preferences.
 *
 * The client fires this from the preferences persistence layer with
 * `keepalive: true` and swallows the outcome (`.catch(() => {})`), so nothing
 * upstream will ever surface a failure here. That makes it worth being strict
 * about the request and quiet about the response rather than the other way
 * round: a malformed body used to reach `$post['meta']` unguarded and return a
 * 500 with a stack trace (measured — an empty body did it), which no one saw.
 */
class Update extends Action implements HttpPutActionInterface
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
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        // Read what the request carries, whatever transport delivered it.
        // `Magezon\Core`'s `Plugin\App\JsonRequestBody` decodes a JSON body
        // into `setPostValue()` before any controller runs, and PHP fills the
        // rest, so one accessor covers every client this endpoint has.
        $post = (array) $this->getRequest()->getPostValue();
        $submitted = $post['meta']['persisted_preferences'] ?? null;

        if (!is_array($submitted)) {
            return $result->setHttpResponseCode(400)->setData([
                'message' => 'Expected meta[persisted_preferences] to be present.'
            ]);
        }

        // Stored VERBATIM. There is nothing left to guess: preferences arrive as
        // JSON with their booleans and numbers intact, and re-typing intact
        // values corrupts them — `Helper\Data::parseFormData` coerces the string
        // branch and has no `else`, so a real `true` or `10` falls through every
        // branch and is written back as null.
        //
        // The hand-parsed multipart fallback that used to sit here is gone with
        // it. No client sends form-data to this endpoint: `formDataMiddleware`
        // has been opt-in since ReactAPP `eb86e3af80`, and the six writers that
        // do opt in all carry a file and all target somewhere else
        // (`api/importProfile`, `api/roleSettings/importRole`,
        // `api/fontManager/fonts/upload`, `api/iconManager/fonts`, and the two
        // media uploads). A preference payload is UI state and cannot contain
        // one.
        $stored = $this->preferences->save($submitted);

        // Deliberately the STORED tree, not the read-processed one: the read
        // pipeline exists to inject server-side values (the AI API key among
        // them) and echoing those back on a write would hand a secret to a caller
        // that only asked to save its own UI state. Same envelope as the GET.
        return $result->setData([
            'meta' => [
                'persisted_preferences' => $stored
            ]
        ]);
    }
}
