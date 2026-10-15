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

namespace Magezon\Core\Model\Request;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Psr\Log\LoggerInterface;

/**
 * Parses an `application/json` body PHP itself will not.
 *
 * The JSON counterpart of {@see MultipartFormDataParser}, and it exists for the
 * same reason: PHP fills `$_POST` only for a urlencoded or multipart body, so a
 * JSON request arrives with an empty `getPostValue()` no matter which method it
 * used. Every admin lookup that matters then reads nothing — including
 * `getParam('form_key')`, which searches route params, `$_GET` and `$_POST` and
 * has no notion of a request body at all
 * ({@see \Magento\Framework\HTTP\PhpEnvironment\Request::getParam}).
 *
 * The consequence measured 2026-08-25 on a live install: an editor CREATE
 * failed its admin form-key check and was answered with a 200 carrying the
 * dashboard HTML, which `apiFetch` does not treat as an error. The caller saw a
 * resolved promise, 11.7 MB of markup where a record should have been, and no
 * row written.
 *
 * Returns a top-level ARRAY only. A JSON body that decodes to a scalar or to
 * `null` is not something `setPostValue()` can carry, and a `[]` says so
 * without the caller having to distinguish "not JSON" from "empty object".
 */
class JsonBodyParser
{
    /**
     * Bodies above this are refused.
     *
     * Deliberately the same ceiling as the multipart sibling: the two parse the
     * same requests, so a payload one accepts and the other refuses would make
     * the transport decide whether a write is possible.
     */
    const MAX_BODY_BYTES = 2097152;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param RequestInterface $request
     * @param LoggerInterface $logger
     */
    public function __construct(
        RequestInterface $request,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->logger  = $logger;
    }

    /**
     * @return array
     */
    public function parse(): array
    {
        if (!$this->isJson()) {
            return [];
        }

        // Checked BEFORE the body is read, for the reason the sibling records:
        // testing strlen() afterwards is theatre, the allocation has happened.
        // Content-Length is absent on a chunked body, hence the second check.
        if ($this->getContentLength() > self::MAX_BODY_BYTES) {
            $this->logger->warning(sprintf(
                'Magezon\Core: refused a %d byte JSON body (limit %d).',
                $this->getContentLength(),
                self::MAX_BODY_BYTES
            ));

            return [];
        }

        $body = $this->getBody();

        if ($body === '') {
            return [];
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
            $this->logger->warning('Magezon\Core: refused an oversized chunked JSON body.');

            return [];
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // The Content-Type promised JSON and the body is not. Say so where
            // it can be found: the controller can only answer "no fields",
            // which looks identical to a client that sent none.
            $this->logger->warning(sprintf(
                'Magezon\Core: a request declared application/json but did not parse: %s',
                json_last_error_msg()
            ));

            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return bool
     */
    private function isJson(): bool
    {
        if (!$this->request instanceof HttpRequest) {
            return false;
        }

        $header = $this->request->getHeader('Content-Type');

        if ($header === false) {
            return false;
        }

        // `stripos`, not equality: a real header carries parameters
        // (`application/json; charset=utf-8`), and axios sends exactly that.
        return stripos((string) $header, 'application/json') !== false;
    }

    /**
     * @return int
     */
    private function getContentLength(): int
    {
        if (!$this->request instanceof HttpRequest) {
            return 0;
        }

        $header = $this->request->getHeader('Content-Length');

        return $header === false ? 0 : (int) $header;
    }

    /**
     * @return string
     */
    private function getBody(): string
    {
        if ($this->request instanceof HttpRequest) {
            return (string) $this->request->getContent();
        }

        return (string) file_get_contents('php://input');
    }
}
