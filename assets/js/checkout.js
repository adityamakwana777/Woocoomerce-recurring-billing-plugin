/* global jQuery, Stripe, wrbData */
jQuery(function ($) {
	'use strict';

	var stripe = null;
	var card = null;
	var busy = false;

	function mountCard() {
		var el = document.getElementById('wrb-card-element');
		// WooCommerce re-renders the payment box on every checkout update; only mount into a fresh element.
		if (!el || el.querySelector('iframe')) {
			return;
		}
		if (!stripe) {
			stripe = Stripe(wrbData.publishableKey);
		}
		card = stripe.elements().create('card', { hidePostalCode: true });
		card.mount(el);
		card.on('change', function (event) {
			$('#wrb-card-errors').text(event.error ? event.error.message : '');
		});
	}

	$(document.body).on('updated_checkout', mountCard);
	mountCard();

	// Runs when the customer clicks "Place order" with our gateway selected.
	$('form.checkout').on('checkout_place_order_wrb_stripe', function () {
		var $form = $(this);

		// Second pass: the PaymentMethod id is already in the hidden field, let WooCommerce submit.
		if ($('#wrb_payment_method').val()) {
			return true;
		}
		if (busy || !card) {
			return false;
		}

		busy = true;
		$form.block({ message: null, overlayCSS: { background: '#fff', opacity: 0.6 } });

		stripe
			.createPaymentMethod({
				type: 'card',
				card: card,
				billing_details: {
					name: ($('#billing_first_name').val() + ' ' + $('#billing_last_name').val()).trim(),
					email: $('#billing_email').val()
				}
			})
			.then(function (result) {
				busy = false;
				$form.unblock();

				if (result.error) {
					$('#wrb-card-errors').text(result.error.message);
					return;
				}
				$('#wrb_payment_method').val(result.paymentMethod.id);
				$form.trigger('submit'); // WooCommerce handles the real submit from here.
			});

		return false; // stop this pass; the promise above re-submits.
	});

	// A PaymentMethod can only be used once, so clear it if the order fails validation.
	$(document.body).on('checkout_error', function () {
		$('#wrb_payment_method').val('');
	});
});
