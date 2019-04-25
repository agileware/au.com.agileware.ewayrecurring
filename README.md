eWAY Recurring Payment Processor for CiviCRM
--------------------------------------------

CiviCRM payment processor extension for [eWAY](https://eway.com.au) which
implements recurring payments using tokens. This method is essential for
automating the process setting up recurring donations and memberships in your
CiviCRM securely and reliably.

This payment processor also allows you to specify a particular day of the month
to process all recurring payments together.

You will need to have a [eWAY account](https://eway.com.au) to use this payment
processor on your CiviCRM website.

This extension previously utilised the eWAY token API which has been deprecated
by eWAY.  [eWAY Rapid API](https://www.eway.com.au/features/api-rapid-api/) is
now used with the Responsive Shared Page method, reducing the level of PCI DSS
compliance required on your site.

Installation
------------

1. Download the [latest version of this
   extension](https://github.com/agileware/au.com.agileware.ewayrecurring/archive/master.zip)
2. Extract in your CiviCRM extensions directory, as defined in "System Settings /
   Directories".
3. Go to "Administer / System Settings / Extensions" and enable the "eWAY
   Recurring Payment Processor (au.com.agileware.ewayrecurring)" extension.
4. Configure the payment processor with your eWAY API Key and Password as
   obtained from your [eWAY Account](https://go.eway.io). eWAY provides 
   [step by step instructions](https://go.eway.io/s/article/How-do-I-setup-my-Live-eWAY-API-Key-and-Password)
   for generating these details.
   
### eWAY Transactions Verification

eWAY Transactions get verified as soon as the user is redirected back to the CiviCRM website. This is true only for Contribution pages created from CiviCRM.

If you've created a WebForm (in Drupal) to take the contributions using eWAY Payment processor, make sure **eWAY Transaction Verifications** Schedule job is active in CiviCRM. 

**eWAY Transaction Verifications** schedule job verifies the pending transactions of eWAY. This is required when CiviCRM is unable to verify the transaction right after redirection.

Visit `civicrm/admin/job` to enable **eWAY Transaction Verifications** job. 


Upgrading from 1.x
------------------

The 2.0.0 version introduces use of the eWAY RapidAPI 3.1, which requires a
different method of authentication with eWAY from that used in the 1.x series.

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
