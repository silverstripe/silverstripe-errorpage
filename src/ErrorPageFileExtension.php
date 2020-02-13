<?php

namespace SilverStripe\ErrorPage;

use SilverStripe\Assets\Shortcodes\FileShortcodeProvider;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;

/**
 * Class ErrorPageFileExtension
 *
 * Decorates @see FileShortcodeProvider::handle_shortcode() with ErrorPage support
 *
 * @property FileShortcodeProvider $owner
 * @package SilverStripe\ErrorPage
 */
class ErrorPageFileExtension extends DataExtension
{
    /**
     * Used by @see FileShortcodeProvider::handle_shortcode()
     *
     * @param int $statusCode HTTP Error code
     * @return DataObject|null Substitute object suitable for handling the given error code
     */
    public function getErrorRecordFor($statusCode)
    {
        $page = ErrorPage::singleton();

        if (!$page->hasExtension(ErrorPageExtension::class)) {
            return null;
        }

        return DataObject::get($page->ClassName)->find('ErrorCode', $statusCode);
    }
}
