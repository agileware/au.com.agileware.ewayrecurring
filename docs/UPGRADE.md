# Upgrade instructions

## Changing from a different eWay Payment Processor to using eWay Recurring Payment Processor

When changing a CiviCRM from a different EWAY Payment Processor to this one, it is recommended to
edit the existing Payment Processor and simply change the **Payment Processor Type** to **eWay Recurring**
and not add a new Payment Processor.

This will avoid issues where existing Recurring Contributions cannot be viewed or the Contact
Contributions tab will not load because when the previous
Payment Processor is disabled.

## 3.0.0

### The next payment date no longer moves when a payment fails

Earlier releases moved a recurring contribution's next payment date on one interval as soon as a
payment was attempted, whether or not it succeeded. From 3.0.0 the date moves only after a charge
succeeds, whether it is the first attempt or a retry. It moves one interval on from the date the
payment was due, or to the first date after today if that has already passed.

This means that a payment that fails and then succeeds on a retry no longer skips a period, and a
recurring contribution that failed (for example because the card expired) and is then recovered, by
updating its card or with Reactivate, is charged once by the next run of the scheduled job. The
periods it missed while it was Failed are not charged as arrears.

Recurring contributions that are already failing when you upgrade keep the date the earlier release
gave them. The upgrade does not change any date.

## 2.0.0

The 2.0.0 version introduces use of the eWay RapidAPI 3.1, which requires a
different method of authentication with eWay from that used in the 1.x series.

You will need to Download and extract the extension as usual, and after running
the Extensions upgrades, you will need to [generate an API Key and
Password](https://go.way.io/s/article/How-do-I-setup-my-Live-eWAY-API-Key-and-Password)
and update these details in your Payment Processor settings.

Once your authentication details are updated, existing recurring payments will
continue to operate as usual.

## 1.2.0

Versions prior to 1.2.0 kept track of remaining installments by decrementing the
installents field of each ContributionRecur, making it difficult to determine
the total number of installments for a given contribution.
1.2.0 changes this behaviour, and includes an upgrade script that will add a
count of contributions that have already passed to the installments count so
that the count of installments remains consistent with new data, and in-progress
recurring contributions are not terminated early.
If you do not wish this upgrade script to be run, you will need to manually set
the schema version of the au.com.agileware.ewayrecurring extension to 5.

