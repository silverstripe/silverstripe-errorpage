# SilverStripe ErrorPage Module

[![Build Status](https://api.travis-ci.org/silverstripe/silverstripe-errorpage.svg?branch=master)](https://travis-ci.org/silverstripe/silverstripe-errorpage)
[![Latest Stable Version](https://poser.pugx.org/silverstripe/errorpage/version.svg)](http://www.silverstripe.org/stable-download/)
[![Latest Unstable Version](https://poser.pugx.org/silverstripe/errorpage/v/unstable.svg)](https://packagist.org/packages/silverstripe/errorpage)
[![Total Downloads](https://poser.pugx.org/silverstripe/errorpage/downloads.svg)](https://packagist.org/packages/silverstripe/errorpage)
[![License](https://poser.pugx.org/silverstripe/errorpage/license.svg)](https://github.com/silverstripe/silverstripe-errorpage#license)
[![Dependency Status](https://www.versioneye.com/php/silverstripe:errorpage/badge.svg)](https://www.versioneye.com/php/silverstripe:errorpage)
[![Reference Status](https://www.versioneye.com/php/silverstripe:errorpage/reference_badge.svg?style=flat)](https://www.versioneye.com/php/silverstripe:errorpage/references)
![helpfulrobot](https://helpfulrobot.io/silverstripe/errorpage/badge)

## Overview

Provides an ErrorPage page type for the [SilverStripe CMS](https://github.com/silverstripe/silverstripe-cms), allowing CMS authors to set custom content for error page responses by error code. Error page responses are fully themed.

## Installation

```
$ composer require silverstripe/errorpage
```

You'll also need to run `dev/build`, which will generate a 500 and 404 error page.

## Limitations

The functionally in this module was separated out from the SilverStripe CMS module and retains some [existing issues](https://github.com/silverstripe/silverstripe-framework/issues/4149).
An issue of note is that static error pages are generated but are rarely served up, and rarely re-generated. This can lead to website visitors seeing a stale or broken page in the event of a 500 server error.
Contributions are welcome, please open a pull request if you want to add a feature or fix a problem.

## Documentation

To Do

## Reporting Issues

Please [create an issue](http://github.com/silverstripe/silverstripe-errorpage/issues) for any bugs you've found, or features you're missing.
