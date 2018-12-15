eWAY Recurring Payment Processor for CiviCRM
------

CiviCRM payment processor extension for [eWay](https://eway.com.au) which implements recurring payments using tokens. This method is essential for automating the process setting up recurring donations and memberships in your CiviCRM securely and reliably.

This payment processor also allows you to specify a particular day of the month to process all recurring payments together.

You will need to have a [eWay account](https://eway.com.au) to use this payment processor on your CiviCRM website.

This extension currently utilises the eWay token API which has been deprecated by eWay. [eWay Rapid API](https://www.eway.com.au/features/api-rapid-api/) support is currently available for testing in the [rapidapi branch](https://github.com/agileware/au.com.agileware.ewayrecurring/archive/rapidapi.zip).

Support for the eWay token API will be dropped once eWay Rapid API integration is stable. The extension upgrade will transition to the new API.

Installation
------

1. Download the [latest version of this extension](https://github.com/agileware/au.com.agileware.ewayrecurring/archive/master.zip)
1. Unzip in the CiviCRM extension directory, as defined in "System Settings / Directories'.
1. Go to "Administer / System Settings / Extensions" and enable the "eWay_Recurring (au.com.agileware.ewayrecurring)" extension.
1. Configure the payment processor with your eWay API token and password as obtained from your (eWay Account](https://go.eway.io)

Sponsorship
------

Initial development of this CiviCRM extension for recurring functionality and token payments was kindly sponsored by Stephen Garrett of
[Good Reason](http://www.goodreason.com.au).

About the Authors
------

This CiviCRM extension was developed by the team at [Agileware](https://agileware.com.au).

[Agileware](https://agileware.com.au) provide a range of CiviCRM services including:

  * CiviCRM migration
  * CiviCRM integration
  * CiviCRM extension development
  * CiviCRM support
  * CiviCRM hosting
  * CiviCRM remote training services

Support your Australian [CiviCRM](https://civicrm.org) developers, [contact Agileware](https://agileware.com.au/contact) today!


![Agileware](logo/agileware-logo.png)  