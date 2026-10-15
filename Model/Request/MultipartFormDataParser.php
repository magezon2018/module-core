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

namespace Magezon\Core\Model\Request;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Psr\Log\LoggerInterface;

/**
 * Parses a `multipart/form-data` body PHP itself will not.
 *
 * PHP populates `$_POST`/`$_FILES` from a multipart body only for POST, so a
 * `PUT` carrying form-data — which is what every editor client request is, since
 * `apiFetch`'s `formDataMiddleware` converts them so the Magento form key can
 * ride along — arrives with an empty `getPostValue()`. Hence hand parsing.
 *
 * Deliberately narrow: fields only. A part carrying `filename=` is skipped
 * rather than written to a temp file — nothing here uploads, and silently
 * materialising attacker-named files off a PUT is not a thing this should do.
 */
class MultipartFormDataParser
{
    /**
     * Bodies above this are refused. Preferences are a few KB.
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
        $boundary = $this->getBoundary();
        if ($boundary === null) {
            // Reaching here with a body means the caller sent something this
            // cannot read — most likely the client stopped using form-data. The
            // controller can only answer "no fields", which looks identical to a
            // client that sent none, so say so where it can be found. The whole
            // point of this pass was that a silent drop hid a defect for months.
            if ($this->getContentLength() > 0) {
                $this->logger->warning(
                    'Magezon\Core: a request body arrived with no multipart boundary in Content-Type; '
                    . 'no fields could be parsed.'
                );
            }

            return [];
        }

        // Checked BEFORE the body is read. Testing strlen() afterwards would be
        // theatre — file_get_contents('php://input') has already allocated it.
        // Content-Length is absent on a chunked body, hence the second check.
        if ($this->getContentLength() > self::MAX_BODY_BYTES) {
            $this->logger->warning(sprintf(
                'Magezon\Core: refused a %d byte multipart body (limit %d).',
                $this->getContentLength(),
                self::MAX_BODY_BYTES
            ));

            return [];
        }

        $body = $this->getBody();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            $this->logger->warning('Magezon\Core: refused an oversized chunked multipart body.');

            return [];
        }

        $result = [];

        // No guard for the preamble, the inter-part CRLF or the closing `--`:
        // none of them survives the header/value split below, and trim()ing every
        // part to detect them would copy each value in full.
        foreach (explode('--' . $boundary, $body) as $part) {
            // Split on the header/body separator instead of matching the value
            // with a regex. The old implementation used `(.*)\r\n`, and `.` does
            // not cross newlines without /s — so any multi-line preference value
            // silently failed to match and was dropped with no error anywhere.
            $split = explode("\r\n\r\n", ltrim($part, "\r\n"), 2);
            if (count($split) !== 2) {
                continue;
            }

            [$headers, $value] = $split;

            if (!preg_match('/name="([^"]*)"/i', $headers, $matches)) {
                continue;
            }
            if (preg_match('/filename="/i', $headers)) {
                continue;
            }

            // Every part is followed by CRLF before the next boundary; that CRLF
            // belongs to the protocol, not to the value. Only ever strip one, and
            // only if it is actually there — a value may legitimately end in a
            // newline of its own.
            if (substr($value, -2) === "\r\n") {
                $value = substr($value, 0, -2);
            }

            $this->assign($result, $this->splitKeys($matches[1]), $value);
        }

        return $result;
    }

    /**
     * `meta[persisted_preferences][core/editor][welcomeGuide]`
     *     => ['meta', 'persisted_preferences', 'core/editor', 'welcomeGuide']
     *
     * An empty `[]` segment means "append" and is returned as an empty string.
     *
     * @param string $name
     * @return string[]
     */
    private function splitKeys(string $name): array
    {
        $base = strstr($name, '[', true);
        if ($base === false) {
            return [$name];
        }

        preg_match_all('/\[([^\]]*)\]/', $name, $matches);

        // Unfiltered on purpose. The previous implementation ran array_filter()
        // over these, which drops "0" as falsy — so `items[0][x]` lost its index
        // and collapsed into the wrong slot. Empty segments are meaningful here
        // (they are the append form), so there is nothing to filter out.
        return array_merge([$base], $matches[1]);
    }

    /**
     * @param array $target
     * @param string[] $keys
     * @param string $value
     * @return void
     */
    private function assign(array &$target, array $keys, string $value): void
    {
        $cursor = &$target;

        foreach ($keys as $key) {
            if ($key === '') {
                // `field[]` — append.
                $cursor[] = [];
                $cursor = &$cursor[array_key_last($cursor)];
                continue;
            }

            // A scalar already sitting where a subtree is needed means the body
            // declared the same name both ways. Descending into it would be a
            // fatal ("cannot use a scalar value as an array"); the last write
            // simply wins instead.
            if (!isset($cursor[$key]) || !is_array($cursor[$key])) {
                $cursor[$key] = [];
            }

            $cursor = &$cursor[$key];
        }

        $cursor = $value;
        unset($cursor);
    }

    /**
     * @return string|null
     */
    private function getBoundary(): ?string
    {
        $contentType = '';

        if ($this->request instanceof HttpRequest) {
            $header = $this->request->getHeader('Content-Type');
            $contentType = $header === false ? '' : (string) $header;
        }

        // Read from the header, not from the body's first line. A body whose
        // first line is not the boundary produced a garbage delimiter that
        // matched nothing, and the request looked like it carried no fields.
        if (!preg_match('/boundary="?([^";,]+)"?/i', $contentType, $matches)) {
            return null;
        }

        return $matches[1];
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
