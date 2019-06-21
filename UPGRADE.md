# eWay Recurring payment processor Upgrade notes.

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

