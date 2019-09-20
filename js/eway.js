CRM.eway = {};
CRM.eway.paymentTokens = [];

CRM.eway.setPaymentTokenOptions = function () {
    CRM.api3('PaymentToken', 'get', {
        "sequential": 1,
        "contact_id": CRM.eway.contact_id,
        "expiry_date": {">": "now"},
        "options": {"sort": "expiry_date DESC"}
    }).then(function (result) {
        CRM.eway.updateOptions(result);
    }, function (error) {
        console.error(error);
    });
};

/**
 * Update the option list with the given api3 response
 * @param result
 */
CRM.eway.updateOptions = function (result) {
    let options = {};
    options.result = result.values;
    if (JSON.stringify(CRM.eway.paymentTokens) === JSON.stringify(options.result)) {
        return;
    }
    options.html = "";
    options.result.forEach(function (value) {
        let expireDate = new Date(value.expiry_date);
        let month = expireDate.getMonth() + 1;
        if (month < 10) {
            month = '0' + month;
        }
        let text = value.masked_account_number.slice(-4) + " - " + month + "/" + expireDate.getFullYear();
        html = "<option value=\"" + value.id + "\">" + text + "</option>";
        options.html += html;
    });
    //console.info(options);
    let $elect = CRM.$('#contact_payment_token');
    if ($elect)
        $elect.find('option').remove();
    if (options.result.length === 0) {
        $elect.append("<option value>No cards found.</option>");
    } else {
        $elect.append(options.html);
    }
    if (CRM.eway.paymentTokens.length !== 0) {
        CRM.alert(
            ts('The credit card list has been updated.'),
            ts('eWay'),
            'info',
            {
                'expires': 10000
            }
        );
    }
    CRM.eway.paymentTokens = options.result;
};

CRM.eway.toggleCreditCardFields = function () {
    CRM.$('select.eway_credit_card_field').prop('disabled', function (i, v) {
        if (CRM.eway.contact_id === 0) {
            CRM.$('#add_credit_card_notification').text('No contact selected');
            return true;
        }
        CRM.$('#add_credit_card_notification').text('');
        return false;
    });

    CRM.$('input.eway_credit_card_field').prop('disabled', function (i, v) {
        if (CRM.eway.contact_id === 0) {
            CRM.$('#add_credit_card_notification').text('No contact selected');
            return true;
        }
        const requiredFields = [
            'billing_first_name',
            'billing_last_name',
            'billing_street_address',
            'billing_city',
            'billing_country_id',
            'billing_state_province_id',
            'billing_postal_code'
        ];
        for (const field of CRM.$('form').serializeArray()) {
            for (const required of requiredFields) {
                if (field.name.includes(required)) {
                    if (field.value.length === 0) {
                        CRM.$('#add_credit_card_notification').text('The Billing Details section must be completed before a Credit Card can be added');
                        return true;
                    }
                }
            }
        }

        CRM.$('#add_credit_card_notification').text('');
        return false;
    });
};

CRM.eway.paymentTokenInitialize = function () {
    CRM.eway.paymentTokens = [];
    if (typeof CRM.eway.contact_id === 'undefined') {
        CRM.eway.contact_id = 0;
    } else {
        CRM.eway.setPaymentTokenOptions();
    }
    CRM.eway.toggleCreditCardFields();

    // move the button
    CRM.$('div.add_credit_card-section').appendTo('.billing_name_address-group');

    // add listener

    /**
     * For contribution form with no contact selected at the beginning
     */
    CRM.$("#contact_id").on('change', function (event) {
        if (event.val <= 0) {
            return;
        }
        CRM.eway.contact_id = event.val;
        CRM.eway.setPaymentTokenOptions();
    });

    CRM.$(':input').on('change', function (event) {
        CRM.eway.toggleCreditCardFields();
    });
};

/**
 * Trigger when add credit card button clicked
 */
CRM.eway.addCreditCard = function () {
    let ppid = CRM.$("#payment_processor_id").val();
    if (typeof ppid === 'undefined') {
        ppid = CRM.eway.contact_id;
    }
    let url = CRM.url('civicrm/ewayrecurring/createtoken', {
        'contact_id': ppid,
        'pp_id': CRM.eway.ppid
    }, 'front');
    let data = CRM.$('form').serialize();
    let deferred = CRM.$.Deferred();
    CRM.status({
        start: ts('processing'),
        success: ts('done')
    }, deferred);
    CRM.$.ajax({
        "url": url,
        "type": "POST",
        "data": data
    }).done(function (data) {
        console.info(data);
        if (data['is_error']) {
            deferred.reject({
                error_message: data['message']
            });
        } else {
            deferred.resolve();
            window.open(data['url'], '_blank');
            CRM.confirm({
                'message': 'Click Ok to update the card list.',
                'options': {
                    'yes': 'Ok'
                }
            }).on('crmConfirm:yes', function () {
                let url = CRM.url('civicrm/ewayrecurring/savetoken', {
                    'pp_id': CRM.$("#payment_processor_id").val()
                }, 'front');
                console.log(data);
                CRM.$.ajax({
                    "url": url,
                }).done(function (data) {
                    console.info(data);
                    if (data['is_error']) {
                        CRM.alert(
                            ts('The credit card was not saved. Please try again.'),
                            ts('eWAY Error'),
                            'error'
                        );
                    } else {
                        CRM.eway.updateOptions(data['message']);
                    }
                });
                CRM.eway.setPaymentTokenOptions();
            });
        }
    });

};

CRM.eway.getUrlParameter = function (name) {
    var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
    var results = regex.exec(location.search);
    return results === null ? '' : decodeURIComponent(results[1].replace(/\+/g, ' '));
};

CRM.$(function ($) {

});