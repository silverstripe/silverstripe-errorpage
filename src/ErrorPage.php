<?php

namespace SilverStripe\ErrorPage;

use Page;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\GeneratedAssetHandler;
use SilverStripe\CMS\Controllers\ModelAsController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Debug;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Security\Member;
use SilverStripe\View\Requirements;
use SilverStripe\View\SSViewer;
use SilverStripe\Core\Convert;
use SilverStripe\ORM\FieldType\DBField;

/**
 * ErrorPage holds the content for the page of an error response.
 * Renders the page on each publish action into a static HTML file
 * within the assets directory, after the naming convention
 * /assets/error-<statuscode>.html.
 * This enables us to show errors even if PHP experiences a recoverable error.
 * ErrorPages
 *
 * @see Debug::friendlyError()
 *
 * @property int $ErrorCode HTTP Error code
 */
class ErrorPage extends Page
{
    private static $db = [
        "ErrorCode" => "Int",
    ];

    private static $defaults = [
        "ShowInMenus" => 0,
        "ShowInSearch" => 0,
        "ErrorCode" => 400
    ];

    private static $table_name = 'ErrorPage';

    private static $allowed_children = [];

    private static $description = 'Custom content for different error cases (e.g. "Page not found")';

    private static $icon_class = 'font-icon-p-error';

    /**
     * Allow developers to opt out of dev messaging using Config
     *
     * @var boolean
     */
    private static $dev_append_error_message = true;

    /**
     * Allows control over writing directly to the configured `GeneratedAssetStore`.
     *
     * @config
     * @var bool
     */
    private static $enable_static_file = true;

    /**
     * Prefix for storing error files in the {@see GeneratedAssetHandler} store.
     * Defaults to empty (top level directory)
     *
     * @config
     * @var string
     */
    private static $store_filepath = null;

    /**
     * An array of error codes to allow in the CMS
     * If null, defaults to all available codes (see ErrorPage::getCodes())
     */
    private static ?array $allowed_error_codes = null;

    /**
     * @param $member
     *
     * @return boolean
     */
    public function canAddChildren($member = null)
    {
        return false;
    }

    /**
     * Get a {@link HTTPResponse} to response to a HTTP error code if an
     * {@link ErrorPage} for that code is present. First tries to serve it
     * through the standard SilverStripe request method. Falls back to a static
     * file generated when the user hit's save and publish in the CMS
     *
     * @param int $statusCode
     * @param string|null $errorMessage A developer message to put in the response on dev envs
     * @return HTTPResponse
     * @throws HTTPResponse_Exception
     */
    public static function response_for($statusCode, $errorMessage = null)
    {
        // first attempt to dynamically generate the error page
        $errorPage = ErrorPage::get()
            ->filter([
                "ErrorCode" => $statusCode
            ])->first();

        if ($errorPage) {
            Requirements::clear();
            Requirements::clear_combined_files();

            //set @var dev_append_error_message to false to opt out of dev message
            $showDevMessage = (static::config()->dev_append_error_message === true);

            if ($errorMessage) {
                // Dev environments will have the error message added regardless of template changes
                if (Director::isDev() && $showDevMessage === true) {
                    $errorPage->Content .= "\n<p><b>Error detail: "
                        . Convert::raw2xml($errorMessage) ."</b></p>";
                }

                // On test/live environments, developers can opt to put $ResponseErrorMessage in their template
                $errorPage->ResponseErrorMessage = DBField::create_field('Varchar', $errorMessage);
            }

            $request = new HTTPRequest('GET', '');
            $request->setSession(Controller::curr()->getRequest()->getSession());
            return ModelAsController::controller_for($errorPage)
                ->handleRequest($request);
        }

        // then fall back on a cached version
        $content = ErrorPage::get_content_for_errorcode($statusCode);
        if ($content) {
            $response = new HTTPResponse();
            $response->setStatusCode($statusCode);
            $response->setBody($content);
            return $response;
        }

        return null;
    }

    /**
     * Ensures that there is always a 404 page by checking if there's an
     * instance of ErrorPage with a 404 and 500 error code. If there is not,
     * one is created when the DB is built.
     *
     * @throws ValidationException
     */
    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();

        // Only run on ErrorPage class directly, not subclasses
        if (static::class !== ErrorPage::class || !SiteTree::config()->get('create_default_pages')) {
            return;
        }

        $defaultPages = $this->getDefaultRecords();

        foreach ($defaultPages as $defaultData) {
            $this->requireDefaultRecordFixture($defaultData);
        }
    }

    /**
     * Build default record from specification fixture
     *
     * @param array $defaultData
     * @throws ValidationException
     */
    protected function requireDefaultRecordFixture($defaultData)
    {
        $code = $defaultData['ErrorCode'];

        $page = ErrorPage::get()->find('ErrorCode', $code);
        if (!$page) {
            $page = static::create();
            $page->update($defaultData);
            $page->write();
        }

        // Ensure page is published at latest version
        if (!$page->isLiveVersion()) {
            $page->publishSingle();
        }

        // Check if static files are enabled
        if (!static::config()->get('enable_static_file')) {
            return;
        }

        // Force create or refresh of static page
        $staticExists = $page->hasStaticPage();
        $success = $page->writeStaticPage();
        if (!$success) {
            DB::alteration_message(
                sprintf('%s error page could not be created. Please check permissions', $code),
                'error'
            );
        } elseif ($staticExists) {
            DB::alteration_message(
                sprintf('%s error page refreshed', $code),
                'created'
            );
        } else {
            DB::alteration_message(
                sprintf('%s error page created', $code),
                'created'
            );
        }
    }

    /**
     * Returns an array of arrays, each of which defines properties for a new
     * ErrorPage record.
     *
     * @return array
     */
    protected function getDefaultRecords()
    {
        $data = [
            [
                'ErrorCode' => 404,
                'Title' => _t('SilverStripe\\ErrorPage\\ErrorPage.DEFAULTERRORPAGETITLE', 'Page not found'),
                'Content' => _t(
                    'SilverStripe\\ErrorPage\\ErrorPage.DEFAULTERRORPAGECONTENT',
                    '<p>Sorry, it seems you were trying to access a page that doesn\'t exist.</p>'
                    . '<p>Please check the spelling of the URL you were trying to access and try again.</p>'
                )
            ],
            [
                'ErrorCode' => 500,
                'Title' => _t('SilverStripe\\ErrorPage\\ErrorPage.DEFAULTSERVERERRORPAGETITLE', 'Server error'),
                'Content' => _t(
                    'SilverStripe\\ErrorPage\\ErrorPage.DEFAULTSERVERERRORPAGECONTENT',
                    '<p>Sorry, there was a problem with handling your request.</p>'
                )
            ]
        ];

        $this->extend('getDefaultRecords', $data);

        return $data;
    }

    /**
     * @return FieldList
     */
    public function getCMSFields()
    {
        $this->beforeUpdateCMSFields(function (FieldList $fields) {
            $fields->addFieldToTab(
                'Root.Main',
                new DropdownField(
                    'ErrorCode',
                    $this->fieldLabel('ErrorCode'),
                    $this->getCodes()
                ),
                'Content'
            );
        });

        return parent::getCMSFields();
    }

    /**
     * When an error page is published, create a static HTML page with its
     * content, so the page can be shown even when SilverStripe is not
     * functioning correctly before publishing this page normally.
     *
     * @return bool True if published
     */
    public function publishSingle()
    {
        if (!parent::publishSingle()) {
            return false;
        }
        return $this->writeStaticPage();
    }

    /**
     * Determine if static content is cached for this page
     *
     * @return bool
     */
    protected function hasStaticPage()
    {
        if (!static::config()->get('enable_static_file')) {
            return false;
        }

        // Attempt to retrieve content from generated file handler
        $filename = $this->getErrorFilename();
        $storeFilename = File::join_paths(static::config()->get('store_filepath'), $filename);
        $result = ErrorPage::get_asset_handler()->getContent($storeFilename);
        return !empty($result);
    }

    /**
     * Write out the published version of the page to the filesystem.
     *
     * @return true if the page write was successful
     */
    public function writeStaticPage()
    {
        if (!static::config()->get('enable_static_file')) {
            return false;
        }

        // Run the page (reset the theme, it might've been disabled by LeftAndMain::init())
        $originalThemes = SSViewer::get_themes();
        try {
            // Restore front-end themes from config
            $themes = SSViewer::config()->get('themes') ?: $originalThemes;
            SSViewer::set_themes($themes);

            // Render page as non-member in live mode
            $response = Member::actAs(null, function () {
                $response = Director::test(Director::makeRelative($this->getAbsoluteLiveLink()));
                return $response;
            });

            $errorContent = $response->getBody();
        } finally {
            // Restore themes
            SSViewer::set_themes($originalThemes);
        }

        // Make sure we have content to save
        if ($errorContent) {
            // Store file content in the default store
            $storeFilename = File::join_paths(
                static::config()->get('store_filepath'),
                $this->getErrorFilename()
            );
            ErrorPage::get_asset_handler()->setContent($storeFilename, $errorContent);

            return true;
        } else {
            return false;
        }
    }

    /**
     * @param bool $includerelations a boolean value to indicate if the labels returned include relation fields
     * @return array
     */
    public function fieldLabels($includerelations = true)
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['ErrorCode'] = _t('SilverStripe\\ErrorPage\\ErrorPage.CODE', "Error code");

        return $labels;
    }

    /**
     * Returns statically cached content for a given error code
     *
     * @param int $statusCode A HTTP Statuscode, typically 404 or 500
     * @return string|null
     */
    public static function get_content_for_errorcode($statusCode)
    {
        if (!static::config()->get('enable_static_file')) {
            return null;
        }

        // Attempt to retrieve content from generated file handler
        $filename = ErrorPage::get_error_filename($statusCode);
        $storeFilename = File::join_paths(
            static::config()->get('store_filepath'),
            $filename
        );
        return ErrorPage::get_asset_handler()->getContent($storeFilename);
    }

    protected function getCodes()
    {
        $allCodes = [
            400 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_400', '400 - Bad Request'),
            401 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_401', '401 - Unauthorized'),
            402 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_402', '402 - Payment Required'),
            403 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_403', '403 - Forbidden'),
            404 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_404', '404 - Not Found'),
            405 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_405', '405 - Method Not Allowed'),
            406 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_406', '406 - Not Acceptable'),
            407 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_407', '407 - Proxy Authentication Required'),
            408 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_408', '408 - Request Timeout'),
            409 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_409', '409 - Conflict'),
            410 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_410', '410 - Gone'),
            411 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_411', '411 - Length Required'),
            412 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_412', '412 - Precondition Failed'),
            413 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_413', '413 - Request Entity Too Large'),
            414 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_414', '414 - Request-URI Too Long'),
            415 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_415', '415 - Unsupported Media Type'),
            416 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_416', '416 - Request Range Not Satisfiable'),
            417 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_417', '417 - Expectation Failed'),
            418 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_418', "418 - I'm a teapot"),
            421 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_421', '421 - Misdirected Request'),
            422 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_422', '422 - Unprocessable Entity'),
            423 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_423', '423 - Locked'),
            424 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_424', '424 - Failed Dependency'),
            425 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_425', '425 - Too Early'),
            426 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_426', '426 - Upgrade Required'),
            428 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_428', '428 - Precondition Required'),
            429 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_429', '429 - Too Many Requests'),
            431 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_431', '431 - Request Header Fields Too Large'),
            451 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_451', '451 - Unavailable For Legal Reasons'),
            500 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_500', '500 - Internal Server Error'),
            501 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_501', '501 - Not Implemented'),
            502 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_502', '502 - Bad Gateway'),
            503 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_503', '503 - Service Unavailable'),
            504 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_504', '504 - Gateway Timeout'),
            505 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_505', '505 - HTTP Version Not Supported'),
            506 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_506', '506 - Variant Also Negotiates'),
            507 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_507', '507 - Insufficient Storage'),
            508 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_508', '508 - Loop Detected'),
            510 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_510', '510 - Not Extended'),
            511 => _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_511', '511 - Network Authentication Required'),
        ];

        $allowedCodes = static::config()->get('allowed_error_codes');
        if ($allowedCodes === null) {
            return $allCodes;
        }

        return array_intersect_key($allCodes, array_flip($allowedCodes));
    }

    /**
     * Gets the filename identifier for the given error code.
     * Used when handling responses under error conditions.
     *
     * @param int $statusCode A HTTP Statuscode, typically 404 or 500
     * @param ErrorPage $instance Optional instance to use for name generation
     * @return string
     */
    protected static function get_error_filename($statusCode, $instance = null)
    {
        if (!$instance) {
            $instance = ErrorPage::singleton();
        }
        // Allow modules to extend this filename (e.g. for multi-domain, translatable)
        $name = "error-{$statusCode}.html";
        $instance->extend('updateErrorFilename', $name, $statusCode);
        return $name;
    }

    /**
     * Get filename identifier for this record.
     * Used for generating the filename for the current record.
     *
     * @return string
     */
    protected function getErrorFilename()
    {
        return ErrorPage::get_error_filename($this->ErrorCode, $this);
    }

    /**
     * @return GeneratedAssetHandler
     */
    protected static function get_asset_handler()
    {
        return Injector::inst()->get(GeneratedAssetHandler::class);
    }
}
