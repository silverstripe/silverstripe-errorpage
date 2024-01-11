<?php
namespace SilverStripe\ErrorPage;

use PageController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\View\SSViewer;

/**
 * Controller for ErrorPages.
 *
 * @extends PageController<ErrorPage>
 */
class ErrorPageController extends PageController
{
    /**
     * Explicitly set themes to the themes config value in case the theme was previously set to something else
     * One example of this is when serving 404 error pages under the admin path e.g. admin/non-existent
     * where LeftAndMain::init() will have previously set themes to the admin_themes config
     */
    protected function init()
    {
        SSViewer::set_themes(SSViewer::config()->themes);
        parent::init();
    }

    /**
     * Overload the provided {@link Controller::handleRequest()} to append the
     * correct status code post request since otherwise permission related error
     * pages such as 401 and 403 pages won't be rendered due to
     * {@link HTTPResponse::isFinished() ignoring the response body.
     */
    public function handleRequest(HTTPRequest $request): HTTPResponse
    {
        $page = $this->data();
        $response = parent::handleRequest($request);
        $response->setStatusCode($page->ErrorCode);
        return $response;
    }
}
