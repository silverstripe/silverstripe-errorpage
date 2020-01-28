<?php

namespace SilverStripe\ErrorPage;

use SilverStripe\Control\Director;
use SilverStripe\Logging\DebugViewFriendlyErrorFormatter;

/**
 * Class ErrorPageErrorFormatter
 *
 * Provides @see ErrorPage - gnostic error handling
 *
 * @package SilverStripe\ErrorPage
 */
class ErrorPageErrorFormatter extends DebugViewFriendlyErrorFormatter
{
    /**
     * @param int $statusCode
     * @return string|null
     */
    public function output($statusCode)
    {
        // Ajax content is plain-text only
        if (Director::is_ajax()) {
            return $this->getTitle();
        }

        // Determine if cached ErrorPage content is available
        $content = ErrorPage::singleton()->getContentForErrorcode($statusCode);
        if ($content) {
            return $content;
        }

        // Fallback to default output
        return parent::output($statusCode);
    }
}
