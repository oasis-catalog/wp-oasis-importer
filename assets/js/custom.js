jQuery(function ($) {
	let tree = new OasisHelper.Tree('#tree', {
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
		jQuery(function ($) {
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {action: 'oasis_get_progress_bar'},
				dataType: 'json',
				success: function (response) {
					if (response) {
						if ('step_item' in response) {
							document.getElementById('upAjaxStep').style.width = response.step_item + '%';
							$('#upAjaxStep').html(response.step_item + '%');
						}

						if ('total_item' in response) {
							document.getElementById('upAjaxTotal').style.width = response.total_item + '%';
							$('#upAjaxTotal').html(response.total_item + '%');
						}

						if ('progress_icon' in response) {
							document.querySelector(".oasis-process-icon").innerHTML = response.progress_icon;
						}

						if ('progress_step_text' in response) {
							document.querySelector('.oasis-process-text').innerHTML = response.progress_step_text;
						}

						if ('status_progress' in response) {
							if (response.status_progress == true) {
								addAnimatedBar('progress-bar-striped progress-bar-animated');
								setTimeout(upAjaxProgressBar, 5000);
							} else {
								removeAnimatedBar('progress-bar-striped progress-bar-animated');
								setTimeout(upAjaxProgressBar, 60000);
							}
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
		});
	}




	function ModalRelation(cat_rel_id){
		return new Promise((resolve, reject) => {
			$.post(ajaxurl, {
				action: 'oasis_get_all_categories',
			}, tree_content => {
				let content = $('#oasis-relation');
				content.find('.modal-body').html(tree_content);

				let btn_ok = content.find('.js-ok'),
					btn_clear = content.find('.js-clear'),
					modal = null,
					tree = new OasisHelper.RadioTree(content.find('.oa-tree'), {
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


function test(){
	jQuery.post(ajaxurl, {
		action: 'oasis_save_options',
	});
}