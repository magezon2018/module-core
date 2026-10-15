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
 * @copyright Copyright (C) 2026 Magezon (https://www.magezon.com)
 */

namespace Magezon\Core\Plugin\App;

use Magezon\Core\Model\Request\JsonBodyParser;

/**
 * Make a JSON request body visible to everything that reads `$_POST`.
 *
 * PHP fills `$_POST` only from a urlencoded or multipart body, so a JSON
 * request arrives with an empty `getPostValue()`. Two things then break, and
 * neither says why:
 *
 * 1. The admin FORM KEY. `_processUrlKeys()` — the method this plugin runs
 *    before — validates it through `getParam('form_key')`, which searches route
 *    params, `$_GET` and `$_POST` and has no notion of a request body. The
 *    check fails and Magento answers a 200 carrying the dashboard HTML, which
 *    `apiFetch` does not treat as an error: the caller sees a resolved promise
 *    and no record. Measured 2026-08-25 — 11.7 MB of markup where a font should
 *    have been.
 * 2. Every controller reading `getPostValue()` — around 145 of them — sees no
 *    fields and quietly writes nothing.
 *
 * Decoding into `setPostValue()` at the one point every admin request passes
 * fixes both, and leaves those controllers unchanged. `Magezon\Core`'s batch
 * controller has done exactly this since it shipped (`Batch\Index::161`), so
 * the shape is this module's own rather than a new idea.
 *
 * ## Why the guard is the CONTENT TYPE and not a route list
 *
 * The editor's API does not live under one Magento route: it spans `editor`,
 * `mgzcore`, `filemanager`, `mgzfontmanager`, `mgziconmanager` and one more per
 * satellite. A route allow-list here would have to grow with every satellite
 * shipped — a core-side list of things the core should not have to know, and
 * one whose omission fails silently.
 *
 * `application/json` is the request describing itself, needs no list, and is
 * exactly as narrow as the problem: no Magento admin HTML form sends it.
 *
 * ## Why this is not a CSRF weakening
 *
 * It grants no authorization. The form key must still be present and correct —
 * this only makes a body READABLE so the existing check can find the token the
 * client already sent. And a JSON content type is what browsers treat as a
 * non-simple request: a forged cross-origin POST carrying it triggers a CORS
 * preflight, which Magento does not answer, so it cannot even be sent. The
 * transports that CAN be forged cross-origin — urlencoded and multipart — are
 * the ones PHP was already parsing before this plugin existed.
 */
class JsonRequestBody
{
    /**
     * @var JsonBodyParser
     */
    private $jsonBodyParser;

    /**
     * @param JsonBodyParser $jsonBodyParser
     */
    public function __construct(JsonBodyParser $jsonBodyParser)
    {
        $this->jsonBodyParser = $jsonBodyParser;
    }

    /**
     * Runs for EVERY method, not only POST: the form key is a POST concern, but
     * a `PUT` carrying JSON has the same empty `$_POST` and the same readers.
     *
     * @param \Magento\Backend\App\AbstractAction $subject
     * @return void
     */
    public function before_processUrlKeys(\Magento\Backend\App\AbstractAction $subject)
    {
        $request = $subject->getRequest();

        if (!method_exists($request, 'setPostValue')) {
            return;
        }

        // A body PHP already parsed is left alone, so this can never overwrite a
        // real payload — including the batch controller's, which injects each
        // sub-request's fields with setPostValue() before re-dispatching while
        // `php://input` still holds the OUTER envelope.
        if (!empty($request->getPostValue())) {
            return;
        }

        $body = $this->jsonBodyParser->parse();

        if (!empty($body)) {
            $request->setPostValue($body);
        }
    }
}
