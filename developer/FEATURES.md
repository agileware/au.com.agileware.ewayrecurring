# Planned features: eWay Recurring Payment Processor

| | |
| --- | --- |
| Extension version | 2.8.2 (work in progress on branch `release-3.0.0`) |
| Status last updated | 2026-11-14 |
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
| [FR-2](#fr-2-upgrade-step-mark-exhausted-recurring-contributions-failed) | Upgrade step: mark exhausted recurring contributions Failed | `planned` | 3.0.0 | PU-L6 to PU-L8, UP-10 |

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
| D3 | Open | After recovering a Failed recurring contribution, what happens to its next scheduled date: leave it (the cron charges the missed date straight away), move it into the future (the missed payment is skipped), or restart it from today? | The staff form's recovery leaves the date alone, as Reactivate does by default. Decide whether the donor page should behave the same |
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

## FR-2 Upgrade step: mark exhausted recurring contributions Failed

| | |
| --- | --- |
| Status | `planned` |
| Raised | 2026-11-14 |
| Target release | 3.0.0 |

### Summary

When 3.0.0 is installed over an earlier release, find the recurring contributions that the old retry
behaviour left stuck and mark them Failed, so they appear in the Failed Recurring Contributions list
and the System Status check, and so anything that recovers Failed records (Reactivate, or a site's
own update-card page) can recover them.

### Why

Up to 2.8.2, when a recurring contribution used up its retries it kept the status In Progress, with
`failure_count` at the retry limit and a retry date set. Neither cron list selects such a record:
the normal list only takes records with no retry date, and the retry list only takes records below
the limit. The Failed Recurring list, the System Status check and the Reactivate action all look for
the status Failed, so they never see it. The record stays stuck, and the donor is never charged again,
with nothing on screen to say so.

The fix for this (DEF-3 in the test plan) marks a record Failed once its retries are used up, but only
from then on. It does not touch records that were already stuck when the site upgraded. Without a data
step, those donors stay stuck after the upgrade, including on sites whose own update-card page resets
Failed records, because that reset finds nothing to reset.

### Proposed behaviour

- A new revision, `upgrade_30000`, in `CRM_eWAYRecurring_Upgrader`.
- It selects recurring contributions that use an eWAY recurring payment processor, have the status In
  Progress, have a retry date, and have `failure_count` at or above the retry limit saved in the
  setting `eway_recurring_contribution_max_retry` when the step runs.
- It sets their status to Failed with a single SQL statement (decision U1), so no PHP hooks or rules fire, and changes nothing else: the failure count, retry date and next
  scheduled date stay as they were.
- It can be run again safely (a second run finds nothing to change).
- Detailed logging, if the site has it enabled, still records the change, because it works through database triggers (without a user id).
- It writes the number of records changed, and their ids, to the CiviCRM log, and shows the number
  in the upgrade output.
- It sends no email and creates no activity.

After the upgrade the System Status check starts reporting these records, because each one's retry
date is already more than four hours old. That is intended: it is the first time anyone sees them.

### Risks

- **Hooks and rules.** Changing the status through the API would fire the usual hooks, and CiviRules or similar tools may have rules on recurring contribution changes, so a bulk change could send many emails. Decision U1 is to update with SQL, which fires no PHP hooks or rules. The cost is that nothing reacts to the change either: a site that relies on a rule to follow up a Failed recurring contribution will not see one for these records.
- **A sudden count.** A site with many stuck records will see a large number in System Status and
  in the Failed list on the day it upgrades. Release notes should say so.
- **Recovery is not automatic.** Marking a record Failed does not charge it. Staff (Reactivate) or a
  site's own update-card page still have to recover it. What happens to its schedule date on
  recovery is decision D3 in FR-1.

### Checking a site by hand

Before upgrading, this lists the records the step would change. Replace the two placeholders with
the eWAY processor's id and the saved retry limit:

```sql
SELECT cr.id, cr.contact_id, cr.failure_count, cr.failure_retry_date
FROM civicrm_contribution_recur cr
WHERE cr.payment_processor_id = <processor id>
  AND cr.failure_count >= <retry limit>
  AND cr.failure_retry_date IS NOT NULL
  AND cr.contribution_status_id = (
    SELECT ov.value FROM civicrm_option_value ov
    JOIN civicrm_option_group og ON og.id = ov.option_group_id
    WHERE og.name = 'contribution_recur_status' AND ov.name = 'In Progress');
```

### Decisions still open

| # | Status | Question | Notes |
| --- | --- | --- | --- |
| U1 | Decided | Apply the change through the API (hooks and rules fire, logging records it) or with direct SQL (silent)? | 2026-11-14: **direct SQL**, one statement. Avoids a burst of emails or rule runs on the day of the upgrade. No PHP hooks or rules fire and no activity is created. Database logging triggers still record the change if logging is enabled. The step logs the ids it changed |
| U2 | Open | Include test-mode records? | The System Status check ignores them. Proposed: leave them alone |
| U3 | Open | Include records still Pending? | The retry list only selects In Progress, so a Pending record that failed is stuck in the same way. Such records are rare (the cron only charges a recurring contribution that already has a Completed contribution), so proposed: leave them alone |
| U4 | Open | Also repair the other stuck state: In Progress, retry date set, `failure_count` 0? | Left by an old update-billing page that reset the count but not the date. Neither list selects it. A repair would clear the retry date so the normal list charges it on the next run, which charges the missed payment straight away |
| U5 | Open | Offer a preview (an API action that lists what would change) so staff can check before upgrading? | The SQL above is the minimum |
| U6 | Open | Use the retry limit saved at upgrade time, or treat any record with failures and a retry date as exhausted? | Saved limit proposed. A site that lowered the limit would see more records marked |

### Acceptance criteria

- [ ] An upgrade from 2.8.1 marks every stuck record Failed and nothing else (UP-10).
- [ ] Records that must not change are unchanged: below the limit, no retry date, other statuses,
      other processors, and test records unless U2 says otherwise (PU-L7).
- [ ] Running the step twice changes nothing more (PU-L8).
- [ ] No email is sent and no activity is created by the step (PU-L8).
- [ ] The count appears in the upgrade output and the log.
- [ ] The release notes describe the new System Status message and the Failed list entries.

### Test coverage

| ID | What it covers |
| --- | --- |
| PU-L6 | The step marks exhausted In Progress records Failed and leaves their other fields alone |
| PU-L7 | Records that must not change |
| PU-L8 | Idempotent, uses the saved limit, reports the count, no mail or activities |
| UP-10 | The same through a real upgrade from the baseline release |
| PU-F14, PU-F15 | The DEF-3 behaviour for records that become exhausted after the upgrade |

---

## 2. Change log

Newest first. One line per change to this file.

| Date | Change |
| --- | --- |
| 2026-11-14 | FR-2 decision U1 settled: the upgrade step updates with a single SQL statement. Risk and behaviour text updated; logging note corrected (database triggers still record the change). |
| 2026-11-14 | FR-2 added: an upgrade step that marks recurring contributions left stuck by releases up to 2.8.2 as Failed, planned for 3.0.0, with six open decisions (U1 to U6). |
| 2026-11-14 | File created with FR-1, the donor-facing update-my-stored-card page, as `planned`. Nine open decisions recorded. |
