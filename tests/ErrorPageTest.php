<?php

namespace SilverStripe\ErrorPage\Tests;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Kernel;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\SSViewer;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @package    cms
 * @subpackage tests
 */
class ErrorPageTest extends FunctionalTest
{
    protected static $fixture_file = 'ErrorPageTest.yml';

    /**
     * Location of temporary cached files
     *
     * @var string
     */
    protected $tmpAssetsPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        // Set temporary asset backend store
        TestAssetStore::activate('ErrorPageTest');
        Config::modify()->set(ErrorPage::class, 'enable_static_file', true);
        $this->logInWithPermission('ADMIN');
    }

    protected function tearDown(): void
    {
        DB::quiet(true);
        TestAssetStore::reset();
        parent::tearDown();

        // When this test is run after FulltextSearchable has been enabled, you end up with some gost DB entries
        // that cause this test to fail.
        $this->objFromFixture(ErrorPage::class, '404')->doUnpublish();
        $this->objFromFixture(ErrorPage::class, '403')->doUnpublish();
    }

    public function test404ErrorPage()
    {
        /** @var ErrorPage $page */
        $page = $this->objFromFixture(ErrorPage::class, '404');
        // ensure that the errorpage exists as a physical file
        $page->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);

        $response = $this->get('nonexistent-page');

        /* We have body text from the error page */
        $this->assertNotNull($response->getBody(), 'We have body text from the error page');

        /* Status code of the HTTPResponse for error page is "404" */
        $this->assertEquals(
            $response->getStatusCode(),
            '404',
            'Status code of the HTTPResponse for error page is "404"'
        );

        /* Status message of the HTTPResponse for error page is "Not Found" */
        $this->assertEquals(
            $response->getStatusDescription(),
            'Not Found',
            'Status message of the HTTResponse for error page is "Not found"'
        );
    }

    public function testBehaviourOfShowInMenuAndShowInSearchFlags()
    {
        $page = $this->objFromFixture(ErrorPage::class, '404');

        /* Don't show the error page in the menus */
        $this->assertEquals($page->ShowInMenus, 0, 'Don\'t show the error page in the menus');

        /* Don't show the error page in the search */
        $this->assertEquals($page->ShowInSearch, 0, 'Don\'t show the error page in search');
    }

    public function testBehaviourOf403()
    {
        /** @var ErrorPage $page */
        $page = $this->objFromFixture(ErrorPage::class, '403');
        $page->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);

        try {
            $controller = singleton(ContentController::class);
            $controller->httpError(403);
            $this->fail('Expected exception to be thrown');
        } catch (HTTPResponse_Exception $e) {
            $response = $e->getResponse();
            $this->assertEquals($response->getStatusCode(), '403');
            $this->assertNotNull($response->getBody(), 'We have body text from the error page');
        }
    }

    public function testSecurityError()
    {
        // Generate 404 page
        /** @var ErrorPage $page */
        $page = $this->objFromFixture(ErrorPage::class, '404');
        $page->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);

        // Test invalid action
        $response = $this->get('Security/nosuchaction');
        $this->assertEquals($response->getStatusCode(), '404');
        $this->assertNotNull($response->getBody());
        $this->assertStringContainsString('text/html', $response->getHeader('Content-Type'));
    }

    public function testStaticCaching()
    {
        // Test new error code does not have static content
        $error = ErrorPage::get_content_for_errorcode('401');
        $this->assertEmpty($error);
        $expectedErrorPagePath = TestAssetStore::base_path() . '/error-401.html';
        $this->assertFileDoesNotExist($expectedErrorPagePath, 'Error page is not automatically cached');

        // Write new 401 page
        $page = new ErrorPage();
        $page->Title = '401 Error';
        $page->ErrorCode = 401;
        $page->Title = 'Unauthorised';
        $page->write();
        $page->publishRecursive();

        // Static cache should now exist
        $this->assertNotEmpty(ErrorPage::get_content_for_errorcode('401'));
        $expectedErrorPagePath = TestAssetStore::base_path() . '/error-401.html';
        $this->assertFileExists($expectedErrorPagePath, 'Error page is cached');
    }

    public function testThemedCaching()
    {
        // Empty theme should not break static caching
        SSViewer::set_themes([
            SSViewer::DEFAULT_THEME,
        ]);
        $this->testStaticCaching();
    }

    /**
     * Test fallback to file generation API with enable_static_file disabled
     */
    public function testGeneratedFile()
    {
        Config::modify()->set(ErrorPage::class, 'enable_static_file', false);
        $this->logInWithPermission('ADMIN');

        $page = new ErrorPage();
        $page->ErrorCode = 405;
        $page->Title = 'Method Not Allowed';
        $page->write();
        $page->publishRecursive();

        // Dynamic content is available
        $response = ErrorPage::response_for('405');
        $this->assertNotEmpty($response);
        $this->assertNotEmpty($response->getBody());
        $this->assertEquals(405, (int)$response->getStatusCode());

        // Static content is not available
        $this->assertEmpty(ErrorPage::get_content_for_errorcode('405'));
        $expectedErrorPagePath = TestAssetStore::base_path() . '/error-405.html';
        $this->assertFileDoesNotExist($expectedErrorPagePath, 'Error page is not cached in static location');
    }

    public function testGetByLink()
    {
        $notFound = $this->objFromFixture(ErrorPage::class, '404');

        Config::modify()->set(SiteTree::class, 'nested_urls', false);
        $this->assertEquals($notFound->ID, SiteTree::get_by_link($notFound->Link(), false)->ID);

        Config::modify()->set(SiteTree::class, 'nested_urls', true);
        $this->assertEquals($notFound->ID, SiteTree::get_by_link($notFound->Link(), false)->ID);
    }

    public function testIsCurrent()
    {
        $aboutPage = $this->objFromFixture(SiteTree::class, 'about');
        $errorPage = $this->objFromFixture(ErrorPage::class, '404');

        Director::set_current_page($aboutPage);
        $this->assertFalse($errorPage->isCurrent(), 'Assert isCurrent works on error pages.');

        Director::set_current_page($errorPage);
        $this->assertTrue($errorPage->isCurrent(), 'Assert isCurrent works on error pages.');
    }

    public function testWriteStaticPageWithDisableStaticFile()
    {
        ErrorPage::config()->set('enable_static_file', false);
        /**
         * @var ErrorPage
         */
        $errorPage = $this->objFromFixture(ErrorPage::class, '404');

        $this->assertFalse(
            $errorPage->writeStaticPage(),
            'writeStaticPage should return false when enable_static_file is true'
        );
        $expectedErrorPagePath = TestAssetStore::base_path() . '/error-404.html';
        $this->assertFileDoesNotExist($expectedErrorPagePath, 'Error page should not be cached.');
    }

    /**
     * @param string $env
     * @param bool $shouldShowInDev
     */
    #[DataProvider('provideErrorMessageEnv')]
    public function testErrorMessageAppended($env, $shouldShowInDev)
    {
        /* @var Kernel $kernel */
        $kernel = Injector::inst()->get(Kernel::class);
        $originalEnv = $kernel->getEnvironment();
        $kernel->setEnvironment($env);
        ErrorPage::config()->set('dev_append_error_message', $shouldShowInDev);
        /* @var HTTPResponse $response */
        $response = ErrorPage::response_for(404, 'Really bad error');
        $this->assertNotEmpty($response->getBody());
        if ($env === 'dev' && $shouldShowInDev) {
            $this->assertStringContainsString('Really bad error', $response->getBody());
        } else {
            $this->assertStringNotContainsString('Really bad error', $response->getBody());
        }

        $kernel->setEnvironment($originalEnv);
    }

    /**
     * @return array
     */
    public static function provideErrorMessageEnv()
    {
        return [
            ['dev', true],
            ['dev', false],
            ['live', true],
            ['live', false]
        ];
    }

    /**
     * @throws ValidationException
     */
    public function testRequiredRecords()
    {
        // Test that 500 error page creates static content
        Config::modify()->set(SiteTree::class, 'create_default_pages', true);
        DB::quiet(false);
        $this->expectOutputRegex('/.*500 error page created.*/');
        ErrorPage::singleton()->requireDefaultRecords();

        // Page is published
        /** @var ErrorPage $error500Page */
        $error500Page = ErrorPage::get()->find('ErrorCode', 500);
        $this->assertInstanceOf(ErrorPage::class, $error500Page);
        $this->assertTrue($error500Page->isLiveVersion());

        // Check content is valid
        $error500Content = ErrorPage::get_content_for_errorcode(500);
        $this->assertNotEmpty($error500Content);

        // Rebuild and ensure that static files are refreshed
        DB::quiet(false);
        $this->expectOutputRegex('/.*500 error page refreshed.*/');
        ErrorPage::singleton()->requireDefaultRecords();
    }

    public function testAllowedAllErrorCodes()
    {
        $page = new ErrorPage();
        $allCodes = $page->getCMSFields()->dataFieldByName('ErrorCode')->getSource();
        $this->assertCount(40, $allCodes);
    }

    public function testAllowedErrorCodes()
    {
        Config::modify()->set(ErrorPage::class, 'allowed_error_codes', [400, 500]);
        $page = new ErrorPage();
        $codes = $page->getCMSFields()->dataFieldByName('ErrorCode')->getSource();
        $this->assertCount(2, $codes);
        $this->assertArrayHasKey(400, $codes);
        $this->assertArrayHasKey(500, $codes);
    }
}
