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
    CRM.$('.eway_credit_card_field').prop('disabled', function (i, v) {
        return !v;
    });
};

CRM.eway.paymentTokenInitialize = function () {
    CRM.eway.paymentTokens = [];
    if (typeof CRM.eway.contact_id === 'undefined') {
        CRM.eway.contact_id = 0;
        CRM.eway.toggleCreditCardFields();
    } else {
        CRM.eway.setPaymentTokenOptions();
    }
};

/**
 * Trigger when add credit card button clicked
 */
CRM.eway.addCreditCard = function () {
    let url = CRM.url('civicrm/ewayrecurring/createtoken', {
        'contact_id': CRM.eway.contact_id,
        'pp_id': CRM.$("#payment_processor_id").val()
    }, 'front');
    window.open(url, '_blank');
    CRM.confirm({
        'message': 'Click Ok to update the card list.',
        'options': {
            'yes': 'Ok'
        }
    }).on('crmConfirm:yes', function () {
        let url = CRM.url('civicrm/ewayrecurring/savetoken', {
            'pp_id': CRM.$("#payment_processor_id").val()
        }, 'front');
        CRM.$.ajax({
            "url": url
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
};

CRM.eway.getUrlParameter = function (name) {
    var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
    var results = regex.exec(location.search);
    return results === null ? '' : decodeURIComponent(results[1].replace(/\+/g, ' '));
};

CRM.$(function ($) {
    /**
     * For contribution form with no contact selected at the beginning
     */
    $("#contact_id").on('change', function (event) {
        if (event.val <= 0) {
            return;
        }
        CRM.eway.contact_id = event.val;
        CRM.eway.setPaymentTokenOptions();
        CRM.eway.toggleCreditCardFields();
    });
});