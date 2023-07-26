CRM.eway = {};
CRM.eway.paymentTokens = [];
CRM.eway.updatedToken = 0;
CRM.eway.selectedToken = 0;

CRM.eway.setPaymentTokenOptions = async function () {
  try {
    const options = await CRM.api4('PaymentToken', 'get', {
      where: [
        ['contact_id', '=', CRM.eway.contact_id],
        ['expiry_date', '>', 'now'],
      ],
      orderBy: {expiry_date: 'DESC'}
    });

    CRM.eway.updateOptions(options);
  } catch (e) {
    console.error(e);
  }
};

/**
 * Update the option list with the given api4 response
 * @param result
 */
CRM.eway.updateOptions = function (values) {
    if (JSON.stringify(CRM.eway.paymentTokens) === JSON.stringify(values)) {
        return;
    }

    let html = '';

    for(const value of values) {
        const expireDate = new Date(value.expiry_date.replace(/\s/, 'T'));
        let month = expireDate.getMonth() + 1;
        if (month < 10) {
            month = '0' + month;
        }

        html += `<option value="${value.id}">${value.masked_account_number.slice(-4)} - ${month}/${expireDate.getFullYear()}</option>`;
    }

    const $select = CRM.$('#contact_payment_token');
    if ($select) {
        $select.find('option').replaceWith(html || '<option value="">No cards found.</option>')

        if(CRM.eway.selectedToken) {
            $select.val(CRM.eway.selectedToken);
        }
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
    CRM.eway.paymentTokens = values;
    CRM.eway.selectedToken = $select.val();
};

CRM.eway.toggleCreditCardFields = function () {
    CRM.$('select.eway_credit_card_field').prop('disabled', function (i, v) {
        if (CRM.eway.contact_id === 0) {
            CRM.$('#add_credit_card_notification')
              .addClass('crm-error').text('No contact selected');
            return true;
        }
        CRM.$('#add_credit_card_notification')
          .removeClass('crm-error').text('');
        return false;
    });

    CRM.$('input.eway_credit_card_field').prop('disabled', function (i, v) {
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
                        CRM.$('#add_credit_card_notification')
                          .addClass('crm-error')
                          .text('The Billing Details section must be completed before a Credit Card can be added');
                        return true;
                    }
                }
            }
        }
        CRM.$('#add_credit_card_notification')
          .removeClass('crm-error')
          .text('');
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
    CRM.$('.credit_card_info-group').insertAfter('.billing_name_address-group');

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
        CRM.eway.selectedToken = CRM.$('#contact_payment_token').val();
    });
};

/**
 * Trigger when add credit card button clicked
 */
CRM.eway.addCreditCard = function () {
    let ppid = CRM.$("#payment_processor_id").val();
    if (typeof ppid === 'undefined') {
        ppid = CRM.eway.ppid;
    }
    let cid = CRM.eway.contact_id;
    if (!cid && CRM.vars.coreForm.contact_id) {
      cid = CRM.vars.coreForm.contact_id;
    }
    let url = CRM.url('civicrm/ewayrecurring/createtoken', {
        'contact_id': cid,
        'pp_id': ppid
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
        if (data['is_error'] === 0) {
            deferred.resolve();
            window.open(data['url'], '_blank');
            CRM.confirm({
                'message': 'Click Ok to update the card list.',
                'options': {
                    'yes': 'Ok'
                }
            }).on('crmConfirm:yes', function () {
                let url = CRM.url('civicrm/ewayrecurring/savetoken', {
                    'pp_id': ppid
                }, 'front');
                CRM.$.ajax({
                    "url": url,
                }).done(function (data) {
                    if (data['is_error']) {
                        CRM.alert(
                            ts('The credit card was not saved. Please try again.'),
                            ts('eWAY Error'),
                            'error'
                        );
                    } else {
                        CRM.eway.updatedToken = data['token_id'];
                        CRM.eway.updateOptions(data['message']);
                    }
                });
                CRM.eway.setPaymentTokenOptions();
            });
        } else {
            deferred.reject({
                error_message: data['message'] ? data['message'] : 'Unknown error. Please check Civi log for more information.'
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
    $(document).ajaxSuccess((event, xhr, options, data) => {
        if (typeof data['userContext'] === 'undefined') {
            return;
        }
        if (!data['userContext'].includes('ewaypayments.com')) {
            return;
        }
        if (CRM.eway.selectedToken == CRM.eway.updatedToken) {
            return;
        }
        window.open(data['userContext'], '_blank');
    })
});

// modify the templates
CRM.eway.modifyUpdateSubscriptionForm = function (elements = null) {
    CRM.$('.crm-recurcontrib-form-block > table tbody').append('<tr class="crm-contribution-form-block-receive_date">\n' +
        '                <td class="label">' + elements.next_scheduled_date.label + '</td>\n' +
        '                <td>' + elements.date_picker +
        '                <br/>\n' +
        '                    <span class="description">The next date on which this contribution will be made.</span>\n' +
        '                </td>\n' +
        '            </tr>');
};
