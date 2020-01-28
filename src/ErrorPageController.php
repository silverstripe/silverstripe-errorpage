<?php
namespace SilverStripe\ErrorPage;

use Page;
use PageController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;

/**
 * Class ErrorPageController
 *
 * @package SilverStripe\ErrorPage
 */
class ErrorPageController extends PageController
{
    /**
     * Overload the provided @see Controller::handleRequest() to append the
     * correct status code post request since otherwise permission related error
     * pages such as 401 and 403 pages won't be rendered due to
     * @see HTTPResponse::isFinished() ignoring the response body.
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function handleRequest(HTTPRequest $request)
    {
        /** @var Page|ErrorPageExtension $page */
        $page = $this->data();

        if (!$page->hasExtension(ErrorPageExtension::class)) {
            return parent::handleRequest($request);
        }

        $response = parent::handleRequest($request);
        $response->setStatusCode($page->ErrorCode);

        return $response;
    }
}
