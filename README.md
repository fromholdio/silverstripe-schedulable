# silverstripe-schedulable

This module adds embargo/expiry scheduling to your data objects and site tree.

Also adds a `GridFieldScheduledStatus` component, allowing you to display the scheduled status of an object within your `GridField`.

## Requirements

* [silverstripe-framework](https://github.com/silverstripe/silverstripe-framework) ^6

(See branch 1.x for Silverstripe 4 & 5 support.)

## Installation

`composer require fromholdio/silverstripe-schedulable`

## Details & Usage

It's essentially plug-n-play - just apply the `Schedulable` extension to your `DataObject` or `SiteTree` class.

It works by augmenting the regular sql query and including/excluding records based on their embargo/expiry settings. This doesn't apply if stage=Stage and your user has VIEW_DRAFT_CONTENT permission, or if you're within an Admin controller.

Works with Versioned objects, when objects are embargoed or expired for the current date on the active Versioned stage, they will not be returned in your queries.

Provides some additional accessors:

* `IsScheduleEmbargoed()` = true if object is embargoed (becomes false once the embargo date/time is passed)
* `IsScheduleExpired()` = true if object has expired (false until the expiry date/time is reached)
* `WillScheduleExpire()` = true if object is not currently embargoed, and will expired, but has not yet
* `getScheduleStatusLabel()` = returns a human readable description of current status (e.g. "Embargoed until 01/01/2020 12:00:00")

If an object has both an embargo-until date/time, and an expiry date/time, `getScheduleStatusLabel()` will reflect the most relevant current state. (So, in that case, it will return that it is Embargoed Until X, and once that date/time passes, it will change to Will Expire At Y.) 

Example of applying the extension to `SiteTree` class:

```yml
---
Name: extensions
---
SilverStripe\CMS\Model\SiteTree:
  extensions:
    - Fromholdio\Schedulable\Extensions\Schedulable
```

## To Do

* Better docs
