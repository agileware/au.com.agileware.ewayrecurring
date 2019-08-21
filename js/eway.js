CRM.$(function($) {
    $("#payment_processor_id")
        .after('<p id="eway_help_text" class="description">Click on the Save button to display the credit card payment page and complete this transaction. Credit card details are not entered directly into CiviCRM and are processed directly by eWay, <a href="https://www.eway.com.au/about-eway/technology-security/pci-dss/">learn more on this page.</a></p>')
        .on('change', function() {
            showOrHideHelpText(this.value);
    });

    showOrHideHelpText($("#payment_processor_id").val());

    function showOrHideHelpText(value) {
        if (CRM.vars.agilewareEwayExtension.paymentProcessorId.includes(value)) {
            $("#eway_help_text").show();
        } else {
            $("#eway_help_text").hide();
        }
    }
});