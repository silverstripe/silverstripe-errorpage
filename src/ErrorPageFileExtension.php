<?php

namespace SilverStripe\ErrorPage;

use SilverStripe\Assets\Shortcodes\FileShortcodeProvider;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;

/**
 * Decorates {@see File} with ErrorPage support
 *
 * @extends Extension<FileShortcodeProvider>
 */
class ErrorPageFileExtension extends Extension
{
    /**
     * Used by {@see File::handle_shortcode}
     *
     * @param int $statusCode HTTP Error code
     * @return DataObject Substitute object suitable for handling the given error code
     */
    protected function getErrorRecordFor($statusCode)
    {
        return ErrorPage::get()->filter("ErrorCode", $statusCode)->first();
    }
}
