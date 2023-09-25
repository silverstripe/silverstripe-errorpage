# Silverstripe ErrorPage Module

[![CI](https://github.com/silverstripe/silverstripe-errorpage/actions/workflows/ci.yml/badge.svg)](https://github.com/silverstripe/silverstripe-errorpage/actions/workflows/ci.yml)
[![Silverstripe supported module](https://img.shields.io/badge/silverstripe-supported-0071C4.svg)](https://www.silverstripe.org/software/addons/silverstripe-commercially-supported-module-list/)

## Overview

Provides an ErrorPage page type for the [Silverstripe CMS](https://github.com/silverstripe/silverstripe-cms), allowing CMS authors to set custom content for error page responses by error code. Error page responses are fully themed.

## Installation

```sh
composer require silverstripe/errorpage
```

## Limitations

The functionally in this module was separated out from the Silverstripe CMS module and retains some existing issues.
An issue of note is that static error pages are generated but are rarely served up, and rarely re-generated. This can lead to website visitors seeing a stale or broken page in the event of a 500 server error.
Contributions are welcome, please open a pull request if you want to add a feature or fix a problem.

## Documentation

To Do
### Theming
To apply a custom template for the error page you will need to create a ErrorPage.ss file in either `templates/SilverStripe/ErrorPage/ErrorPage.ss` or `templates/SilverStripe/ErrorPage/Layout/ErrorPage.ss`

### Detailed error messages
If you're using `$this->httpError($code, $message)` in your codebase and want to include the message that is passed into that method, you can add the `$ResponseErrorMessage` to your ErrorPage template. Use caution when including this information, as these messages are often intended for developers rather than end-users. 

For example, one of your controllers may throw a 401 status code for some specific reason. Very broadly, a 401 means the request is Unauthorised; if your controller throws a 401, it can add also add a specific reason:

```YourController.php
return $this->httpError(401, 'This is only accessible on Sunday before 10AM.');```

```YourErrorPage.ss
<h1>$Title</h1>
<% if ResponseErrorMessage %>
<p class="lead">
$ResponseErrorMessage
</p>
<% end_if %>
</div>
```

These messages are appended to the ErrorPage template automatically when the site is in dev mode. You can disable this using the Config API. Setting this to false will also remove the $ResponseErrorMessage variable from your template.
```yml
SilverStripe\ErrorPage\ErrorPage:
  dev_append_error_message: false
```

### Limiting options in the CMS
By default, all available error codes are present in the dropdown in the CMS. This can be overwhelming and there are a few (looking at you, 418) that can
be confusing. To that end, you can limit the codes in the dropdown with the config value `allowed_error_codes` like so:

```yml
SilverStripe\ErrorPage\ErrorPage:
  allowed_error_codes:
    - 400
    - 403
    - 404
    - 500
```

## Reporting Issues

Please [create an issue](http://github.com/silverstripe/silverstripe-errorpage/issues) for any bugs you've found, or features you're missing.
