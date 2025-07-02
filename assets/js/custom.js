jQuery(function ($) {
	let tree = new OaHelper.Tree('#tree', {
		onBtnRelation (cat_id, cat_rel_id){
			ModalRelation(cat_rel_id).then(item => tree.setRelationItem(cat_id, item));
		}
	});

	// Initialize tooltips
	$('[data-bs-toggle="tooltip"]').each(function () {
		new bootstrap.Tooltip(this);
	});

	$('#copyImport').on('click', () => {
		$('#inputImport').select();
		document.execCommand("copy");
	});

	$('#copyUp').on('click', () => {
		$('#inputUp').select();
		document.execCommand("copy");
	});

	$('#cf_opt_category_rel').on('click', function(){
		let el_value = $(this).find('input[type="hidden"]'),
			el_label = $(this).find('.oa-category-rel'),
			cat_rel_id = el_value.val();

		cat_rel_id = cat_rel_id ? parseInt(cat_rel_id) : null;

		ModalRelation(cat_rel_id).then(item => {
			el_value.val(item ? item.value : '');
			el_label.text(item ? item.lebelPath : '');
		});
	});


	function addAnimatedBar(classStr) {
		let lassArr = classStr.split(' ');

		lassArr.forEach(function (item, index, array) {
			let upAjaxTotal = document.getElementById('upAjaxTotal');

			if (upAjaxTotal && !upAjaxTotal.classList.contains(item)) {
				upAjaxTotal.classList.add(item);
			}

			let upAjaxStep = document.getElementById('upAjaxStep');

			if (upAjaxStep && !upAjaxStep.classList.contains(item)) {
				upAjaxStep.classList.add(item);
			}
		});
	}

	function removeAnimatedBar(classStr) {
		let lassArr = classStr.split(' ');

		lassArr.forEach(function (item, index, array) {
			let upAjaxTotal = document.getElementById('upAjaxTotal');

			if (upAjaxTotal && upAjaxTotal.classList.contains(item)) {
				upAjaxTotal.classList.remove(item);
			}

			let upAjaxStep = document.getElementById('upAjaxStep');

			if (upAjaxStep && upAjaxStep.classList.contains(item)) {
				upAjaxStep.classList.remove(item);
			}
		});
	}

	setTimeout(upAjaxProgressBar, 20000);

	// Up progress bar
	function upAjaxProgressBar() {
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {action: 'oasis_get_progress_bar'},
			dataType: 'json',
			success: function (data) {
				if (data) {
					$('#upAjaxStep').css('width', data.p_step + '%');
					$('#upAjaxStep').html(data.p_step + '%');

					$('#upAjaxTotal').css('width',  data.p_total + '%');
					$('#upAjaxTotal').html(data.p_total + '%');

					$('.oasis-process-icon').html(data.progress_icon);
					$('.oasis-process-text').html(data.step_text);

					if (data.is_process) {
						addAnimatedBar('progress-bar-striped progress-bar-animated');
						setTimeout(upAjaxProgressBar, 5000);
					} else {
						removeAnimatedBar('progress-bar-striped progress-bar-animated');
						setTimeout(upAjaxProgressBar, 60000);
					}
				} else {
					removeAnimatedBar('progress-bar-striped progress-bar-animated');
					setTimeout(upAjaxProgressBar, 600000);
				}
			},
			error: function (error) {
				setTimeout(upAjaxProgressBar, 60000);
			}
		});
	}




	function ModalRelation(cat_rel_id){
		return new Promise((resolve, reject) => {
			$.post(ajaxurl, {
				action: 'oasis_get_all_categories',
			}, tree_content => {
				let content = $('#oasis-relation').clone();
				content.find('.modal-body').html(tree_content);

				let btn_ok = content.find('.js-ok'),
					btn_clear = content.find('.js-clear'),
					modal = null,
					tree = new OaHelper.RadioTree(content.find('.oa-tree'), {
							onChange: item => {
								btn_ok.toggleClass('disabled', !item);
							}
						});

				tree.value = cat_rel_id;

				btn_ok.toggleClass('disabled', !tree.value);
				btn_clear.toggle(!!cat_rel_id);

				btn_ok.on('click', () => {
					let item = tree.item;
					if(item){
						modal.hide();
						resolve(item);
					}
				});
				btn_clear.on('click', () => {
					modal.hide();
					resolve(null);
				});

				modal = new bootstrap.Modal(content);
				modal.show();
			});
		});
	}
});