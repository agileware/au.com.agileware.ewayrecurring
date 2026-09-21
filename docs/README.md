# eWay Recurring Payment Processor (au.com.agileware.ewayrecurring)

CiviCRM payment processor extension for [eWay](https://eway.com.au) which uses
the latest [eWay Rapid API](https://www.eway.com.au/features/api-rapid-api/) and
ensures [PCI DSS compliance](https://www.eway.com.au/about-eway/technology-security/pci-dss/).

Supports both once-off and recurring payments using eWay's secure token
payment method, so card details never touch your CiviCRM server. Recurring
billing is scheduled and processed by CiviCRM itself (not by a subscription
managed on eWay's side), which gives full control over retry behaviour for
failed payments. Provides:

* Once-off and recurring payments via eWay's Shared Page / Responsive Shared Page checkout
* Secure, tokenised "pay with saved card" for repeat/recurring contributions, without re-entering card details
* Configurable automatic retries for failed recurring payments, with a system status check for contributions that have exhausted their retries
* A Search Kit action to reactivate a failed Recurring Contribution
* Background verification of pending/unconfirmed transactions
* Automatic backfill of saved card metadata (expiry date, masked number) via the eWay Rapid API
* Automated reconciliation of a contribution's Fee Amount / Net Amount against eWay's Settlement Reports API (opt-in, disabled by default)

This extension is licensed under [GPL-3.0](../LICENSE.txt).

You will need an [eWay account](https://eway.com.au) with Rapid API access to
use this payment processor on your CiviCRM website.

## Usage

Once the extension is installed and a **eWay Recurring** payment processor
is configured (see [eWay API Key and Password](#eway-api-key-and-password)),
it can be selected as the payment processor on any Contribution Page, Event,
or Membership type like any other CiviCRM payment processor, for both
once-off and recurring payments.

### Scheduled Jobs

| Job | Frequency | Default | What it does |
| --- | --- | --- | --- |
| **eWay Recurring Payments** | Always | Enabled | Processes recurring contributions that are due, and retries failed ones on schedule (see [Failed eWay Transactions](#failed-eway-transactions)) |
| **eWay Transaction Verifications** | Always | Enabled | Verifies pending/unconfirmed transactions (see [eWay Transactions Verification](#eway-transactions-verification)) |
| **eWay Recurring: fill missing tokens metadata** | Hourly | Enabled | Backfills the expiry date / masked card number on stored Payment Tokens that are missing them, via the eWay Rapid API |
| **eWay Settlement Sync** | Daily | **Disabled** | Reconciles Fee Amount / Net Amount from eWay's Settlement Reports API (see [eWay Settlement Sync](#eway-settlement-sync)) |

All jobs are configurable at `civicrm/admin/job`.

### Permissions

* **CiviContribute: view payment tokens** and **CiviContribute: edit payment
  tokens** control who can view or manage a contact's stored eWay card
  tokens.

### API

In addition to the standard `PaymentProcessor`/`Contribution`/`ContributionRecur`
APIs, this extension exposes:

* `EwayRecurring.fillTokensMeta` - manually trigger the saved-card metadata backfill described above.
* `EwayContributionTransactions.get` / `.create` / `.delete` / `.validate` - the pending-transaction verification queue; `.validate` is what the **eWay Transaction Verifications** job calls.
* `EwaySettlement.Sync` - manually trigger settlement reconciliation, optionally scoped to a single contribution (see [eWay Settlement Sync](#eway-settlement-sync)).

## Special Configuration Requirements

* An eWay Rapid API **Key and Password** are required to configure the
  payment processor - see [eWay API Key and Password](#eway-api-key-and-password).
* If your CiviCRM site is behind a proxy (Nginx, CloudFlare, etc.), the
  **Allow Beagle Alerts Customer IP Override** permission must be enabled on
  the eWay account - see [eWay Account Configuration](#eway-account-configuration).
* It is recommended to set eWay's **Redirect After Payment Processing** delay
  to 0 seconds - see [Recommended eWay Shared Page Settings](#recommended-eway-shared-page-settings).
* eWay Settlement Sync (Fee/Net Amount reconciliation) is optional and
  disabled by default - see [eWay Settlement Sync](#eway-settlement-sync) if
  you want to enable it.

## Installation

1. Download the [latest version of this
   extension](https://github.com/agileware/au.com.agileware.ewayrecurring/archive/master.zip)
2. Extract it to your CiviCRM extensions directory, as defined in "System
   Settings / Directories".
3. Go to "Administer / System Settings / Extensions" and enable the "eWay
   Recurring Payment Processor (au.com.agileware.ewayrecurring)" extension.

## Requirements

* CiviCRM - `info.xml` declares compatibility up to version 5.82; no explicit
  minimum version is declared.
* PHP - no explicit minimum version is declared in `composer.json`.
* [eway/eway-rapid-php](https://packagist.org/packages/eway/eway-rapid-php)
  `^2.0`, installed automatically as a dependency.
* An eWay account with Rapid API access.

## Upgrade instructions

If you are changing from a different eWay Payment Processor or upgrading from eWay Recurring 1.x, please read the [Upgrade Instructions](UPGRADE.md)

## eWay API Key and Password

Configure the payment processor with the required eWay API Key and Password as
obtained from the [eWay Account](https://go.eway.io).
eWay provides [step by step instructions](https://go.eway.io/s/article/How-do-I-setup-my-Live-eWAY-API-Key-and-Password)
for generating these details.

## eWay Account Configuration

It is recommended to set the following options in the eWay account.

Log into **MYeWAY** and go to **My Account tab** > **User Security** > **Manage Roles**.
Click on the **Role** to get to the **Role Permissions**.

Enable the **Allow Beagle Alerts Customer IP Override** permission for the role assigned to the API account.
This is required for any CiviCRM site which is operating behind a proxy server such as Nginx, CloudFlare etc.

This can be indicated by eWay error response with text: _Function Not Permitted to Terminal_

![Allow Beagle Alerts Customer IP Override](img/eway-customer-ip-override.png)

## Recommended eWay Shared Page Settings

By default, when a credit card payment is processed by eWay, the transaction is not confirmed in CiviCRM until either:

1. the customer waits for the **default 5 seconds** before being returned to the website or 
2. the customer clicks the **Finalise Transaction** button.

It is often the case that neither of these events occur which results in the CiviCRM **Contribution** record being created with a **Status** of _Pending (Incomplete Transaction)_.

The responsibility of marking these Contributions as _Completed_ then becomes a **manual process** of reconciling the eWay payment with the Contribution record, which is not ideal.

To avoid this situation, it is recommended to change the **Redirect After Payment Processing** delay default from 5 seconds to **0 seconds**. Thereby reducing the likelihood of the transaction not being confirmed in CiviCRM and thus ensuring that the Contribution **Status** is set to _Completed_.

To change the **Redirect After Payment Processing** option:

1. Login to MYeWAY.
2. Hover the mouse over the Settings tab then click on Shared Page.
3. Locate the **Redirect After Payment Processing** option.
4. Change the option to **0 seconds**.

For more details see, [https://go.eway.io/s/article/Can-I-customize-the-eWAY-hosted-Payment-page?language=en_US](https://go.eway.io/s/article/Can-I-customize-the-eWAY-hosted-Payment-page?language=en_US)

![Redirect After Payment Processing](img/eway-shared-page-settings.png)

Recommended setting for **Redirect After Payment Processing** is **0 seconds**.

![Redirect After Payment Processing](img/eway-shared-page-redirect-after-payment-delay.png)

## eWay Transactions Verification

The **eWay Transaction Verifications** job verifies the pending transactions in
eway. This is required for when CiviCRM is unable to verify the transaction
immediately, for example if the end user does not press the *Return to Merchant*
button or if the contribution was made via a Drupal Webform.

This job is enabled by default; visit `civicrm/admin/job` if you need to
review or adjust it.

## Failed eWay Transactions

Recurring contribution transactions could fail for one of several reasons; in
these situations, the extension will mark the recurring contribution as failed
and retry the transaction at an interval up to a maximum number of times, both
of which can be configured.

To update the **Maximum retries** and **Retry delay (in days)** go to
`civicrm/ewayrecurring/settings`. The default **Maximum retries** is 3
and **Retry delay** is 4 days.

You will be notified if there are any failed contributions that are no longer being retried via a
CiviCRM system status check.

## Reactivating Failed Contributions

This processor includes a Search kit action to Reactivate a failed Recurring Contribution.
The action is not currently available to the built-in Recurring Contribution list on the contact
card, however may be added to any Search kit on Recurring Contributions, for example the included
"Failed Recurring Contributions" listing.

This action will prompt you for an optional date to reset the next scheduled date to process the
recurring contribution, and then:

- Set the Number of Failures to 0,
- Clear the Retry Failed Attempt Date,
- Update the Next Scheduled Contribution Date as requested, and
- Reset the Recurring Contribution's status to in progress.

The contribution will then be processed as soon as the Next Scheduled Contribution Date has passed,
*which will most likely be the next processing run* if you do not explicitly set it to a future
date when prompted.

Using this action on Recurring Contributions with other payment processors is possible and will
update the recurring contribution status, however the behaviour depends on the payment processor
setup and a majority do not take the status in CiviCRM into account due to the payment being
processed automatically on the gateway.

## eWay Settlement Sync

The **eWay Settlement Sync** job reconciles the **Fee Amount** and **Net
Amount** on Completed contributions using eWay's [Settlement Reports
API](https://go.eway.io/s/article/Settlement-Reports-API-Snippets?language=en_US),
which returns daily summaries of settled transactions and the fees eWay
deducted from each one. This automates fee/net amount reconciliation that
would otherwise have to be done manually by cross-checking the MYeWAY portal.

By default this feature is **disabled** and does nothing. To enable it:

1. Go to `civicrm/ewayrecurring/settings` and select which **live** eWay
   payment processors the sync should cover, under **Settlement Sync:
   Payment Processors**. Leaving this empty disables the sync entirely -
   test/sandbox processors are never covered, as eWay's sandbox does not
   return settlement data.
2. Optionally adjust **Settlement Sync: Window (days)** (default: 10, valid
   range 1-90). This controls both how far back a Completed contribution is
   still treated as awaiting reconciliation, and how many days of eWay
   settlement reports are queried on each run. A contribution older than
   this window is left for standard manual reconciliation.
3. Visit `civicrm/admin/job` and enable the **eWay Settlement Sync** job
   (runs Daily by default).

The sync only ever updates a contribution's Fee Amount / Net Amount while
they are still unset (Fee Amount = 0), so it will not overwrite a fee that
has already been reconciled by some other means. You can also trigger a
sync for a single contribution manually via the API (for example from the
CiviCRM API Explorer, or `cv api3 EwaySettlement.Sync contribution_id=123`).

## Recurring Contribution Form Behaviour

This extension changes some standard CiviCRM recurring-contribution form
behaviour for the eWay Recurring processor, via CiviCRM's own extension
hooks (not by overriding template files):

1. **Cancel Subscription** - the "send cancellation request" option is
   hidden, since eWay Recurring schedules and processes all recurring
   billing locally in CiviCRM rather than via a subscription managed on
   eWay's side. Cancelling in CiviCRM stops future scheduled charges; it
   does not delete or otherwise notify eWay about the saved card token.
2. **Update Subscription** - the Amount, Number of Installments, Interval,
   Interval Unit, Next Scheduled Contribution Date, and End Date fields are
   all editable.

# About the Authors

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
