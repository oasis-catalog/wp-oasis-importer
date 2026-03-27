jQuery(function ($) {
	let tree = new OaHelper.Tree('#oasis_import_cat_tree', {
		onBtnRelation (cat_id, cat_rel_id){
			ModalRelation(cat_rel_id).then(item => tree.setRelationItem(cat_id, item));
		}
	});

	// Initialize tooltips
	$('[data-bs-toggle="tooltip"]').each(function () {
		new bootstrap.Tooltip(this);
	});

	$('#oasis_import_copy_run').on('click', () => {
		$('#oasis_import_inp_run').select();
		document.execCommand("copy");
	});

	$('#oasis_import_copy_up').on('click', () => {
		$('#oasis_import_inp_up').select();
		document.execCommand("copy");
	});

	$('#oasis_import_category_rel').on('click', function(){
		let el_value = $(this).find('input[type="hidden"]'),
			el_label = $(this).find('.oa-category-rel'),
			cat_rel_id = el_value.val();

		cat_rel_id = cat_rel_id ? parseInt(cat_rel_id) : null;

		ModalRelation(cat_rel_id).then(item => {
			el_value.val(item ? item.value : '');
			el_label.text(item ? item.lebelPath : '');
		});
	});

	$('#oasis_import_btn_run').on('click', function(){
		$.get(ajaxurl, {
			action: 'oasis_import_run'
		});
	});
	$('#oasis_import_btn_up').on('click', function(){
		$.get(ajaxurl, {
			action: 'oasis_import_up'
		});
	});
	let i_debbug = 0;
	$('.js-notice').on('click', function() {
		i_debbug++;
		if (i_debbug > 6) {
			$('#oasis_import_btn_run').show()
			$('#oasis_import_btn_up').show()
		}
	});

	function UpProgressBar() {
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {action: 'oasis_get_progress_bar'},
			dataType: 'json',
			success: function (data) {
				$('#oasis_import_bar_step').css('width', data.p_step + '%');
				$('#oasis_import_bar_step').html(data.p_step + '%');

				$('#oasis_import_bar_total').css('width',  data.p_total + '%');
				$('#oasis_import_bar_total').html(data.p_total + '%');

				$('.oasis-process-icon').html(data.progress_icon);
				$('.oasis-process-text').html(data.step_text);

				if (data.is_process) {
					$('#oasis_import_bar_total').addClass(['progress-bar-striped', 'progress-bar-animated']);
					$('#oasis_import_bar_step').addClass(['progress-bar-striped', 'progress-bar-animated']);
				}
				else {
					$('#oasis_import_bar_total').removeClass(['progress-bar-striped', 'progress-bar-animated']);
					$('#oasis_import_bar_step').removeClass(['progress-bar-striped', 'progress-bar-animated']);
				}
			},
			error: function (error) {
				$('#oasis_import_bar_total').removeClass(['progress-bar-striped', 'progress-bar-animated']);
				$('#oasis_import_bar_step').removeClass(['progress-bar-striped', 'progress-bar-animated']);
			}
		});
	}
	setInterval(() => UpProgressBar(), 20000);
	
	function ModalRelation(cat_rel_id) {
		return new Promise((resolve, reject) => {
			$.post(ajaxurl, {
				action: 'oasis_get_all_categories',
			}, tree_content => {
				let content = $('#oasis_import_m_relation').clone();
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