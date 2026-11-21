# Planned features: eWay Recurring Payment Processor

| | |
| --- | --- |
| Extension version | 2.8.2 (work in progress on branch `release-3.0.0`) |
| Status last updated | 2026-11-15 |
| Owner | Extension maintainers |
| Related | [TEST_PLAN.md](TEST_PLAN.md) (test coverage and status), [TESTING.md](TESTING.md) (how to run tests), [LOCAL_DEV.md](LOCAL_DEV.md) (local environment) |

## 0. About this document

This file tracks features that are planned for the extension but not yet built: what each one is
for, what it must do, the design options, the decisions still open, and how it will be tested. It
is a record for the maintainers and for other developers who pick the work up. It is not user
documentation (that lives in `docs/`).

### Reading the status

| Status | Meaning |
| --- | --- |
| `idea` | Written down, not yet agreed |
| `planned` | Agreed as worth doing, design decisions still open |
| `designed` | Design decisions settled, ready to build |
| `in progress` | Being built, in an open pull request or on a release branch |
| `released` | In a tagged release (name the version) |
| `dropped` | Decided against. Keep the entry and say why |

### Maintaining this file

1. **IDs are permanent.** Features are numbered `FR-1`, `FR-2` and so on. Never renumber or reuse one.
   To drop a feature set it to `dropped` and say why.
2. **Keep the summary table and the feature's section in step.** Change the status in the same pull
   request that moves the work forward.
3. **Settle decisions here.** When an open decision is resolved, record the answer and the date in
   the feature's decision table rather than deleting the question.
4. **Link the tests.** When a feature is designed, add its cases to [TEST_PLAN.md](TEST_PLAN.md) and
   list the group names in the feature's "Test coverage" part. Do not mark a feature `released`
   until those cases are `done`.
5. **Keep private details out.** Do not name customers, customer sites or their staff, and do not
   record credentials, keys or other private configuration. Describe how sites behave in general
   terms.
6. Add a line to the change log (section 2) for every change to this file.

## 1. Summary

| ID | Feature | Status | Target release | Test plan |
| --- | --- | --- | --- | --- |
| [FR-1](#fr-1-donor-facing-update-my-stored-card-page) | Donor-facing "update my stored card" page | `planned` | to be decided | Group to be added once decisions D1 to D5 are settled; supporting cases listed below |
| [FR-2](#fr-2-upgrade-step-repair-recurring-contributions-left-stuck-by-the-old-retry-behaviour) | Upgrade step: repair recurring contributions left stuck by the old retry behaviour | `in progress` | 3.0.0 | PU-L6 to PU-L10, UP-10, UP-11 |
| [FR-3](#fr-3-check-a-new-card-with-the-issuer-when-it-is-added) | Check a new card with the issuer when it is added | `idea` | to be decided | Group to be added once decisions C1 to C6 are settled; PW-X-05 is the first step |
| [FR-4](#fr-4-let-finance-staff-open-the-failed-recurring-contributions-list) | Let finance staff open the Failed Recurring Contributions list | `in progress` | 3.0.0 | PU-O6 to PU-O10, PW-B-failed-01, PW-B-failed-13 |
| [FR-5](#fr-5-search-kit-report-of-payments-eway-approved-but-civicrm-could-not-complete) | Search Kit report of payments eWAY approved but CiviCRM could not complete, with actions and instructions | `idea` | to be decided | Group to be added once decisions A1 to A5 are settled |
| [FR-6](#fr-6-blocked-recurring-contributions-list-with-reactivate-and-cancel) | Blocked Recurring Contributions list with Reactivate and Cancel, and a status check | `in progress` | 3.0.0 | PU-P1 to PU-P5, PW-B-blocked-01 to PW-B-blocked-04 |

---

## FR-1 Donor-facing "update my stored card" page

| | |
| --- | --- |
| Status | `planned` |
| Raised | 2026-11-14 |
| Target release | To be decided (after 3.0.0) |

### Summary

Give donors a page on the website where they can replace the credit card stored for their recurring
contributions, without staff involvement and without entering card details anywhere but on eWAY's
hosted page. It should look and behave like the rest of the site, not like a CiviCRM back-office
screen.

### Why

Today there is no donor-facing way to do this, either in this extension or in CiviCRM core.

- CiviCRM's "Update Billing Details" form (`civicrm/contribute/updatebilling`) is a back-office form.
  The extension adds its saved-card controls to it, but the card list is loaded with an API call that
  needs staff-level permission, so in practice it is a staff form (test case PW-F4-07 confirms this).
- Stored cards expire. When one does, every recurring payment on it starts failing. Donors need an
  easy way to fix it, and the reminder email (see the separate `expiredcreditcards` extension) needs
  a page to send them to.
- Without a built-in page, each website has to build its own, outside this extension, and depend on
  the extension's internals behaving a particular way.

### History

- **2019, ticket CIVIEWAY-36 (release 2.1.2).** The first attempt reused core's Update Billing form
  and sent the donor to eWAY's hosted page and back to a return page in the extension. In use it was
  unreliable, confusing, and unstyled for the website: a back-office feature put in front of donors.
- **2019, tickets CIVIEWAY-126 and CIVIEWAY-130 (release 2.2.5).** Update billing was changed to the
  saved-card flow used by the back-office forms. The return page from the first attempt became
  unreachable from then on, and is deleted on the 3.0.0 branch (defect DEF-13 in the test plan).
- **Since then.** Websites that wanted donor self-service built their own update-card page outside
  this extension. That works, but every site repeats the effort, and the extension has no test
  coverage of what such a page relies on. The first cases for that are in the test plan (PU-F25,
  PU-F26, PU-G11, PW-B-cron-08).

### Requirements

**Must have**

1. A public route that a donor can open from a link in an email. It identifies the contact by a
   CiviCRM checksum (contact id and checksum) or by a logged-in session. No staff permission is needed.
2. The page lists only the contact's own saved cards for the eWAY processor (masked number and
   expiry), and lets the donor choose which one to replace.
3. Card details are entered only on eWAY's hosted page. No card data passes through the website.
4. Everything the donor can influence is checked on the server, not just in the browser: the
   checksum on every step including the return from eWAY, that the chosen card belongs to that
   contact and to the right processor, and that the eWAY access code belongs to this donor's visit.
   (Defect DEF-14 was exactly this gap on the staff form.)
5. On success the stored card is replaced, its expiry and masked number are refreshed, any Failed or
   Failing recurring contributions that use it are recovered so the cron charges them again, an
   activity is recorded on the contact, and the donor sees a confirmation.
6. On failure the donor sees a clear message with eWAY's reason, nothing is changed, and they can try
   again.
7. It works on WordPress, Drupal, Joomla and Standalone, takes its look from the site's theme, works
   on a phone, and meets WCAG 2.1 AA for the controls it adds.

**Should have**

8. A way to put the page's address, with a valid checksum for the contact, into a message template
   (a token), so reminder emails can link straight to it.
9. A setting to enable or disable the page, and a setting or template override for the
   confirmation text and where the donor goes afterwards.
10. Protection against abuse: a limit on attempts per contact and address, and a hook where a site
    can add a bot check.
11. All text translatable through the extension's usual `E::ts()` calls.

**Could have**

12. Let the donor add a card without replacing one, and choose which recurring contributions use it.
13. Require a successful card authorisation before the stored card is replaced (see decision D4).

**Out of scope for FR-1**

Changing the amount or schedule, cancelling, editing the billing address on its own, managing
cards for other payment processors, and any change to the staff Update Billing form.

### Building blocks that already exist

| Piece | Where | Use for FR-1 |
| --- | --- | --- |
| Create customer through eWAY's hosted page, and save the returned token | `CRM_eWAYRecurring_PaymentToken::createToken()` and `saveToken()` (staff routes `createtoken` and `savetoken`) | The same eWAY flow, behind a new donor route. Do not loosen the permissions on the existing staff routes |
| Token lookup, expiry and masked number refresh | `PaymentToken::fillTokensMeta()` and the "fill missing tokens metadata" job | Refresh after the card is replaced |
| Recovering a Failed recurring contribution when a card is updated | `updateSubscriptionBillingInfo()` (DEF-13) and the Reactivate task | Reuse the logic, with the schedule choice from decision D3 |
| Checks on which saved card may be chosen | `getChosenToken()` (DEF-14) | Same rules, applied to the donor route |
| Expiry reminders | The separate `expiredcreditcards` extension | Supplies the reminder; FR-1 supplies the page it links to |

### Design options

| Option | Description | For | Against |
| --- | --- | --- | --- |
| A. New page in this extension (proposed) | A public route, for example `civicrm/ewayrecurring/updatecard`, rendered by the extension with minimal theme-neutral markup and a small script, using the existing eWAY hosted-page flow | Works on all four CMSs with nothing else installed; one place for the security checks; testable here | The extension owns a donor-facing screen and its markup |
| B. Afform based | Ship an Afform (CiviCRM's form builder) plus an extension action that replaces the card | Sites can restyle it in the Afform editor | Needs Afform and a front-end route on every CMS; harder to keep the security checks in one place |
| C. Document the contract only | Keep sites building their own pages; document and test what they rely on | Least work | Does not deliver the feature; every site repeats the work and the security checks |

Proposed: **A**, to be confirmed under decision D1.

### Decisions still open

| # | Status | Question | Notes |
| --- | --- | --- | --- |
| D1 | Open | Which design option (A, B or C), and which authentication: checksum link only, logged-in session only, or both? | Option A with both is proposed |
| D2 | Open | Replace the stored card in place, or create a new card and re-point the recurring contributions? | In place keeps every link, reminder and recurring record pointing at the same row. A new card keeps a history of old ones but leaves stale cards that can trigger "card expired" reminders |
| D3 | Decided | After recovering a Failed recurring contribution, what happens to its next scheduled date: leave it (the cron charges the missed date straight away), move it into the future (the missed payment is skipped), or restart it from today? | 2026-11-15 (owner): **leave it, and make the date mean "the payment that is owed".** A failure no longer moves `next_sched_contribution_date` at all (DEF-46, DEF-58). It moves only after a successful charge, first attempt or retry, from the original due date by one interval and then on to the first date after today. So recovery (the staff form, Reactivate, or a site's own update-card page that does not set a date) needs no schedule choice: the next cron run charges the record exactly once, whatever it missed, and the date then moves to the first future date; periods missed while it sat Failed are not charged as arrears. A site page that does set its own date still wins. Tested end to end by PU-F33 and PU-F34 |
| D4 | Open | Is eWAY accepting the card enough, or must a small authorisation succeed first? | The extension makes no authorisation call when it saves a card today. Check what eWAY itself verifies when it creates a token (sandbox case PW-X-05) |
| D5 | Open | Does the page only replace a card, or also let the donor add one and pick the recurring contributions that use it? | Affects the amount of work and the tests |
| D6 | Open | Is a new CiviCRM permission needed for the donor route, or is a valid checksum or login enough? | |
| D7 | Open | Where does the "link with checksum" token live: this extension, the `expiredcreditcards` extension, or both? | |
| D8 | Open | Minimum CiviCRM version for the feature | The extension declares 6.16 today |
| D9 | Open | Target release number | |

### Acceptance criteria

The feature is `released` when all of these hold on WordPress, Drupal, Joomla and Standalone:

- [ ] A donor opens the emailed link, picks a card, enters a new card on eWAY's page, and is back on
      the site with a confirmation.
- [ ] Their stored card now has the new card's masked number and expiry, and every Failed or Failing
      recurring contribution that used it is back to In Progress and is charged on the next cron run.
- [ ] A declined card, a cancelled visit and an eWAY error each show a clear message and change nothing.
- [ ] A bad, missing or another contact's checksum, a tampered card id, a replayed return URL and an
      expired link are all refused with no PHP error and no change to any record.
- [ ] The page uses the site's theme, works at 375 px wide, and passes an automated accessibility check.
- [ ] No card data appears in the site's database, logs, page source or network traffic to the site.
- [ ] The cases listed under "Test coverage" are `done` in [TEST_PLAN.md](TEST_PLAN.md).
- [ ] User documentation in `docs/` describes the page, its setting and its token.

### Test coverage

Existing cases in [TEST_PLAN.md](TEST_PLAN.md) that support this feature:

| ID | What it covers |
| --- | --- |
| PU-F25, PU-F26 | The cron charging a card that was replaced in place, and a reset Failed record being charged at the right time |
| PU-G11 | The metadata job refilling a replaced card's expiry and masked number |
| PW-B-cron-08 | The same, end to end through the stub |
| PU-N5 to PU-N8 | Recovery and the card checks on the staff form, whose logic the donor route will share |
| PW-S-05 | Tampered card id (to be repeated for the donor route) |
| PW-F4-07 | Whether a user without staff permission can use the staff form (expected: no) |
| PW-X-05 | What eWAY verifies when a card is added (informs D4) |

New cases will be added to the test plan once decisions D1 to D5 are settled. The expected groups are:

- **PHPUnit:** checksum and session checks on every step, card ownership and processor checks,
  replacing a card, recovery of Failed records, failure and retry paths, rate limiting.
- **Playwright, a new front-end group (PW-F7):** the full donor journey on each CMS, decline and
  cancel, mobile width, a tampered request, an expired or reused link, and the page in each CMS's theme.
- **Security group (PW-S):** the page's output is escaped, the checksum cannot be bypassed, and no
  card data is stored or logged.

---

## FR-2 Upgrade step: repair recurring contributions left stuck by the old retry behaviour

| | |
| --- | --- |
| Status | `in progress` |
| Raised | 2026-11-14 |
| Target release | 3.0.0 |

### Summary

When 3.0.0 is installed over an earlier release, find the recurring contributions that the old retry
behaviour left stuck and mark them Failed, by two rules:

1. Records that used up their retries are marked Failed, so they appear in the Failed Recurring
   Contributions list and the System Status check, and so anything that recovers Failed records
   (Reactivate, or a site's own update-card page) can recover them.
2. Records that have a retry date but no failures are also marked Failed (decision U4), so they are
   visible and recoverable too instead of silently stuck.

### Why

**Rule 1: exhausted records.** Up to 2.8.2, when a recurring contribution used up its retries it kept
the status In Progress, with `failure_count` at the retry limit and a retry date set. Neither cron list
selects such a record: the normal list only takes records with no retry date, and the retry list only
takes records below the limit. The Failed Recurring list, the System Status check and the Reactivate
action all look for the status Failed, so they never see it. The record stays stuck, and the donor is
never charged again, with nothing on screen to say so.

The fix for this (DEF-3 in the test plan) marks a record Failed once its retries are used up, but only
from then on. It does not touch records that were already stuck when the site upgraded. Without a data
step, those donors stay stuck after the upgrade, including on sites whose own update-card page resets
Failed records, because that reset finds nothing to reset.

**Rule 2: a retry date with no failures.** An In Progress record with a retry date and
`failure_count` 0 is stuck the other way round: the normal list needs no retry date, and the retry list
needs at least one failure. It was left by the old update-billing page in releases 2.1.2 to 2.2.4,
which reset the count but not the date, or by a hand edit that did the same. No current code produces
it, so there are probably few, but nothing ever picks them up.

### Behaviour

Built on `release-3.0.0` (PU-L6 to PU-L10 are done and passing in CI; UP-10 and UP-11 are in `tests/upgrade/` and pass on GitHub).

- A new revision, `upgrade_30000`, in `CRM_eWAYRecurring_Upgrader`. It calls
  `markStuckRecurringContributionsFailed()`, which does the work and returns the ids changed.
- Both rules apply only to recurring contributions that use an eWAY recurring payment processor, have
  the status In Progress (not Pending), and are not test-mode records. Both have a retry date.
- **Rule 1** selects those with `failure_count` above 0 and at or above the retry limit saved in the
  setting `eway_recurring_contribution_max_retry` when the step runs. It sets their status to Failed
  and changes nothing else: the failure count, retry date and next scheduled date stay as they were.
- **Rule 2** selects those with `failure_count` 0. It sets their status to Failed and changes nothing
  else: the failure count stays 0, and the retry date and next scheduled date stay as they were.
  Nobody is charged.
- Each rule is applied with a single SQL statement (decision U1), so no PHP hooks or rules fire.
  Detailed logging, if the site has it enabled, still records the changes, because it works through
  database triggers (without a user id).
- The two rules never select the same record (one needs failures, the other none), and a second run
  finds nothing to change.
- The step writes the number of records changed by each rule, and their ids, to the CiviCRM log, and
  shows the same message in the upgrade output.
- It sends no email and creates no activity.

After the upgrade the System Status check starts reporting the records from both rules, because each
one's retry date is already more than four hours old. That is intended: it is the first time anyone sees them.

### Risks

- **A Failed record with no failures.** Rule 2 records show as Failed with a failure count of 0, which
  looks odd. That is expected: the status is how they become visible and recoverable, and Reactivate
  or a donor's card update sets the count to 0 anyway.
- **Hooks and rules.** Changing the status through the API would fire the usual hooks, and CiviRules or similar tools may have rules on recurring contribution changes, so a bulk change could send many emails. Decision U1 is to update with SQL, which fires no PHP hooks or rules. The cost is that nothing reacts to the change either: a site that relies on a rule to follow up a Failed recurring contribution will not see one for these records.
- **A sudden count.** A site with many stuck records will see a large number in System Status and
  in the Failed list on the day it upgrades. Release notes should say so.
- **Recovery is not automatic.** Marking a record Failed does not charge it. Staff (Reactivate) or a
  site's own update-card page still have to recover it. What happens to its schedule date on recovery
  is decision D3 in FR-1.

### Checking a site by hand

Before upgrading, these list the records each rule would change. Replace the placeholders with the
eWAY processor's id and the saved retry limit.

Rule 1 (to be marked Failed):

```sql
SELECT cr.id, cr.contact_id, cr.failure_count, cr.failure_retry_date
FROM civicrm_contribution_recur cr
WHERE cr.payment_processor_id = <processor id>
  AND cr.failure_count > 0
  AND cr.failure_count >= <retry limit>
  AND cr.failure_retry_date IS NOT NULL
  AND cr.is_test = 0
  AND cr.contribution_status_id = (
    SELECT ov.value FROM civicrm_option_value ov
    JOIN civicrm_option_group og ON og.id = ov.option_group_id
    WHERE og.name = 'contribution_recur_status' AND ov.name = 'In Progress');
```

Rule 2 (to be marked Failed):

```sql
SELECT cr.id, cr.contact_id, cr.next_sched_contribution_date, cr.failure_retry_date
FROM civicrm_contribution_recur cr
WHERE cr.payment_processor_id = <processor id>
  AND cr.failure_count = 0
  AND cr.failure_retry_date IS NOT NULL
  AND cr.is_test = 0
  AND cr.contribution_status_id = (
    SELECT ov.value FROM civicrm_option_value ov
    JOIN civicrm_option_group og ON og.id = ov.option_group_id
    WHERE og.name = 'contribution_recur_status' AND ov.name = 'In Progress');
```

### Decisions still open

| # | Status | Question | Notes |
| --- | --- | --- | --- |
| U1 | Decided | Apply the change through the API (hooks and rules fire, logging records it) or with direct SQL (silent)? | 2026-11-14: **direct SQL**, one statement. Avoids a burst of emails or rule runs on the day of the upgrade. No PHP hooks or rules fire and no activity is created. Database logging triggers still record the change if logging is enabled. The step logs the ids it changed |
| U2 | Decided | Include test-mode records? | 2026-11-14: **no**, leave them alone. The System Status check ignores them too |
| U3 | Decided | Include records still Pending? | 2026-11-14: **no**, leave them alone. The retry list only selects In Progress, so a Pending record that failed is stuck in the same way, but such records are rare (the cron only charges a recurring contribution that already has a Completed contribution) |
| U4 | Decided | Also repair the other stuck state: In Progress, retry date set, `failure_count` 0? | 2026-11-14: **yes, mark these Failed too (Rule 2), option C.** Nobody is charged by the upgrade, and the records become visible in the Failed list and System Status so staff or a donor's card update can recover them. Clearing the retry date instead (option B) would have charged the donor on the next cron run; it was chosen first and then changed |
| U5 | Decided | Offer a preview (an API action that lists what would change) so staff can check before upgrading? | 2026-11-14: **no new preview feature for 3.0.0.** Staff check before upgrading with the two SQL queries above (the only preview that works before the new code is installed), and the step itself logs the number of records changed by each rule and their ids, and shows the numbers in the upgrade output. A read-only API action or a saved search for ongoing monitoring can be added later if it is wanted |
| U6 | Decided | Use the retry limit saved at upgrade time, or treat any record with failures and a retry date as exhausted? | 2026-11-14: **the saved limit at the time the step runs**. A site that lowered the limit before upgrading will see more records marked |

### Acceptance criteria

- [ ] An upgrade from 2.8.1 marks every exhausted record Failed and nothing else (UP-10), and does the
      same for every record with a retry date and no failures (UP-11).
- [ ] Records that must not change are unchanged: below the limit, no retry date, other statuses,
      other processors, test-mode records and Pending records (PU-L7, PU-L10).
- [ ] Running the step twice changes nothing more (PU-L8).
- [ ] A Rule 2 record keeps its failure count of 0, its retry date and its schedule date, and the step
      charges nobody (PU-L9).
- [ ] No email is sent and no activity is created by the step (PU-L8).
- [ ] The count appears in the upgrade output and the log.
- [ ] The release notes describe the new System Status message and the Failed list entries, and
      include the two SQL checks so staff can see what will change before they upgrade (decision U5).

### Test coverage

| ID | What it covers |
| --- | --- |
| PU-L6 | Rule 1: the step marks exhausted In Progress records Failed and leaves their other fields alone |
| PU-L7 | Records that Rule 1 must not change |
| PU-L8 | Idempotent, uses the saved limit, reports the count, no mail or activities |
| PU-L9 | Rule 2: a record with a retry date and no failures is marked Failed and keeps its other fields |
| PU-L10 | Records that Rule 2 must not change |
| UP-10, UP-11 | Rules 1 and 2 through a real upgrade from the baseline release |
| PU-F14, PU-F15 | The DEF-3 behaviour for records that become exhausted after the upgrade |

---

## FR-3 Check a new card with the issuer when it is added

| | |
| --- | --- |
| Status | `idea` |
| Raised | 2026-11-14 |
| Target release | To be decided (after 3.0.0) |
| Related | DEF-33 in [TEST_PLAN.md](TEST_PLAN.md) (recorded there as not a defect, this feature is the answer), decision D4 of FR-1 |

### Summary

When a card is added to a recurring contribution (the staff Update Billing form today, and the donor page
if FR-1 is built), ask the card issuer whether the card can be used, so a bad card is found while the
person is still there to fix it.

### Why

Today the extension saves the card as an eWAY token and makes no authorisation call. Nothing asks the
bank whether the card is live or can take payments. A mistyped, cancelled or blocked card is first found
at the next scheduled charge. That charge fails, the retry logic runs, the record is marked Failed once
the retries are used up, and staff have to notice and reactivate it. A card added in good faith can go
a whole billing period before anyone knows it does not work. The extension has never checked a card when
it is added, so this is a missing feature rather than a defect (recorded as DEF-33, reclassified).

### What eWAY's documentation says (read 2026-11-14, first pass)

Read from the [Rapid API reference](https://eway.io/api-v3/) and search summaries of eWAY's help
articles. The reading was incomplete, see the last point.

- There is no dedicated "verify this card" request.
- There is a pre-authorisation. Setting the request's `Method` to `Authorise` "will authorise and hold an
  amount on the customer's card without charging it". The hold can later be captured or voided.
- A help article (not readable in full) says an authorisation can be run against a saved token by passing
  the token id with `Method` set to `Authorise`, and that pre-authorisation is a way to check card details
  before taking payment.
- Creating a token (`CreateTokenCustomer`) requires `TotalAmount` of 0 and processes no payment. The
  pages read do not say whether the issuer is contacted at that point.
- The response's `Verification` object (CVN, address, email and so on) is documented as unused. No
  response field or code was found that reports an issuer check on token creation.
- Query Customer returns the masked card number and expiry from eWAY's store. It does not appear to ask
  the issuer. An `IsActive` field is mentioned but its meaning is unconfirmed.
- Not read: the last part of the API reference (probably the response and error code tables), the
  full text of the help articles, and anything on whether Authorise works on each connection method
  (Responsive Shared Page, Transparent Redirect, Direct) or accepts a zero or one dollar amount.

### Design options

| Option | What it does | Cost and risk |
| --- | --- | --- |
| A. Authorise and void | After the token is created, send an `Authorise` for a small amount against it, then void it. A decline is shown to the person at once and the card is not saved. | May count as a billable eWAY transaction (to be checked). The donor may see a brief hold. Some cards may decline a small authorisation yet be valid. Adds a new way for adding a card to fail |
| B. Rely on eWAY | If the sandbox shows eWAY already checks the card when the token is created, do nothing and document it. | No code. Needs the sandbox result (PW-X-05) to be sure |
| C. Warn only | Run the authorisation but treat a decline as a warning, save the card anyway. | Less disruptive, but the problem card is still saved |

### Decisions still open

| # | Status | Question | Notes |
| --- | --- | --- | --- |
| C1 | Open | Is a check wanted at all, or is finding a bad card at the first charge acceptable? | |
| C2 | Open | Which option: A, B or C? | Needs the sandbox result first |
| C3 | Open | What amount, and is it voided straight away? | Check eWAY's fee for an authorisation and for a void |
| C4 | Open | Does it apply to the staff Update Billing form, the donor page (FR-1), or both? | FR-1 decision D4 is the same question for the donor page |
| C5 | Open | What does the person see on a decline, and is the eWAY response message shown or a general message? | Messages are translated from eWAY codes elsewhere in the extension |
| C6 | Open | Does Authorise work on the connection methods this extension uses, and is a zero or one dollar amount accepted? | Finish reading the API reference and the help articles, then confirm in the sandbox |

### First steps (when picked up)

1. Finish reading the API reference (response and error codes) and the help articles, and check eWAY's fee
   for an authorisation and a void.
2. Write the sandbox case PW-X-05: add a card eWAY's test cards mark as declined, and record what eWAY
   says at token creation and at the first charge. This decides option B.
3. Settle C1 to C6 here, then add the test cases to [TEST_PLAN.md](TEST_PLAN.md).

### Research notes (so the work can be picked up)

Research done on 2026-11-14 from the web only. Nothing was tried against the eWAY sandbox, and no
eWAY account or key was used. Treat every point below as "the documentation says", not "eWAY does".

#### How the extension adds a card today (read from the code)

- `js/eway.js` calls the front-context route `civicrm/ewayrecurring/createtoken`, handled by
  `CRM_eWAYRecurring_PaymentToken::createToken()`.
- `createToken()` calls the SDK's `createCustomer(ApiMethod::RESPONSIVE_SHARED, ...)` with the contact's
  billing details and `CustomerReadOnly`. eWAY returns a `SharedPaymentUrl`, the person enters the card on
  eWAY's hosted page, and eWAY redirects to `civicrm/ewayrecurring/savetoken` with an `AccessCode`.
- `PaymentToken::saveToken()` asks eWAY for the result (`queryTransaction` on the access code), reads the
  customer token and stores it as a CiviCRM `PaymentToken`.
- No payment or authorisation request is made anywhere in that flow. The bundled SDK does support the
  method: `vendor/eway/eway-rapid-php/src/Rapid/Enum/PaymentMethod.php` has `AUTHORISE = 'Authorise'`
  (next to `PROCESS_PAYMENT`, `CREATE_TOKEN_CUSTOMER`, `UPDATE_TOKEN_CUSTOMER` and `TOKEN_PAYMENT`), and
  its language file has the error `V6142 = 'Authorise not available for Bank/Branch'`.
- The Update Billing form (`updateSubscriptionBillingInfo()`) only picks an existing token, so a card
  added in the same session goes through the flow above.

#### Sources read, and how well

| Source | What it gave | How well it was read |
| --- | --- | --- |
| [Eway Rapid API Reference](https://eway.io/api-v3/) (the same page is also served at [eway.com.au/api-v3](https://www.eway.com.au/api-v3/)) | The `Method` field and its `Authorise` value; the `TotalAmount` zero rule for `CreateTokenCustomer`; the unused `Verification` object; `BeagleVerification`; Query Customer | The page is about 456,000 characters. Read verbatim: characters 0 to 39,968, 139,968 to 179,936 and 279,936 to 319,904. Characters 39,968 to 139,968 and 179,936 to 279,936 were seen only as a model-made summary. Characters 419,904 to the end were never read, and probably hold the response and error code tables |
| [How does PreAuth work?](https://go.eway.io/s/article/How-does-PreAuth-work?language=en_US) | Seen in search results only: pre-auth is set with `Method` = `Authorise` and has three stages (authorise, capture, void); described as useful to check card details before taking payment | Not read. The page loads its text with JavaScript and a plain fetch returned only a "Loading" shell |
| [Can you do preauth on a token payment?](https://go.eway.io/s/article/Can-you-do-preauth-on-a-token-payment?language=en_US) | Seen in search results only: pass the token id and `Method` = `Authorise`, then capture with the standard pre-auth capture request | Not read, same reason |
| [Preauth - API Snippets](https://go.eway.io/s/article/Preauth-API-Snippets?language=en_US) | Not seen beyond its title | Not read, same reason |
| [What is considered a transaction?](https://go.eway.io/s/article/What-is-considered-a-transaction?language=en_US) | Not seen beyond its title. Wanted for the fee question (does an authorisation or a void count as a billable transaction?) | Not read |
| [Developers Documentation, preauth](https://eway.io/sg/developers/api/preauth/) | Title only (the Singapore site) | Not read |
| [How to Enable and Test 3D Secure 2.0 in the Eway Sandbox](https://go.eway.io/s/article/How-to-Enable-and-Test-3DS-2-0-in-the-Eway-Sandbox?language=en_US) | Title only. 3DS 2.0 is a cardholder authentication step in the checkout and is a different thing from an issuer check on a saved card | Not read |
| [Active Merchant eWAY Rapid gateway source](https://codeclimate.com/github/Shopify/active_merchant/lib/active_merchant/billing/gateways/eway_rapid.rb/source) | Third party. Shows `Method` = `Authorise` for an authorisation and `UpdateTokenCustomer` for a token update, so the two are separate calls in practice | Only seen as a search snippet |
| [Chargebee's eWAY page](https://www.chargebee.com/docs/payments/1.0/payment-gateways-and-configuration/eWay) | Third party. Says payments use a permanent token held in eWAY's vault | Only seen as a search snippet |

#### Findings, with how sure they are

1. **`Authorise` exists.** Quoted from the API reference: "This transaction type will authorise and hold an
   amount on the customer's card without charging it." The value goes in `Method`; `TransactionType`
   stays `Purchase`, `MOTO` or `Recurring`. The SDKs set it when `Capture` is false. Sure.
2. **`CreateTokenCustomer` takes no payment.** `TotalAmount` "must be 0" for `CreateTokenCustomer` and
   `UpdateTokenCustomer` (seen in a summary of the Direct Connection payment fields). Fairly sure.
3. **No issuer check is described on token creation.** The pages read neither say the card is checked
   with the issuer nor say it is only format checked. This is absence of evidence. The sandbox decides it.
4. **No response field reports an issuer check.** `Verification` (CVN, Address, Email, Mobile, Phone) is
   documented as "currently unused". `BeagleVerification` (Email, Phone; 0 not verified, 1 attempted,
   2 verified, 3 failed) is Fraud Verify identity checking, and only on the Responsive Shared Page. The
   error codes were not read, so a token-creation decline code may exist. Sure about what was read.
5. **Query Customer reads stored data.** It returns the masked card and expiry for a token. Nothing says
   it contacts the issuer. An `IsActive` flag appears in one place only and two readings disagreed, so its
   meaning is unknown.
6. **Authorise on a saved token.** Only from search summaries of the help article. Needs the article read.
7. **Not found anywhere:** a zero or one dollar `Authorise`, or any statement that `Authorise` works on the
   Responsive Shared Page, Transparent Redirect and Direct connection each. The reference says only "your
   preferred connection method".

#### Where the research stopped, and how to carry on

1. **Read the help articles.** They need a browser that runs JavaScript, so use the built-in browser pane
   or Claude in Chrome rather than a plain fetch. Start with the two pre-auth articles and "What is
   considered a transaction?" (the fee question, decision C3).
2. **Read the rest of the API reference.** Start at character 419,904 (response and error codes), then the
   two ranges that were only summarised, looking for anything on `Authorise` with a token, a zero amount,
   the Responsive Shared Page and `CreateTokenCustomer`, and the response codes for a declined token.
3. **Try it in the sandbox (PW-X-05).** The eWAY sandbox has test card numbers and magic amounts that
   force declines. Add a card that a real issuer would decline and record what eWAY says at token creation
   and at the first charge. Then try `Authorise` against that token and record the response. The case is
   `todo` in [TEST_PLAN.md](TEST_PLAN.md) and is WordPress only (it needs the sandbox keys as secrets).
4. **Then settle C1 to C6** in the table above and write the acceptance criteria.

### Acceptance criteria

To be written once the options are decided.

### Test coverage

| ID | What it covers |
| --- | --- |
| PW-X-05 | The real sandbox: what happens when a card eWAY will decline is added, at token creation and at the first charge |

## FR-4 Let finance staff open the Failed Recurring Contributions list

| | |
| --- | --- |
| Status | `in progress` (built on `release-3.0.0`, green locally; PU-O6 to PU-O10 and PW-B-failed-13 wait for a GitHub run) |
| Raised | 2026-11-15 |
| Target release | 3.0.0 (the owner asked for it with the DEF-62 fix) |
| Related | DEF-62 in [TEST_PLAN.md](TEST_PLAN.md) |

### Summary

The Failed Recurring Contributions page lists the recurring records that need attention. It used to be open only to
administrators (DEF-62): the list was built from a saved search that joined the payment processor to keep only
eWAY records, and CiviCRM drops that join for anyone who may not read payment processors, because the processor
holds the API credentials. The page and its Reactivate action are now open to a user who has the "edit contributions"
permission (a finance role, for example), and that user still has no access to the processors.

### Why

Finance staff are the people who chase failed payments. Making them ask an administrator to open the list is a
real cost, and the page worked for no one but administrators before the interim fix, so there is nothing to lose
by doing this properly.

### Design options

| Option | How | Notes |
| --- | --- | --- |
| A | Filter eWAY records without joining the payment processor | Chosen, as a calculated field (decision F1). No extra read access is granted |
| B | Let the extension authorise that one read of the payment processor | Not recommended: the entity holds the API credentials. Not built |
| C | Keep the page for administrators only | The interim state set by the DEF-62 fix (commit `c14eb17`), now replaced |

### Decisions

| ID | Question | Status |
| --- | --- | --- |
| F1 | Which mechanism filters eWAY records for a user who cannot read payment processors | **Decided 2026-11-15**: a calculated yes/no field `is_eway_processor` on ContributionRecur, added through APIv4's spec provider extension point. See below |
| F2 | Which permission the page and menu entry need once it works | **Decided 2026-11-15**: "edit contributions", as before DEF-62 (and as in release 2.8.2). The data requests also need "access CiviCRM" and "access CiviContribute", which core and the extension already require for recurring records |

#### F1: how eWAY records are filtered

Chosen: the extension registers a spec provider (`Civi\EwayRecurring\Api4\ContributionRecurEwaySpecProvider`, registered in
`ewayrecurring_civicrm_container()`) that adds one read-only, calculated field to ContributionRecur, `is_eway_processor`. The
saved search filters on `is_eway_processor = TRUE` instead of joining PaymentProcessor. The field is rendered as SQL inside the
query: `IF(EXISTS (SELECT 1 FROM civicrm_payment_processor ... INNER JOIN civicrm_payment_processor_type ... WHERE processor id =
the record's payment_processor_id AND type name = 'eWay_Recurring'), 1, 0)`. It is the same test the old join made.

Why this one:

- It exposes one yes or no value and nothing else. It is not a join, so there is no processor field to select, filter or sort
  by, and it needs no permission on PaymentProcessor. Nothing authorises a read of that entity (option B is not built).
- It is worked out when the list is read, so it follows processors being added, removed or changed with nothing to refresh
  and nothing stored (PU-O8).
- It is core's documented extension point (the same one core uses for calculated fields such as "is current"), and the core
  version it relies on (6.16 and later, the extension's minimum) already has it. Nothing is patched or copied from core.
- It changes no other call: it is not part of a plain read, it is not a field of create or update, and no other screen or
  API call returns different rows (PU-O8).

What was weighed, with what was checked on CiviCRM 6.16.5 (read from the core code on the local site, then run):

| Option | Result |
| --- | --- |
| Filter on `payment_processor_id` against the ids of eWAY processors | A fixed list is not acceptable. A list kept up to date needs a hook on every processor change and the saved search still holds ids. APIv4's `IN` takes literal values only (`Api4Query.php`), so a live subquery cannot be written in the saved search. Rejected |
| An APIv4 listener or `hook_civicrm_selectWhereClause` limiting ContributionRecur rows for this search only | A listener cannot tell which screen made a request without a marker the request itself carries, and an ACL clause applies to every call and every join to the entity. Fragile, and the closest to changing what other screens return. Rejected |
| A join or virtual field from the schema map events | A join to the table `civicrm_payment_processor` is still checked against the PaymentProcessor entity (`Api4SelectQuery::autoJoinFK()` maps the table back to its entity and calls `checkEntityAccess()`), so it would be dropped the same way. Making the target something that is not a known table needs a table expression core did not design for. Rejected |
| A SQL-level approach in the saved search | The saved search is APIv4 parameters, not SQL, so this is the same idea as the calculated field. This is how it is done |
| The calculated field (chosen) | Verified: for a user holding exactly `access CiviCRM`, `view all contacts`, `access CiviContribute` and `edit contributions`, the old saved search fails with "Invalid field ...payment_processor_type_id" (the DEF-62 error) and the new one returns the right rows |

The saved search also loses its processor join and its processor title column (the display never showed it). Its payment token
join stays: the extension already lets anyone with "access CiviContribute" read payment tokens, which every user who can read
recurring records has.

How it was proved that the user cannot read processor data (PU-O6 to PU-O9, PW-B-failed-01 and PW-B-failed-13): as a user
with only "edit contributions" and the access it needs, PaymentProcessor reads are refused, a join of either kind to
PaymentProcessor returns no field (the same for `id`, `name`, `title`, `user_name`, `password`, `signature`, `url_site`,
`class_name` and the type), a condition on a processor field is refused as an invalid field, the new field returns only 1 or
0, and no processor key, password, name or title appears in the page's own responses or in the Search Kit display's data.

Limit of the guarantee: core lets any user who can read recurring records read the **title** of processors through the field's
option lookup (`payment_processor_id:label`, and `getFields` with `loadOptions`). That is core's behaviour for every recurring
read, not something this feature adds, and it never returns the key, password or any other field. It is logged as a core issue outside this repository.

What happens on a site that is upgraded:

- **Menu entry.** It is a managed record with the policy `unmodified`, so an ordinary reconcile (the extension upgrade, or any
  cache clear) updates it unless someone changed it in the menu editor. Release 2.8.2 already had "edit contributions", so a
  site upgrading from a release has nothing to change. A development build between commits `c14eb17` and this change has the
  administrator-only entry, and the reconcile sets it back (PU-O10). No upgrade step is needed: the existing mechanism does it.
- **Saved search and display.** The same policy applies, so an unmodified saved search is replaced with the new filter on the
  reconcile (PU-O10). A saved search someone edited in Search Kit is left alone, as the policy promises, and a user without
  administrator rights then still gets the old HTTP 500 until it is reverted (Search Kit, Saved Searches, Revert). The local
  development site had one that core counted as modified (probably left by an earlier local rehearsal of the upgrade test,
  which edits the saved search on purpose), and Revert gave it the new definition. This is in `docs/UPGRADE.md`.

### Acceptance criteria

1. A user with only "edit contributions" sees the page in the Contributions menu and sees the Failed and Failing
   eWAY records, and no records of other processors. (PU-O6, PW-B-failed-01)
2. That user can Reactivate a record from the list, and nothing about a payment processor (its name, key or
   password) is readable by them through the page or its requests. (PU-O7, PU-O9, PW-B-failed-01, PW-B-failed-13)
3. A user without "edit contributions" is refused, and an administrator sees what they saw before. (PU-O8, PU-O9, PW-B-failed-01)

### Test coverage

| ID | What it covers |
| --- | --- |
| PU-O6 | A user who may not administer sees only the live eWAY records that have a card, through the saved search and through the Search Kit display |
| PU-O7 | That user cannot read any processor field through ContributionRecur (joins, conditions, the new field, the page's data); the saved search does not name the PaymentProcessor entity |
| PU-O8 | The field follows processors added or changed with nothing to refresh, changes no other call, and an administrator sees the same records as the old join gave |
| PU-O9 | Reactivate works for a user with "edit contributions" and is refused without it |
| PU-O10 | The page and menu entry need "edit contributions"; an upgrade replaces an older menu entry and saved search unless a person changed them |
| PW-B-failed-01 | `finance` sees the entry under Contributions and the Failed and Failing eWAY records and can Reactivate one; `limited` is refused; the administrator still works |
| PW-B-failed-13 | `finance` cannot read any processor data through the page's responses, the Search Kit request or APIv4 |

---

## FR-5 Search Kit report of payments eWAY approved but CiviCRM could not complete

| | |
| --- | --- |
| Status | `idea` |
| Raised | 2026-11-21 |
| Target release | to be decided (not needed for 3.0.0: the log and a note on the contribution already say what happened) |
| Related | DEF-78 in [TEST_PLAN.md](TEST_PLAN.md), FR-4 (the same kind of Search Kit page), the "Payments eWay Approved but CiviCRM Could Not Complete" section of `docs/README.md` |

### Summary

A Search Kit report that lists every payment eWAY approved but the verification job could not complete in CiviCRM, with
the actions a person can take on each row and plain instructions for what to do next. Today (since DEF-78) such a payment
is kept in the verification queue and tried again for about 24 hours, then left `Pending` with its queue row marked
`Failed`. The only signs are an error in the log and a note on the contribution, so staff must know to look for them.

### Why

The donor has been charged but the contribution is `Pending`, so the payment is easy to miss and no receipt has been
sent. A finance user needs one place that shows which payments are in this state, why, and what to do about each, without
reading the log or querying the database.

### What the report shows

One row per queue row that holds an approved payment that could not be completed. These are the rows of the
`EwayContributionTransactions` entity whose `failed_message` starts with "Approved by eWAY but could not be completed: "
(`CRM_eWAYRecurring_Utils::APPROVED_NOT_COMPLETED_PREFIX`), in either state:

* **Retrying**: still in the queue (status 0), with the number of tries so far out of the limit.
* **Gave up**: status 2 after the limit, the contribution still `Pending`. These need a person.

Suggested columns: the contribution (link), the contact, the amount and date, the eWAY transaction ID, the reason (the rest of
`failed_message`), the number of tries, and the state (Retrying or Gave up).

### Actions and instructions

A box above the list says what to do next, in the same words as the documentation:

1. Check in the eWAY Business Centre that the payment was taken, using the transaction ID.
2. Fix the reason shown.
3. If the row is Retrying, wait: the next run completes it. If it Gave up, complete the contribution by hand.
4. If eWAY shows the payment was not taken, set the contribution to Failed or Cancelled.

Row actions to consider (each one is a decision, A2):

| Action | What it does |
| --- | --- |
| Open contribution | Opens the contribution so the person can set it to Completed and enter the eWAY transaction ID |
| Try again now | Sets the tries back to 0 and the status back to in queue, so the next run asks eWAY again (only after the reason is fixed) |
| Mark as handled | Removes the queue row once the contribution has been completed or failed by hand, so it leaves the report |
| Open in eWAY | A link to the Business Centre transaction search |

### Design options

| Option | How | Notes |
| --- | --- | --- |
| A | A saved search and Search Kit display (an APIv4 filter on `failed_message`) with the instructions in the page's description | Matches FR-4 and the Failed Recurring Contributions list. No schema change |
| B | As A, plus a CiviCRM system status check that warns while any row has Gave up | Same idea as the existing check for failed recurring contributions. Small addition |
| C | A new `status` value for "approved, not completed" instead of the message prefix | Cleaner to filter on, but needs an upgrade step (DEF-78 chose the message, decision 4A) |

### Decisions still open

| ID | Question | Status |
| --- | --- | --- |
| A1 | Where does the page live and which permission does it need? The Failed Recurring Contributions page uses "edit contributions" (FR-4 F2) | Open |
| A2 | Which row actions are built (the table above)? "Try again now" and "Mark as handled" change data, so they need a permission and a confirmation | Open |
| A3 | Add a system status check (option B) so someone is told when a payment has been given up on? | Open |
| A4 | Should the report also show the rows that gave up on a payment eWAY declined or never answered, or only approved ones? Today those are not in this list | Open |
| A5 | Does "Try again now" reset to 0 tries (24 more hours) or add a fixed number of tries? | Open |

### Acceptance criteria

1. A payment eWAY approved but the job could not complete appears in the report while it is Retrying and after it has Gave up, with its contribution, amount, eWAY transaction ID, reason and tries.
2. Payments that completed, failed normally or were never approved do not appear.
3. The instructions are on the page, and match `docs/README.md`.
4. A user without the page's permission is refused, and no payment processor data is readable through the page.
5. Each row action does what the table says, and none of them marks an approved payment Failed.

### Test coverage

To be written once decisions A1 to A5 are settled. Likely: PHPUnit cases for the saved search's filter and the permission,
and Playwright cases for the page, the instructions and each action, with a fixture that creates a held payment through the
verification job (PU-D19 to PU-D24 already cover how a row gets into this state).

---

## FR-6 Blocked Recurring Contributions list with Reactivate and Cancel

| | |
| --- | --- |
| Status | `in progress` (built on `release-3.0.0`; its tests wait for a CI run) |
| Raised | 2026-11-21 |
| Target release | 3.0.0 (it deals with records left blocked by the change that makes a Cancelled contribution skip an installment) |
| Related | DEF-68 in [TEST_PLAN.md](TEST_PLAN.md), FR-4 (the same kind of page), `docs/UPGRADE.md` (3.0.0) |

### Summary

A Search Kit page, "Blocked Recurring Contributions", under the Contributions menu, listing the eWAY recurring contributions that
are due to be charged but that the scheduled job will not charge, because one of their contributions is Cancelled and is dated on
or after the date the record is waiting on. Each row has a Reactivate button (to set a new next scheduled date) and a Cancel
button (to stop the recurring contribution for good), and the page carries instructions for what to do. A system status check
counts the records and links to the page.

### Why

Cancelling a contribution now makes the extension skip that installment and move the date on, so new records are not left
waiting. A record that already had such a contribution before the upgrade, or whose contribution was cancelled in a way that did
not trigger the skip, stays blocked, and nothing says so. This page and the status check make those records visible and give
staff the two ways out. It replaces the earlier idea of an upgrade step, an SQL query in the docs or a log line.

### What it does

* **Blocked record**: live (not test), In Progress, paid through eWAY, and due (its retry date while it has one, otherwise its
  next scheduled date, is not in the future), with a Cancelled contribution dated on or after that date. The calculated field
  `is_blocked_by_cancelled_contribution` on ContributionRecur says so, in SQL, like `is_eway_processor` (FR-4).
* **Page**: `civicrm/contribute/blockedrecurring`, menu entry "Blocked Recurring Contributions" under Contributions, permission
  "edit contributions". Columns: contact, Recurring ID (opens the recurring contribution and its contributions), next scheduled
  date, retry date, amount, frequency.
* **Reactivate**: the existing action. With a date after the cancelled contribution it unblocks the record. With no date it
  changes nothing about the block, which the instructions say.
* **Cancel**: a new Search Kit task. It sets the status Cancelled with the cancel date and an optional reason, as core's
  Cancel Subscription does to the record, and sends nothing to eWAY.
* **Status check** `ewayrecurring_blocked`: "Found N recurring contributions that are not being charged ...", with a link to the page.
  It can be disabled like any status check.

### Decisions

| ID | Question | Status |
| --- | --- | --- |
| B1 | Build it, in place of an upgrade step | **Decided 2026-11-21** (owner, option 6) |
| B2 | Name | **Decided 2026-11-21**: "Blocked Recurring Contributions" |
| B3 | How Cancel works | **Decided 2026-11-21**: a custom task that does what core's cancel does to the record, not the built-in Update task |
| B4 | Where it is recorded | **Decided 2026-11-21**: this entry (FR-6). FR-5, the report of approved but not completed payments, is a different list and stays an `idea` |
| B5 | Permission | **Decided 2026-11-21**: "edit contributions", as the Failed Recurring Contributions page |

### Acceptance criteria

1. A user with only "edit contributions" sees the entry under Contributions and the blocked records, and nothing else. (PU-P2, PW-B-blocked-01)
2. A user without that permission is refused and has no menu entry. (PU-P5, PW-B-blocked-01)
3. Reactivate with a later date removes a record from the list; Cancel stops it and removes it. (PU-P3, PW-B-blocked-02, PW-B-blocked-03)
4. The status check counts exactly the records on the list and links to it. (PU-P4, PW-B-blocked-04)
5. Cancelling a contribution through the normal save leaves nothing on the list. (PU-P3, PU-F42)

### Test coverage

PU-P1 to PU-P5 (`BlockedRecurringSearchTest`) and PW-B-blocked-01 to PW-B-blocked-04 (`blocked-recurring.spec.ts`).

---

## 2. Change log

Newest first. One line per change to this file.

| Date | Change |
| --- | --- |
| 2026-11-21 | FR-6 added and built (`in progress`): the Blocked Recurring Contributions list with Reactivate and Cancel buttons, its instructions and a status check, for records left blocked by a Cancelled contribution. All five decisions (B1 to B5) taken by the owner. |
| 2026-11-21 | FR-5 added as `idea`: a Search Kit report of payments eWAY approved but CiviCRM could not complete (the rows DEF-78 now keeps in the verification queue), with row actions and instructions for what to do next, five open decisions (A1 to A5). |
| 2026-11-15 | FR-4 built (`in progress`): decisions F1 (a calculated `is_eway_processor` field on ContributionRecur through a spec provider replaces the join to the payment processor) and F2 ("edit contributions" for the page and menu entry) decided and recorded with the options weighed and the evidence. Page and menu entry back to "edit contributions". PU-O6 to PU-O10 and PW-B-failed-13 added. |
| 2026-11-15 | FR-4 added as `planned`: let finance staff open the Failed Recurring Contributions list without access to payment processors (the better follow-up to DEF-62, which now needs "administer CiviCRM"). |
| 2026-11-15 | FR-1 decision D3 decided by the owner: a failure does not move the schedule date, a successful charge moves it from the due date to the first date after today, so a recovered record is charged once by the next run (built and tested as DEF-46 and DEF-58, PU-F29 to PU-F40). |
| 2026-11-14 | FR-3 added as `idea`: check a new card with the issuer when it is added (recorded as DEF-33, reclassified as not a defect), with what eWAY's documentation says, three options and six open decisions (C1 to C6). Not scheduled. |
| 2026-11-14 | FR-2 upgrade tests UP-10 and UP-11 written (`tests/upgrade/`) and passed a local rehearsal; waiting for a GitHub run. |
| 2026-11-14 | FR-2 tests PU-L6 to PU-L10 passing in CI. UP-10 and UP-11 still to be written; FR-2 stays `in progress` until they are done. |
| 2026-11-14 | FR-2 built on `release-3.0.0`: `upgrade_30000` and `markStuckRecurringContributionsFailed()` added to the upgrader, with tests PU-L6 to PU-L10 (`wip` until CI passes). UP-10 and UP-11 not written yet. Status `in progress`. |
| 2026-11-14 | FR-2 decision U5 settled: no new preview feature for 3.0.0. The two SQL checks (before upgrading) plus the step's log and upgrade output are the preview. All FR-2 decisions U1 to U6 are now decided; status stays `planned` until the step is built. |
| 2026-11-14 | FR-2 decision U4 changed from option B to option C: Rule 2 marks records with a retry date and no failures Failed (no charge) instead of clearing the retry date. FR-2 text, risks, SQL check and acceptance criteria updated. Added the catch-up charge test result to FR-1 decision D3. |
| 2026-11-14 | FR-2 decision U4 settled: the step also repairs records with a retry date and no failures (Rule 2) by clearing the retry date, so the next cron run charges them once. FR-2 renamed and reworked into two rules; added the Rule 2 risk (donors are charged), a second SQL check, and a guard that Rule 1 needs `failure_count` above 0. |
| 2026-11-14 | FR-2 decisions U2, U3 and U6 settled: test-mode and Pending records are left alone, and the retry limit saved when the step runs is used. Selection text updated. |
| 2026-11-14 | FR-2 decision U1 settled: the upgrade step updates with a single SQL statement. Risk and behaviour text updated; logging note corrected (database triggers still record the change). |
| 2026-11-14 | FR-2 added: an upgrade step that marks recurring contributions left stuck by releases up to 2.8.2 as Failed, planned for 3.0.0, with six open decisions (U1 to U6). |
| 2026-11-14 | File created with FR-1, the donor-facing update-my-stored-card page, as `planned`. Nine open decisions recorded. |
