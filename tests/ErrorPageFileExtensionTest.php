<?php

namespace SilverStripe\ErrorPage\Tests;

use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Shortcodes\FileShortcodeProvider;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Parsers\ShortcodeParser;

class ErrorPageFileExtensionTest extends FunctionalTest
{
    /**
     * @var string
     */
    protected static $fixture_file = 'ErrorPageTest.yml';

    /**
     * @var string|null
     */
    protected $versionedMode = null;

    protected function setUp() : void
    {
        parent::setUp();
        $this->versionedMode = Versioned::get_reading_mode();
        Versioned::set_stage(Versioned::DRAFT);
        TestAssetStore::activate('ErrorPageFileExtensionTest');
        // Required so that shortcodes check permissions
        Config::modify()->set(FileShortcodeProvider::class, 'shortcodes_inherit_canview', false);
        $file = File::create();
        $file->setFromString('dummy', 'dummy.txt');
        $file->CanViewType = InheritedPermissions::LOGGED_IN_USERS;
        $file->write();
    }

    protected function tearDown() : void
    {
        DB::quiet(true);
        Versioned::set_reading_mode($this->versionedMode);
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testErrorPage()
    {
        // Get and publish records
        /** @var ErrorPage $notFoundPage */
        $notFoundPage = $this->objFromFixture(ErrorPage::class, '404');
        $notFoundPage->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $notFoundLink = $notFoundPage->Link();

        /** @var ErrorPage $disallowedPage */
        $disallowedPage = $this->objFromFixture(ErrorPage::class, '403');
        $disallowedPage->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $disallowedLink = $disallowedPage->Link();

        // Get stage version of file
        /** @var File $file */
        $file = File::get()->filter('Name', 'dummy.txt')->first();
        $fileLink = $file->Link();
        Security::setCurrentUser(null);

        // Generate shortcode for a file which doesn't exist
        $shortcode = FileShortcodeProvider::handle_shortcode(
            ['id' => 9999],
            null,
            ShortcodeParser::create(),
            'file_link'
        );
        $this->assertEquals($notFoundLink, $shortcode);
        $shortcode = FileShortcodeProvider::handle_shortcode(
            ['id' => 9999],
            'click here',
            ShortcodeParser::create(),
            'file_link'
        );
        $this->assertEquals(sprintf('<a href="%s">%s</a>', $notFoundLink, 'click here'), $shortcode);

        // Test that user cannot view secured file
        $shortcode = FileShortcodeProvider::handle_shortcode(
            ['id' => $file->ID],
            null,
            ShortcodeParser::create(),
            'file_link'
        );
        $this->assertEquals($disallowedLink, $shortcode);
        $shortcode = FileShortcodeProvider::handle_shortcode(
            ['id' => $file->ID],
            'click here',
            ShortcodeParser::create(),
            'file_link'
        );
        $this->assertEquals(sprintf('<a href="%s">%s</a>', $disallowedLink, 'click here'), $shortcode);

        // Authenticated users don't get the same error
        $this->logInWithPermission('ADMIN');
        $shortcode = FileShortcodeProvider::handle_shortcode(
            ['id' => $file->ID],
            null,
            ShortcodeParser::create(),
            'file_link'
        );
        $this->assertEquals($fileLink, $shortcode);
    }

    /**
     * @throws ValidationException
     */
    public function testRequiredRecords()
    {
        $page = ErrorPage::singleton();
        // Test that 500 error page creates static content
        Config::modify()->set(SiteTree::class, 'create_default_pages', true);

        DB::quiet(false);

        $this->expectOutputRegex('/.*500 error page created.*/');
        $page->requireDefaultRecords();

        // Page is published
        /** @var ErrorPage $error500Page */
        $error500Page = ErrorPage::get()->find('ErrorCode', 500);
        $this->assertInstanceOf(ErrorPage::class, $error500Page);
        $this->assertTrue($error500Page->isLiveVersion());

        // Check content is valid
        $error500Content = $page->getContentForErrorcode(500);
        $this->assertNotEmpty($error500Content);

        // Rebuild and ensure that static files are refreshed
        DB::quiet(false);

        $this->expectOutputRegex('/.*500 error page refreshed.*/');
        $page->requireDefaultRecords();
    }
}
