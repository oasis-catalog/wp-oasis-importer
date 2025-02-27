if(!OasisHelper){
	var OasisHelper = {};
}

(function ($) {
	let CLASS_HANDLE_P = 'oasis-tree-handle-p',
		CLASS_HANDLE_M = 'oasis-tree-handle-m',
		CLASS_TREE_NODE = 'oasis-tree-node',
		CLASS_TREE_LEAF = 'oasis-tree-leaf',
		CLASS_TREE_CHILDS = 'oasis-tree-childs',
		CLASS_COLLAPSED = 'oasis-tree-collapsed',

		CLASS_LABEL = 'oasis-tree-label',
		CLASS_LABEL_RELATION_ACTIVE = 'relation-active',

		CLASS_CTRL_M = 'oasis-tree-ctrl-m',
		CLASS_CTRL_P = 'oasis-tree-ctrl-p',

		CLASS_RELATION = 'oasis-tree-relation',
		CLASS_BTN_RELATION = 'oasis-tree-btn-relation',

		NAME_CAT = 'oasis_options[oasis_categories][]';
		NAME_RELATION = 'oasis_options[oasis_cat_relation][]';


	OasisHelper.Tree = class {
		el_root = null;

		constructor (el_root, p) {
			this.el_root = el_root = $(el_root);
			p = p || {};

			el_root.find('.' + CLASS_HANDLE_P).on('click', evt => {
				$(evt.target).closest('.' + CLASS_TREE_NODE).toggleClass(CLASS_COLLAPSED, false);
			});
			el_root.find('.' + CLASS_HANDLE_M).on('click', evt => {
				$(evt.target).closest('.' + CLASS_TREE_NODE).toggleClass(CLASS_COLLAPSED, true);
			});

			el_root.find('.' + CLASS_CTRL_M).on('click', () => {
				el_root.find('.' + CLASS_TREE_NODE).addClass(CLASS_COLLAPSED);
			});
			el_root.find('.' + CLASS_CTRL_P).on('click', () => {
				el_root.find('.' + CLASS_TREE_NODE).removeClass(CLASS_COLLAPSED);
			});

			el_root.find('input[type="checkbox"]').on('change', (evt) => {
				let tree_node = $(evt.target).closest(`.${CLASS_TREE_NODE}, .${CLASS_TREE_LEAF}`);
				this.checkCheckbox(el_root, tree_node, evt.target.checked);
			});

			el_root.find('.' + CLASS_TREE_LEAF).each((i, node) =>{
				this.checkStatusNode(el_root, $(node));
			});

			el_root.find('.' + CLASS_BTN_RELATION).on('click', (evt) => {
				evt.preventDefault();
				evt.stopPropagation();

				let tree_node = $(evt.target).closest(`.${CLASS_TREE_NODE}, .${CLASS_TREE_LEAF}`),
					cat_id = tree_node.find('[name="' + NAME_CAT + '"]').val(),
					val_rel = tree_node.find('[name="' + NAME_RELATION + '"]').val().split('_');

				let cat_rel_id = val_rel.length == 2 ? parseInt(val_rel[1]) : null;

				p.onBtnRelation && p.onBtnRelation.call(this, cat_id, cat_rel_id);
			});
		}

		checkCheckbox (el_root, tree_node, is_checked) {
			tree_node.find('input[type="checkbox"]').prop({
				checked: is_checked,
				indeterminate: false
			});		

			this.checkStatusNode(el_root, tree_node);
		}

		checkStatusNode (el_root, tree_node) {
			let parent_el = tree_node;
			while(true){
				parent_el = parent_el.parent();
				if(parent_el.is(el_root) || parent_el.length == 0){
					break;
				}
				if(parent_el.hasClass(CLASS_TREE_NODE)){
					let state = this.checkChildsStatus(parent_el),
						cb = parent_el.find(`.${CLASS_LABEL}:first input[type="checkbox"]`);

					switch(state){
						case 'indeterminate':
							cb.prop({
								checked: true,
								indeterminate: true
							});
							break;
						case 'checked':
							cb.prop({
								checked: true,
								indeterminate: false
							});
							break;
						case 'unchecked':
							cb.prop({
								checked: false,
								indeterminate: false
							});
							break;
					}
				}
			}
		}

		checkChildsStatus (tree_node) {
			let arr = [];
			tree_node.find(`.${CLASS_TREE_CHILDS}:first input[type="checkbox"]`).each(function(i, node) {
				if(node.indeterminate){
					arr.push('indeterminate');
				}
				else if(node.checked){
					arr.push('checked');
				}
				else{
					arr.push('unchecked');
				}
			});
			return arr.includes('indeterminate') ? 'indeterminate' : 
					arr.includes('unchecked') ?
						(arr.includes('checked') ? 'indeterminate' : 'unchecked') :
						'checked';
		}

		setRelationItem (cat_id, item) {
			this.el_root.find('[name="' + NAME_CAT + '"]').each((i, inp_node) => {
				if(inp_node.value == cat_id){
					let tree_node = $(inp_node).closest(`.${CLASS_TREE_NODE}, .${CLASS_TREE_LEAF}`),
						el_label = tree_node.find('.' + CLASS_LABEL + ':first');

					el_label.find('[name="' + NAME_RELATION + '"]').val(cat_id + '_' + (item ? item.value : ''));
					el_label.find('.' + CLASS_RELATION).text(item ? item.lebelPath : '');

					tree_node.find('.' + CLASS_LABEL + ':first').toggleClass(CLASS_LABEL_RELATION_ACTIVE, !!item);

					return false;
				}
			});
		}
	};


	OasisHelper.RadioTree = class {
		el_root = null;

		constructor (el_root, p){
			this.el_root = el_root = $(el_root);
			p = p || {};

			el_root.find('.' + CLASS_HANDLE_P).on('click', (evt) => {
				$(evt.target).closest('.' + CLASS_TREE_NODE).toggleClass(CLASS_COLLAPSED, false);
			});
			el_root.find('.' + CLASS_HANDLE_M).on('click', (evt) => {
				$(evt.target).closest('.' + CLASS_TREE_NODE).toggleClass(CLASS_COLLAPSED, true);
			});

			el_root.find('.' + CLASS_CTRL_M).on('click', () => {
				el_root.find('.' + CLASS_TREE_NODE).addClass(CLASS_COLLAPSED);
			});
			el_root.find('.' + CLASS_CTRL_P).on('click', () => {
				el_root.find('.' + CLASS_TREE_NODE).removeClass(CLASS_COLLAPSED);
			});

			el_root.find('input[type="radio"]').on('change', (evt) => {
				if(evt.target.checked && p.onChange){
					p.onChange.call(this, {
						value: evt.target.value,
						lebelPath: this.getTreePath($(evt.target))
					});
				}
			});
		}

		getTreePath (input_el){
			let result = '',
				parent_el = input_el;
			while(true){
				parent_el = parent_el.parent();
				if(parent_el.is(this.el_root) || parent_el.length == 0){
					break;
				}
				if(parent_el.hasClass(CLASS_TREE_NODE) || parent_el.hasClass(CLASS_TREE_LEAF)){
					result = parent_el.find('label:first').text() + (result.length > 0 ?  ' / ' : '') + result;
				}
			}
			return result;
		}

		get value() {
			let radio_checked = this.el_root.find('input[type="radio"]:checked');
			if(radio_checked.length > 0){
				return radio_checked.val();
			}
			return null;
		}

		get item() {
			let radio_checked = this.el_root.find('input[type="radio"]:checked');
			if(radio_checked.length > 0){
				return {
					value: radio_checked.val(),
					lebelPath: this.getTreePath(radio_checked)
				};
			}

			return null;
		}

		set value(v) {
			this.el_root.find('[type="radio"]').each(function(){
				if(this.value == v){
					this.checked = true;
					return false;
				}
			});
		}
	}
})(jQuery);