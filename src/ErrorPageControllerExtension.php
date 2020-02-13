<?php

namespace SilverStripe\ErrorPage;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\Form;

/**
 * Class ErrorPageControllerExtension
 *
 * Enhances error handling for a controller with ErrorPage generated output
 *
 * @property Controller|Form $owner
 * @package SilverStripe\ErrorPage
 */
class ErrorPageControllerExtension extends Extension
{
    /**
     * Used by @see RequestHandler::httpError
     *
     * @param int $statusCode
     * @param HTTPRequest $request
     * @param string|null $errorMessage
     * @throws HTTPResponse_Exception
     */
    public function onBeforeHTTPError($statusCode, $request, $errorMessage = null)
    {
        if (Director::is_ajax()) {
            return;
        }

        $response = ErrorPage::singleton()->responseFor($statusCode, $errorMessage);

        if (!$response) {
            return;
        }

        throw new HTTPResponse_Exception($response, $statusCode);
    }
}
