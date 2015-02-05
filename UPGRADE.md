# eWAY Recurring payment processor Upgrade notes.

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
