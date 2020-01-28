<?php

namespace SilverStripe\ErrorPage;

use Page;
use SilverStripe\Dev\Debug;
use SilverStripe\Forms\FieldList;

/**
 * ErrorPage holds the content for the page of an error response.
 * Renders the page on each publish action into a static HTML file
 * within the assets directory, after the naming convention
 * /assets/error-<statuscode>.html.
 * This enables us to show errors even if PHP experiences a recoverable error.
 * ErrorPages
 *
 * @see Debug::show()
 * @mixin ErrorPageExtension
 */
class ErrorPage extends Page
{
    const HTTP_RESPONSE_CODE_400 = 400;
    const HTTP_RESPONSE_CODE_401 = 401;
    const HTTP_RESPONSE_CODE_403 = 403;
    const HTTP_RESPONSE_CODE_404 = 404;
    const HTTP_RESPONSE_CODE_405 = 405;
    const HTTP_RESPONSE_CODE_406 = 406;
    const HTTP_RESPONSE_CODE_407 = 407;
    const HTTP_RESPONSE_CODE_408 = 408;
    const HTTP_RESPONSE_CODE_409 = 409;
    const HTTP_RESPONSE_CODE_410 = 410;
    const HTTP_RESPONSE_CODE_411 = 411;
    const HTTP_RESPONSE_CODE_412 = 412;
    const HTTP_RESPONSE_CODE_413 = 413;
    const HTTP_RESPONSE_CODE_414 = 414;
    const HTTP_RESPONSE_CODE_415 = 415;
    const HTTP_RESPONSE_CODE_416 = 416;
    const HTTP_RESPONSE_CODE_417 = 417;
    const HTTP_RESPONSE_CODE_422 = 422;
    const HTTP_RESPONSE_CODE_429 = 429;
    const HTTP_RESPONSE_CODE_500 = 500;
    const HTTP_RESPONSE_CODE_501 = 501;
    const HTTP_RESPONSE_CODE_502 = 502;
    const HTTP_RESPONSE_CODE_503 = 503;
    const HTTP_RESPONSE_CODE_504 = 504;
    const HTTP_RESPONSE_CODE_505 = 505;

    /**
     * @var string
     */
    private static $table_name = 'ErrorPage';

    /**
     * @see ErrorPageExtension::defaultRecordsAllowed()
     * @return bool
     */
    public function defaultRecordsAllowed()
    {
        // Only run on ErrorPage class directly, not subclasses
        return static::class === self::class;
    }

    /**
     * @return FieldList
     */
    public function getCMSFields()
    {
        $this->beforeUpdateCMSFields(function (FieldList $fields) {
            $fields->addFieldToTab(
                'Root.Main',
                $this->createErrorCodeField(),
                'Content'
            );
        });

        return parent::getCMSFields();
    }

    /**
     * @deprecated use ErrorPage::singleton()->responseFor
     * @param $statusCode
     * @return mixed
     */
    public static function response_for($statusCode)
    {
        return ErrorPage::singleton()->responseFor($statusCode);
    }

    /**
     * @deprecated use ErrorPage::singleton()->getContentForErrorcode
     * @param $statusCode
     * @return mixed
     */
    public static function get_content_for_errorcode($statusCode)
    {
        return ErrorPage::singleton()->getContentForErrorcode($statusCode);
    }
}
