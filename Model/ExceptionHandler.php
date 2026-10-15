<?php
namespace Magezon\Core\Model;

use Magento\Framework\Exception\LocalizedException;
use Magezon\Core\Framework\Controller\Result\JsonFactory;

class ExceptionHandler
{
    /**
     * Sentinel `code` for a failure that reached the handler as a plain
     * exception rather than an ErrorException, so it carries no domain code of
     * its own. Without it the generic branches emit a bare `{ message }` and a
     * client cannot branch on `code` for that path at all.
     *
     * Mirrors GENERIC_ERROR_CODE in the TS twin (api-server-base/handleError.ts).
     */
    const GENERIC_ERROR_CODE = 'rest_internal_error';

    /**
     * Status sent for a failure that carries no status of its own. Matches the
     * TS twin's HTTP_STATUS.BAD_REQUEST, and is what these branches already
     * sent before the status header started being honoured.
     */
    const GENERIC_ERROR_STATUS = \Laminas\Http\Response::STATUS_CODE_400;

    /**
     * Fallback when an ErrorException carries no usable status.
     */
    const DEFAULT_ERROR_STATUS = \Laminas\Http\Response::STATUS_CODE_500;

    protected $_jsonFactory;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param JsonFactory                   $jsonFactory
     * @param \Psr\Log\LoggerInterface|null $logger Optional so that adding it does not
     *        break a released consumer that constructs this class positionally. Every
     *        caller in this repo resolves it through DI, where the argument is always
     *        supplied; the ObjectManager fallback exists only for the ones outside it.
     */
    public function __construct(
        JsonFactory $jsonFactory,
        ?\Psr\Log\LoggerInterface $logger = null
    ) {
        $this->_jsonFactory = $jsonFactory;
        $this->logger = $logger ?: \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Psr\Log\LoggerInterface::class);
    }

    /**
     * Converts an error to a response object.
     *
     * This iterates over all error codes and messages to change it into a flat
     * array. This enables simpler client behavior, as it is represented as a
     * list in JSON rather than an object/map.
     *
     * The resulting envelope is `{ code, message, data }` — identical to the
     * one produced by the TS twin, `restConvertErrorToResponse()` in
     * packages/api-server-base/src/handleError.ts.
     *
     * @param \Throwable $error The failure to convert. An
     *                          \Magezon\Core\Exception\ErrorException carries
     *                          its own code(s), message(s) and data; anything
     *                          else falls back to GENERIC_ERROR_CODE.
     *
     * @return \Magezon\Core\Framework\Controller\Result\Json Flat error
     *         envelope, with an HTTP status matching its `data.status`.
     */
    public function convertErrorToResponse( $error ) {
        $data = array();

        if ($error instanceof \Magezon\Core\Exception\ErrorException) {
            $status = array_reduce(
                $error->get_all_error_data(),
                static function ( $status, $error_data ) {
                    return is_array( $error_data ) && isset( $error_data['status'] ) ? $error_data['status'] : $status;
                },
                self::DEFAULT_ERROR_STATUS
            );

            $errors = array();

            foreach ( (array) $error->errors as $code => $messages ) {
                $all_data  = $error->get_all_error_data( $code );
                $last_data = array_pop( $all_data );

                foreach ( (array) $messages as $message ) {
                    $formatted = array(
                        'code'    => $code,
                        'message' => $message,
                        'data'    => $last_data,
                    );

                    if ( $all_data ) {
                        $formatted['additional_data'] = $all_data;
                    }

                    $errors[] = $formatted;
                }
            }

            // An ErrorException built with an empty code has no errors at all,
            // so guard the primary read before mutating it — as the TS twin
            // does with `errors[0] ?? …`.
            $data = isset( $errors[0] ) ? $errors[0] : array(
                'code'    => self::GENERIC_ERROR_CODE,
                'message' => __('Something went wrong while processing the request.'),
                'data'    => array( 'status' => $status ),
            );

            if ( count( $errors ) > 1 ) {
                // Remove the primary error.
                array_shift( $errors );
                $data['additional_errors'] = $errors;
            }
        } else if ($error instanceof LocalizedException) {
            // Emit the SAME flat envelope an ErrorException produces — `code` +
            // `data.status`, not a bare `{ message }`. A client cannot branch on
            // `code` for a path that omits it, so the generic fallback was the
            // one error shape no consumer could handle uniformly.
            $status = self::GENERIC_ERROR_STATUS;
            $data['code']    = self::GENERIC_ERROR_CODE;
            $data['message'] = $error->getMessage();
            $data['data']    = array( 'status' => $status );
        } else {
            // Unlike the TS twin, an unknown failure's own message is NOT
            // forwarded: a raw PHP exception message leaks paths, SQL and class
            // names to an admin HTTP client. The code and status still are.
            //
            // Which is exactly why it has to be LOGGED. Stripping the message
            // without recording it anywhere made this branch the one error shape
            // nobody could diagnose: the client sees a bare
            // `{"code":"rest_internal_error"}` HTTP 400 and var/log/exception.log
            // stays EMPTY, so the failure reads as an auth or routing problem
            // rather than as whatever actually threw. Measured 2026-08-21 on the
            // editor's media/create endpoint, where a plain \DomainException
            // ("The file was not uploaded.", raised because PHP had silently
            // discarded a file over upload_max_filesize) cost a full A/B
            // bisection to identify — the exception object was here all along.
            //
            // The other two branches deliberately stay quiet: an ErrorException
            // carries a domain code the client can act on, and a LocalizedException
            // has its message forwarded verbatim. Neither is invisible.
            $this->logger->critical($error);

            $status = self::GENERIC_ERROR_STATUS;
            $data['code']    = self::GENERIC_ERROR_CODE;
            $data['message'] = __('Something went wrong while processing the request.');
            $data['data']    = array( 'status' => $status );
        }

        $status = $this->normalizeStatus( $status );

        $resultJson = $this->_jsonFactory->create();
        $resultJson->setStatusHeader(
            $status,
            \Laminas\Http\AbstractMessage::VERSION_11,
            $this->getReasonPhrase( $status )
        );
        return $resultJson->setData($data);
    }

    /**
     * Clamps an error-supplied status to one the response layer will accept.
     *
     * `data.status` is whatever a controller put in its error data, while
     * Magento's PhpEnvironment\Response::setHttpResponseCode() throws on
     * anything non-numeric or outside 100-599. Inside an error handler that
     * would replace a clean envelope with a fatal, so anything unusable
     * degrades to DEFAULT_ERROR_STATUS rather than propagating.
     *
     * @param mixed $status Status reduced out of the error data.
     *
     * @return int A status code in the 100-599 range.
     */
    private function normalizeStatus( $status ) {
        if ( ! is_numeric( $status ) ) {
            return self::DEFAULT_ERROR_STATUS;
        }

        $status = (int) $status;

        if ( $status < 100 || $status > 599 ) {
            return self::DEFAULT_ERROR_STATUS;
        }

        return $status;
    }

    /**
     * Canonical reason phrase for a status code.
     *
     * Delegates to Laminas' own table instead of duplicating it. The phrase
     * cannot simply be left to Magento to resolve: PhpEnvironment\Response
     * ::setStatusHeader() replaces a null phrase via getReasonPhrase() BEFORE
     * it applies the new code, so every response would be labelled with the
     * previous status's phrase.
     *
     * @param int $status A status code already through normalizeStatus().
     *
     * @return string Reason phrase, or a generic one for an unlisted code.
     */
    private function getReasonPhrase( $status ) {
        $response = new \Laminas\Http\Response();
        $response->setStatusCode( $status );

        $phrase = $response->getReasonPhrase();

        return $phrase !== '' ? $phrase : 'Error';
    }
}