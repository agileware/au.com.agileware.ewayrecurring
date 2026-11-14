(function (angular, $, _) {
    "use strict";

    /**
     * Defines the search task dialog controller
     */
    angular.module('ewayrecurring').controller('crmSearchTaskResetRecur', function ($scope, $timeout, crmApi4, searchTaskBaseTrait) {
        const ts = $scope.ts ??= CRM.ts('au.com.agileware.ewayrecurring'),
            ctrl = angular.extend(this, $scope.model, searchTaskBaseTrait);

        // Default next scheduled date, blank
        ctrl.next_date = '';

        // Placeholder status label
        let statusLabel = this.statusLabel ?? ts('In Progress');

        // Callback to set the status message consistently
        const statusMessage = () => ts(
            'Clear the failures on %1 selected recurring contribution and reset its status to "%2"',
            {
                1: ctrl.ids.length,
                2: statusLabel,
                plural: 'Clear the failures on %1 selected recurring contributions and reset their statuses to "%2"',
                count: ctrl.ids.length
            });

        // Status Message initial value
        ctrl.statusMessage = statusMessage();

        // If we haven't previously fetched the "In Progress" status label from the system, do so now and update the status message
        if (typeof this.statusLabel == 'undefined') {
            crmApi4('ContributionRecur', 'getfields', {
                where: [['name', '=', 'contribution_status_id']],
                loadOptions: ['id', 'name', 'label'],
                select: ['options']
            }).then((result) => $timeout(() => $scope.$apply(() => {
                // This is called inside $scope.$apply so we actually update the message in the interface.
                // An arrow function: a plain function has no `this` in strict mode, which made this throw
                // "Cannot set properties of undefined (setting 'statusLabel')" every time the dialog opened.
                statusLabel = this.statusLabel = _.indexBy(result[0].options, 'name')['In Progress'].label;
                ctrl.statusMessage = statusMessage();
            })));
        }

        // Submit callback
        this.submit = function () {
            // These are the values for the update function.
            // Passed to the crm-search-batch-runner directive in crmSearchTaskResetRecure.html.
            const values = {
                'contribution_status_id:name': 'In Progress',
                failure_count: 0,
                failure_retry_date: ''
            };

            // Only set the next_sched_contribution_date if we have one to set.
            if (ctrl.next_date) {
                values.next_sched_contribution_date = ctrl.next_date;
            }

            // Start the batch runner
            ctrl.start({ values });
        }

        // Success Callback: Alert and close
        this.onSuccess = function (result) {
            CRM.alert(
                ts('Successfully reactivated %1 Recurring Contributions.', { 1: ctrl.ids.length }),
                ts('Reactivation Complete'),
                'success'
            );
            this.close();
        }
        
        // Error Callback: Alert and cancel operation
        this.onError = function (err) {
            CRM.alert(ts('An error occurring while attempting to reactivate %1 Recurring Contributions.', { 1: ctrl.ids.length }),
                ts('Reactivation Error'),
                'error'
            );
            this.cancel();
        }
    })

})(angular, CRM.$, CRM._);