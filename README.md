eWay Recurring Payment Processor for CiviCRM
--------------------------------------------

CiviCRM payment processor extension for [eWay](https://eway.com.au) which uses the latest [eWay Rapid API](https://www.eway.com.au/features/api-rapid-api/) and ensures [PCI DSS compliance](https://www.eway.com.au/about-eway/technology-security/pci-dss/). Supports both once-off and recurring payment utilising the secure token payment method. This essential for automating the process setting up recurring donations and memberships in your CiviCRM securely and reliably. This payment processor also allows you to specify a particular day of the month to process all recurring payments together.

This extension previously utilised the eWay token API which has been deprecated by eWay. [eWay Rapid API](https://www.eway.com.au/features/api-rapid-api/) is now used with the Responsive Shared Page method for a higher standard of [PCI DSS compliance](https://www.eway.com.au/about-eway/technology-security/pci-dss/).

You will need to have a [eWay account](https://eway.com.au) to use this payment processor on your CiviCRM website.

Installation
------------

1. Download the [latest version of this
   extension](https://github.com/agileware/au.com.agileware.ewayrecurring/archive/master.zip)
2. Extract in your CiviCRM extensions directory, as defined in "System Settings /
   Directories".
3. Go to "Administer / System Settings / Extensions" and enable the "eWay
   Recurring Payment Processor (au.com.agileware.ewayrecurring)" extension.
4. Configure the payment processor with your eWay API Key and Password as
   obtained from your [eWay Account](https://go.eway.io). eWay provides 
   [step by step instructions](https://go.eway.io/s/article/How-do-I-setup-my-Live-eWAY-API-Key-and-Password)
   for generating these details.
   
### eWay Transactions Verification

eWay Transactions get verified as soon as the user is redirected back to the CiviCRM website. This is true only for Contribution pages created from CiviCRM.

If you've created a WebForm (in Drupal) to take the contributions using eWay Payment processor, make sure **eWay Transaction Verifications** Schedule job is active in CiviCRM. 

**eWay Transaction Verifications** schedule job verifies the pending transactions of eWay. This is required when CiviCRM is unable to verify the transaction right after redirection.

Visit `civicrm/admin/job` to enable **eWay Transaction Verifications** job.

### eWay Failed Transactions

Recurring contribution transactions get failed because of several reasons. In such situation extension will mark the recurring contribution as failed and will retry the transaction for **x** number of times at **y (days)** of interval.

To update the **Maximum retries** and **Retry delay (in days)** go to `civicrm/ewayrecurring/settings`. By default value of **Maximum retries** is 3 and value of **Retry delay (in days)** is 4. 


Upgrading from 1.x
------------------

The 2.0.0 version introduces use of the eWay RapidAPI 3.1, which requires a
different method of authentication with eWay from that used in the 1.x series.

You will need to Download and extract the extension as usual, and after running
the Extensions upgrades, you will need to [generate an API Key and
Password](https://go.way.io/s/article/How-do-I-setup-my-Live-eWAY-API-Key-and-Password)
as above and update these details in your Payment Processor settings.

Once your authentication details are updated, existing recurring payments will
continue to operate as usual.

Sponsorship
-----------

Initial development of this CiviCRM extension for recurring functionality and
token payments was kindly sponsored by Stephen Garrett of [Good
Reason](http://www.goodreason.com.au).

About the Authors
-----------------

This CiviCRM extension was developed by the team at
[Agileware](https://agileware.com.au).

[Agileware](https://agileware.com.au) provide a range of CiviCRM services
including:

  * CiviCRM migration
  * CiviCRM integration
  * CiviCRM extension development
  * CiviCRM support
  * CiviCRM hosting
  * CiviCRM remote training services

Support your Australian [CiviCRM](https://civicrm.org) developers, [contact
Agileware](https://agileware.com.au/contact) today!


![Agileware](logo/agileware-logo.png)
