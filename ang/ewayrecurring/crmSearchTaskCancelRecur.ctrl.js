(function (angular, $, _) {
    "use strict";

    /**
     * Defines the Cancel Recurring Contribution search task dialog controller.
     *
     * It does what core's Cancel Subscription does to the record (CRM_Contribute_BAO_ContributionRecur::cancelRecurContribution):
     * the status becomes Cancelled, with the cancellation date and an optional reason. eWAY holds no schedule for these
     * records (CiviCRM charges them), so nothing is sent to eWAY.
     */
    angular.module('ewayrecurring').controller('crmSearchTaskCancelRecur', function ($scope, crmApi4, searchTaskBaseTrait) {
        const ts = $scope.ts ??= CRM.ts('au.com.agileware.ewayrecurring'),
            ctrl = angular.extend(this, $scope.model, searchTaskBaseTrait);

        // Optional reason, blank
        ctrl.reason = '';

        ctrl.statusMessage = ts(
            'Cancel %1 selected recurring contribution? It will never be charged again.',
            {
                1: ctrl.ids.length,
                plural: 'Cancel %1 selected recurring contributions? They will never be charged again.',
                count: ctrl.ids.length
            });

        // Submit callback
        this.submit = function () {
            const values = {
                'contribution_status_id:name': 'Cancelled',
                cancel_date: 'now'
            };

            // Only save a reason if there is one.
            if (ctrl.reason) {
                values.cancel_reason = ctrl.reason;
            }

            // Start the batch runner
            ctrl.start({ values });
        }

        // Success Callback: Alert and close
        this.onSuccess = function (result) {
            CRM.alert(
                ts('Successfully cancelled %1 Recurring Contributions.', { 1: ctrl.ids.length }),
                ts('Cancellation Complete'),
                'success'
            );
            this.close();
        }

        // Error Callback: Alert and cancel operation
        this.onError = function (err) {
            CRM.alert(ts('An error occurred while attempting to cancel %1 Recurring Contributions.', { 1: ctrl.ids.length }),
                ts('Cancellation Error'),
                'error'
            );
            this.cancel();
        }
    })

})(angular, CRM.$, CRM._);
