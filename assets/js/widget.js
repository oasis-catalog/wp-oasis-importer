jQuery(function ($) {
	let widgets = $('.js--oasis-client-branding-widget');

	if(widgets.length){
		widgets.each(function() {
			let widget = $(this),
				locale = widget.attr('data-oasis-locale') || 'ru-RU',
				currency = widget.attr('data-oasis-currency') || 'RUB',
				oid = widget.attr('data-oasis-product-id'),
				param = { widget, locale, currency };

			if(oid) {
				changeVariation(param, {
					'product_id_oasis': oid
				});
			}
			else {
				$('form.variations_form').on('show_variation', (evt, variation) => changeVariation(param, variation));
				$('form.variations_form').on('hide_variation', (evt, variation) => changeVariation(param, null));
			}
		});

		function changeVariation(param, variation) {
			param.widget.empty();

			if (variation && variation.product_id_oasis) {
				OasisBrandigWidget('.js--oasis-client-branding-widget', {
					productId: variation.product_id_oasis,
					locale: param.locale,
					currency: param.currency
				});
			}
		}
	}
});