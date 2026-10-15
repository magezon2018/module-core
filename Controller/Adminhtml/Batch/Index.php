<?php
/**
 * Magezon
 *
 * This source file is subject to the Magezon Software License, which is available at https://www.magezon.com/license
 * Do not edit or add to this file if you wish to upgrade the to newer versions in the future.
 * If you wish to customize this module for your needs.
 * Please refer to https://www.magezon.com for more information.
 *
 * @category  Magezon
 * @package   Magezon_Core
 * @copyright Copyright (C) 2026 Magezon (https://www.magezon.com)
 */

namespace Magezon\Core\Controller\Adminhtml\Batch;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\Json\Helper\Data as JsonHelper;

/**
 * The `/batch/v1` endpoint core-data's `__experimentalBatch` fans out to.
 *
 * Reached at `magezon/batch` — ReactAPP's magentoRouteConverter rewrites the
 * WordPress path `/batch/v1` to `/magezon/batch`, so the frontName is `magezon`
 * (declared in etc/adminhtml/routes.xml) rather than this module's own
 * `mgzcore`.
 *
 * ## Request shape
 *
 * ```json
 * { "requests": [ { "method": "DELETE", "path": "file-manager/assets/index/id/7", "body": {} } ] }
 * ```
 *
 * Each sub-request is re-dispatched through the front controller as if it had
 * arrived on its own, and the bodies are collected into `{ "responses": [...] }`
 * in the SAME order — core-data matches results positionally, so a dropped
 * entry silently shifts every later result onto the wrong record.
 */
// Deliberately declares NO Http*ActionInterface. core-data pre-flights this
// endpoint with OPTIONS to read `maxItems` before it chunks, and Magento's
// HttpMethodValidator rejects any verb outside the one declared marker — so
// adding HttpPostActionInterface here makes the pre-flight 404 and core-data
// falls back to its built-in default chunk size.
class Index extends \Magento\Backend\App\Action
{
    /**
     * Authorization level of a basic admin session.
     *
     * The sub-requests are re-dispatched through the front controller, so each
     * one is re-checked against its OWN controller's ADMIN_RESOURCE. This gate
     * only says "may reach the batch endpoint at all"; it deliberately does not
     * widen what a batched call is allowed to do.
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'Magento_Backend::admin';

    /**
     * Upper bound on sub-requests, mirrored in the OPTIONS pre-flight payload
     * core-data reads to size its own chunks. Enforced rather than advertised
     * only: one HTTP request must not be able to fan out unbounded work.
     */
    const MAX_ITEMS = 100;

    /**
     * @var FrontControllerInterface
     */
    private $frontController;

    /**
     * @var JsonHelper
     */
    private $jsonHelper;

    /**
     * @param Context                 $context
     * @param FrontControllerInterface $frontController
     * @param JsonHelper              $jsonHelper
     */
    public function __construct(
        Context $context,
        FrontControllerInterface $frontController,
        JsonHelper $jsonHelper
    ) {
        parent::__construct($context);
        $this->frontController = $frontController;
        $this->jsonHelper      = $jsonHelper;
    }

    /**
     * Split `module/controller/action/k/v/k/v` into the routing triplet plus
     * the trailing key/value pairs.
     *
     * The previous implementation fell back to `$this->pathConfig->getDefaultPath()`
     * for an empty path — on a property that was never declared or injected, so
     * the fallback fatal'd instead of defaulting. An empty path is simply not
     * dispatchable, and is rejected by the caller.
     *
     * @param string $path
     * @return array{route: string, controller: string, action: string, variables: array}
     */
    private function parseRequest(string $path): array
    {
        $segments = explode('/', trim($path, '/'));

        $route      = array_shift($segments) ?: '';
        $controller = array_shift($segments) ?: 'index';
        $action     = array_shift($segments) ?: 'index';

        $variables = [];
        for ($i = 0, $l = count($segments); $i < $l; $i += 2) {
            $variables[$segments[$i]] = isset($segments[$i + 1])
                ? urldecode($segments[$i + 1])
                : '';
        }

        return [
            'route'      => $route,
            'controller' => $controller,
            'action'     => $action,
            'variables'  => $variables,
        ];
    }

    /**
     * Re-dispatch one sub-request and normalize whatever the target controller
     * produced into `{ body, status, headers }`.
     *
     * @param array $subRequest
     * @return array
     */
    private function dispatchOne(array $subRequest): array
    {
        $path = isset($subRequest['path']) ? trim((string) $subRequest['path'], '/') : '';

        if ($path === '') {
            return [
                'body'   => ['message' => (string) __('Missing sub-request path.')],
                'status' => 400,
            ];
        }

        $parsed = $this->parseRequest($path);

        // Clone so the sub-dispatch cannot mutate the outer request the batch
        // controller itself is still running on.
        $newRequest = clone $this->getRequest();
        $newRequest->setMethod(strtoupper($subRequest['method'] ?? 'GET'));
        $newRequest->setPathInfo('/' . $path);
        $newRequest->setDispatched(false);

        // Derived from the sub-request path. The previous implementation
        // hardcoded file-manager/assets/index here, so this endpoint could only
        // ever batch File Manager asset operations no matter what was asked —
        // every other module's batched call silently hit the wrong controller.
        $newRequest->setModuleName($parsed['route']);
        $newRequest->setControllerName($parsed['controller']);
        $newRequest->setActionName($parsed['action']);

        $newRequest->setPostValue($subRequest['body'] ?? []);
        $newRequest->clearParams();
        $newRequest->setParams($parsed['variables']);

        $result = $this->frontController->dispatch($newRequest);

        if ($result instanceof \Magento\Framework\Controller\Result\Json) {
            // A Json result is the controller's SUCCESS shape. The old code
            // stamped 500 on it unconditionally, so every sub-request that
            // returned cleanly through this branch was reported to the client
            // as a server error.
            return [
                'body'   => $this->jsonHelper->jsonDecode($result->getJson()),
                'status' => 200,
            ];
        }

        if ($result instanceof \Magento\Framework\App\ResponseInterface) {
            $body = $result->getBody();

            return [
                'body'    => $body === '' ? null : $this->jsonHelper->jsonDecode($body),
                'status'  => $result->getHttpResponseCode(),
                'headers' => $result->getHeaders()->toArray(),
            ];
        }

        // Anything else (a redirect, a Page result, a null) has no JSON body to
        // hand back. Emit an explicit entry anyway: core-data pairs responses to
        // requests BY INDEX, so skipping one — as the old `else {}` branch did —
        // shifts every subsequent result onto the wrong record.
        return [
            'body'   => null,
            'status' => 204,
        ];
    }

    /**
     * @return void
     */
    public function execute()
    {
        $request = $this->getRequest();

        if ($request->isOptions()) {
            $this->getResponse()->representJson(
                $this->jsonHelper->jsonEncode([
                    'endpoints' => [
                        ['args' => ['requests' => ['maxItems' => self::MAX_ITEMS]]],
                    ],
                ])
            );
            return;
        }

        $requests = $request->getPostValue('requests');

        // apiFetch's formDataMiddleware sends this as multipart, which PHP
        // parses into $_POST for us. A JSON body is accepted too so the endpoint
        // is usable from anything that speaks plain REST.
        if (!is_array($requests)
            && stripos((string) $request->getHeader('Content-Type'), 'application/json') !== false
        ) {
            $decoded = json_decode((string) $request->getContent(), true);
            if (is_array($decoded) && isset($decoded['requests'])) {
                $requests = $decoded['requests'];
            }
        }

        // `foreach` over a non-array warned and, under Magento's error handler,
        // surfaced as a 500 with a PHP warning as its message. A malformed or
        // absent payload is a client error and should say so.
        if (!is_array($requests)) {
            $this->getResponse()->setHttpResponseCode(400)->representJson(
                $this->jsonHelper->jsonEncode([
                    'code'    => 'rest_invalid_param',
                    'message' => (string) __('The "requests" parameter must be an array.'),
                ])
            );
            return;
        }

        if (count($requests) > self::MAX_ITEMS) {
            $this->getResponse()->setHttpResponseCode(400)->representJson(
                $this->jsonHelper->jsonEncode([
                    'code'    => 'rest_invalid_param',
                    'message' => (string) __('A batch accepts at most %1 requests.', self::MAX_ITEMS),
                ])
            );
            return;
        }

        $responses = [];

        foreach ($requests as $subRequest) {
            if (!is_array($subRequest)) {
                $responses[] = [
                    'body'   => ['message' => (string) __('Malformed sub-request.')],
                    'status' => 400,
                ];
                continue;
            }

            try {
                $responses[] = $this->dispatchOne($subRequest);
            } catch (\Exception $e) {
                // One failing sub-request must not abort the rest of the batch,
                // and must still occupy its index in the response array.
                $responses[] = [
                    'body'   => ['message' => $e->getMessage()],
                    'status' => 500,
                ];
            }
        }

        $this->getResponse()->representJson(
            $this->jsonHelper->jsonEncode(['responses' => $responses])
        );
    }
}
