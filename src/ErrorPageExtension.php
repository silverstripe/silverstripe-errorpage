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
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\DropdownField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Requirements;
use SilverStripe\View\SSViewer;

/**
 * Class ErrorPageExtension
 *
 * @property int $ErrorCode
 * @property ErrorPage $owner
 * @package SilverStripe\ErrorPage
 */
class ErrorPageExtension extends DataExtension
{
    /**
     * @config
     * @var array
     */
    private static $db = [
        'ErrorCode' => 'Int',
    ];

    /**
     * @config
     * @var array
     */
    private static $defaults = [
        'ShowInMenus' => 0,
        'ShowInSearch' => 0,
        'ErrorCode' => ErrorPage::HTTP_RESPONSE_CODE_400,
    ];

    /**
     * @config
     * @var array
     */
    private static $allowed_children = [];

    /**
     * @config
     * @var string
     */
    private static $description = 'Custom content for different error cases (e.g. "Page not found")';

    /**
     * @config
     * @var string
     */
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
     * Prefix for storing error files in the @see GeneratedAssetHandler store.
     * Defaults to empty (top level directory)
     *
     * @config
     * @var string|null
     */
    private static $store_filepath = null;

    /**
     * @param Member|null $member
     * @return bool
     */
    public function canAddChildren(?Member $member = null): bool
    {
        return false;
    }

    /**
     * Default records are allowed by default
     * override on model if needed
     *
     * @return bool
     */
    public function defaultRecordsAllowed(): bool
    {
        return true;
    }

    /**
     * @return DropdownField
     */
    public function createErrorCodeField(): DropdownField
    {
        $owner = $this->owner;

        return DropdownField::create(
            'ErrorCode',
            $owner->fieldLabel('ErrorCode'),
            $owner->getCodes()
        );
    }

    /**
     * Get a {@link HTTPResponse} to response to a HTTP error code if an
     * {@link ErrorPage} for that code is present. First tries to serve it
     * through the standard SilverStripe request method. Falls back to a static
     * file generated when the user hit's save and publish in the CMS
     *
     * @param int $statusCode
     * @param string|null $errorMessage
     * @return HTTPResponse|null
     * @throws HTTPResponse_Exception
     */
    public function responseFor($statusCode, $errorMessage = null): ?HTTPResponse
    {
        $owner = $this->owner;

        // first attempt to dynamically generate the error page
        /** @var ErrorPage $errorPage */
        $errorPage = DataObject::get($owner->ClassName)->find('ErrorCode', $statusCode);

        if ($errorPage) {
            Requirements::clear();
            Requirements::clear_combined_files();

            if ($errorMessage) {
                //set @var dev_append_error_message to false to opt out of dev message
                $showDevMessage = (bool) $owner->config()->get('dev_append_error_message');

                // Dev environments will have the error message added regardless of template changes
                if (Director::isDev() && $showDevMessage) {
                    $errorPage->Content .= sprintf(
                        '%s<p><b>Error detail: %s</b></p>',
                        PHP_EOL,
                        Convert::raw2xml($errorMessage)
                    );
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
        $content = $owner->getContentForErrorcode($statusCode);
        if ($content) {
            $response = new HTTPResponse();
            $response->setStatusCode($statusCode);
            $response->setBody($content);

            return $response;
        }

        return null;
    }

    /**
     * Returns statically cached content for a given error code
     *
     * @param int $statusCode A HTTP Statuscode, typically 404 or 500
     * @return string|null
     */
    public function getContentForErrorcode($statusCode): ?string
    {
        $owner = $this->owner;

        if (!$owner->config()->get('enable_static_file')) {
            return null;
        }

        // Attempt to retrieve content from generated file handler
        $filename = $this->getErrorFilename($statusCode);
        $storeFilename = File::join_paths(
            $owner->config()->get('store_filepath'),
            $filename
        );

        return $owner->getAssetHandler()->getContent($storeFilename);
    }

    /**
     * Write out the published version of the page to the filesystem.
     *
     * @return bool true if the page write was successful
     */
    public function writeStaticPage(): bool
    {
        $owner = $this->owner;

        if (!$owner->config()->get('enable_static_file')) {
            return false;
        }

        // Run the page (reset the theme, it might've been disabled by LeftAndMain::init())
        $originalThemes = SSViewer::get_themes();

        try {
            // Restore front-end themes from config
            $themes = SSViewer::config()->get('themes') ?: $originalThemes;
            SSViewer::set_themes($themes);

            // Render page as non-member in live mode
            $response = Member::actAs(null, function () use ($owner) {
                $response = Director::test(Director::makeRelative($owner->getAbsoluteLiveLink()));

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
                $owner->config()->get('store_filepath'),
                $this->getErrorFilename()
            );
            $owner->getAssetHandler()->setContent($storeFilename, $errorContent);

            return true;
        }

        return false;
    }

    /**
     * Determine if static content is cached for this page
     *
     * @return bool
     */
    public function hasStaticPage(): bool
    {
        $owner = $this->owner;

        if (!$owner->config()->get('enable_static_file')) {
            return false;
        }

        // Attempt to retrieve content from generated file handler
        $filename = $this->getErrorFilename();
        $storeFilename = File::join_paths($owner->config()->get('store_filepath'), $filename);
        $result = $owner->getAssetHandler()->getContent($storeFilename);

        return !empty($result);
    }

    /**
     * Ensures that there is always a 404 page by checking if there's an
     * instance of ErrorPage with a 404 and 500 error code. If there is not,
     * one is created when the DB is built.
     *
     * @throws ValidationException
     */
    public function requireDefaultRecords(): void
    {
        if (!SiteTree::config()->get('create_default_pages')) {
            return;
        }

        $owner = $this->owner;

        if (!$owner->defaultRecordsAllowed()) {
            return;
        }

        $defaultPages = $owner->getDefaultRecordsData();

        foreach ($defaultPages as $defaultData) {
            $this->requireDefaultRecordFixture($defaultData);
        }
    }

    /**
     * @return GeneratedAssetHandler
     */
    public function getAssetHandler(): GeneratedAssetHandler
    {
        return Injector::inst()->get(GeneratedAssetHandler::class);
    }

    /**
     * @return array
     */
    public function getCodes(): array
    {
        $codes = [
            ErrorPage::HTTP_RESPONSE_CODE_400,
            ErrorPage::HTTP_RESPONSE_CODE_401,
            ErrorPage::HTTP_RESPONSE_CODE_403,
            ErrorPage::HTTP_RESPONSE_CODE_404,
            ErrorPage::HTTP_RESPONSE_CODE_405,
            ErrorPage::HTTP_RESPONSE_CODE_406,
            ErrorPage::HTTP_RESPONSE_CODE_407,
            ErrorPage::HTTP_RESPONSE_CODE_408,
            ErrorPage::HTTP_RESPONSE_CODE_409,
            ErrorPage::HTTP_RESPONSE_CODE_410,
            ErrorPage::HTTP_RESPONSE_CODE_411,
            ErrorPage::HTTP_RESPONSE_CODE_412,
            ErrorPage::HTTP_RESPONSE_CODE_413,
            ErrorPage::HTTP_RESPONSE_CODE_414,
            ErrorPage::HTTP_RESPONSE_CODE_415,
            ErrorPage::HTTP_RESPONSE_CODE_416,
            ErrorPage::HTTP_RESPONSE_CODE_417,
            ErrorPage::HTTP_RESPONSE_CODE_422,
            ErrorPage::HTTP_RESPONSE_CODE_429,
            ErrorPage::HTTP_RESPONSE_CODE_500,
            ErrorPage::HTTP_RESPONSE_CODE_501,
            ErrorPage::HTTP_RESPONSE_CODE_502,
            ErrorPage::HTTP_RESPONSE_CODE_503,
            ErrorPage::HTTP_RESPONSE_CODE_504,
            ErrorPage::HTTP_RESPONSE_CODE_505,
        ];

        $labels = [
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_400', '400 - Bad Request'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_401', '401 - Unauthorized'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_403', '403 - Forbidden'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_404', '404 - Not Found'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_405', '405 - Method Not Allowed'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_406', '406 - Not Acceptable'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_407', '407 - Proxy Authentication Required'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_408', '408 - Request Timeout'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_409', '409 - Conflict'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_410', '410 - Gone'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_411', '411 - Length Required'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_412', '412 - Precondition Failed'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_413', '413 - Request Entity Too Large'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_414', '414 - Request-URI Too Long'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_415', '415 - Unsupported Media Type'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_416', '416 - Request Range Not Satisfiable'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_417', '417 - Expectation Failed'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_422', '422 - Unprocessable Entity'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_429', '429 - Too Many Requests'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_500', '500 - Internal Server Error'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_501', '501 - Not Implemented'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_502', '502 - Bad Gateway'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_503', '503 - Service Unavailable'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_504', '504 - Gateway Timeout'),
            _t('SilverStripe\\ErrorPage\\ErrorPage.CODE_505', '505 - HTTP Version Not Supported'),
        ];

        return array_combine($codes, $labels);
    }

    /**
     * Returns an array of arrays, each of which defines properties for a new ErrorPage record.
     *
     * @return array
     */
    public function getDefaultRecordsData(): array
    {
        $owner = $this->owner;
        $data = [
            [
                'ErrorCode' => ErrorPage::HTTP_RESPONSE_CODE_404,
                'Title' => _t('SilverStripe\\ErrorPage\\ErrorPage.DEFAULTERRORPAGETITLE', 'Page not found'),
                'Content' => _t(
                    'SilverStripe\\ErrorPage\\ErrorPage.DEFAULTERRORPAGECONTENT',
                    '<p>Sorry, it seems you were trying to access a page that doesn\'t exist.</p>'
                    . '<p>Please check the spelling of the URL you were trying to access and try again.</p>'
                )
            ],
            [
                'ErrorCode' => ErrorPage::HTTP_RESPONSE_CODE_500,
                'Title' => _t('SilverStripe\\ErrorPage\\ErrorPage.DEFAULTSERVERERRORPAGETITLE', 'Server error'),
                'Content' => _t(
                    'SilverStripe\\ErrorPage\\ErrorPage.DEFAULTSERVERERRORPAGECONTENT',
                    '<p>Sorry, there was a problem with handling your request.</p>'
                )
            ]
        ];

        $owner->extend('getDefaultRecords', $data);

        return $data;
    }

    /**
     * Extension point in @see Versioned::publishSingle
     *
     * @param DataObject $original
     */
    public function onAfterPublish($original): void
    {
        $this->ensureStaticPage();
    }

    /**
     * Extension point in @see DataObject::fieldLabels
     *
     * @param array $labels
     */
    public function updateFieldLabels(&$labels): void
    {
        $labels = $this->addErrorCodeFieldLabel($labels);
    }

    protected function addErrorCodeFieldLabel(array $labels): array
    {
        $labels['ErrorCode'] = _t('SilverStripe\\ErrorPage\\ErrorPage.CODE', 'Error code');

        return $labels;
    }

    /**
     * When an error page is published, create a static HTML page with its
     * content, so the page can be shown even when SilverStripe is not
     * functioning correctly before publishing this page normally.
     */
    protected function ensureStaticPage(): void
    {
        $owner = $this->owner;

        if (!$owner->isInDB()) {
            return;
        }


        $owner->writeStaticPage();
    }

    /**
     * Gets the filename identifier for the given error code.
     * Used when handling responses under error conditions.
     *
     * @param int|null $statusCode A HTTP Statuscode, typically 404 or 500
     * @return string
     */
    protected function getErrorFilename($statusCode = null): string
    {
        $owner = $this->owner;

        if ($statusCode === null) {
            $statusCode = $owner->ErrorCode;
        }

        // Allow modules to extend this filename (e.g. for multi-domain, translatable)
        $name = sprintf('error-%s.html', $statusCode);

        $owner->extend('updateErrorFilename', $name, $statusCode);

        return $name;
    }

    /**
     * Build default record from specification fixture
     *
     * @param array $defaultData
     * @throws ValidationException
     */
    protected function requireDefaultRecordFixture($defaultData): void
    {
        $owner = $this->owner;
        $code = $defaultData['ErrorCode'];

        /** @var Page|ErrorPageExtension $page */
        $page = DataObject::get($owner->ClassName)->find('ErrorCode', $code);
        if (!$page) {
            $page = Injector::inst()->create($owner->ClassName);
            $page->update($defaultData);
            $page->write();
        }

        // Ensure page is published at latest version
        if (!$page->isLiveVersion()) {
            $page->publishSingle();
        }

        // Check if static files are enabled
        if (!$owner->config()->get('enable_static_file')) {
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
            DB::alteration_message(sprintf('%s error page created', $code),
                'created'
            );
        }
    }
}
