<?php
namespace OasisImport;

use OasisImport\Main;
use OasisImport\Cli;
use OasisImport\Api;


class Config {
	public const IMG_SIZE_THUMBNAIL = 	[80, 60];
	public const IMG_SIZE_SMALL = 		[220, 165];
	public const IMG_SIZE_BIG = 		[640, 480];
	public const IMG_SIZE_SUPERBIG =	[1000, 750];

	public bool $is_debug = false;
	public bool $is_debug_log = false;
	public string $upload_path;

	public string $api_key;
	public string $api_user_id;

	public string $currency;

	public array $categories;
	public array $categories_rel;
	private array $categories_easy;
	public ?int $category_rel;
	public string $category_rel_label;

	public array $progress;
	private bool $is_progress = false;

	public array $currencies;

	public bool $is_not_up_cat;
	public bool $is_import_anytime;

	public ?int $limit;

	public ?\DateTime $import_date;

	public ?float $price_factor;
	public ?float $price_increase;
	public bool $is_price_dealer;

	public bool $is_no_vat;
	public bool $is_not_on_order;
	public ?float $price_from;
	public ?float $price_to;
	public ?int $rating;
	public bool $is_wh_moscow;
	public bool $is_wh_europe;
	public bool $is_wh_remote;

	public bool $is_comments;
	public bool $is_brands;
	public bool $is_disable_sales;
	public bool $is_branding;
	public bool $is_up_photo;
	public bool $is_cdn_photo;
	public bool $is_fast_import;

	private bool $is_init = false;
	private bool $is_init_rel = false;

	private static $instance;

	public static function instance($opt = []) {
		if (!isset(self::$instance)) {
			self::$instance = new self($opt);
		} else {
			if(!empty($opt['init'])){
				self::$instance->init();
			}
			if(!empty($opt['init_rel'])){
				self::$instance->initRelation();
			}
			if(!empty($opt['load_currencies'])){
				self::$instance->loadCurrencies();
			}
		}

		return self::$instance;
	}

	public function __construct($opt = []) {
		$upload_dir = wp_upload_dir();
		$this->upload_path = $upload_dir['basedir'] . '/wp-oasis-importer';

		$this->is_debug = !empty($opt['debug']);
		$this->is_debug_log = !empty($opt['debug_log']);

		Cli::$cf = $this;
		Main::$cf = $this;
		Api::$cf = $this;

		if(!empty($opt['init'])){
			$this->init();
		}
		if(!empty($opt['init_rel'])){
			$this->initRelation();
		}
		if(!empty($opt['load_currencies'])){
			$this->loadCurrencies();
		}
	}

	public function init() {
		if($this->is_init) {
			return;
		}

		$this->progress = get_option('oasis_progress', [
			'item' => 0,			// count updated products
			'total' => 0,			// count all products
			'step' => 0,			// step (for limit)
			'step_item' => 0,		// count updated products for step
			'step_total' => 0,		// count step total products
			'date' => '',			// date end import
			'date_step' => ''		// date end import for step
		]);

		$opt = get_option('oasis_options', []);

		$this->api_key =		$opt['api_key'] ?? '';
		$this->api_user_id =	$opt['api_user_id'] ?? '';
		$this->currency =		$opt['currency'] ?? 'rub';
		$this->limit =			!empty($opt['limit']) ? intval($opt['limit']) : null;

		$this->categories =		$opt['categories'] ?? [];

		$cat_rel = $opt['cat_relation'] ?? [];
		$this->categories_rel = [];
		foreach($cat_rel as $rel){
			$rel = 	explode('_', $rel);
			$cat_id = (int)$rel[0];
			$rel_id = (int)$rel[1];

			$this->categories_rel[$cat_id] = [
				'id' =>  $rel_id,
				'rel_label' => null
			];
		}
		
		$this->price_factor =			!empty($opt['price_factor']) ? floatval(str_replace(',', '.', $opt['price_factor'])) : null;
		$this->price_increase =			!empty($opt['price_increase']) ? floatval(str_replace(',', '.', $opt['price_increase'])) : null;
		$this->is_price_dealer =		!empty($opt['is_price_dealer']);

		$this->is_import_anytime =		!empty($opt['is_import_anytime']);
		$dt = null;
		if(!empty($this->progress['date'])){
			$dt = \DateTime::createFromFormat('d.m.Y H:i:s', $this->progress['date']);
		}
		$this->import_date = $dt;

		$this->category_rel = 			!empty($opt['category_rel']) ? intval($opt['category_rel']) : null;
		$this->category_rel_label = 	'';
		$this->is_not_up_cat =			!empty($opt['is_not_up_cat']);

		$this->is_no_vat =				!empty($opt['is_no_vat']);
		$this->is_not_on_order =		!empty($opt['is_not_on_order']);
		$this->price_from =				!empty($opt['price_from']) ? floatval(str_replace(',', '.', $opt['price_from'])) : null;
		$this->price_to =				!empty($opt['price_to']) ? floatval(str_replace(',', '.', $opt['price_to'])) : null;
		$this->rating =					!empty($opt['rating']) ? intval($opt['rating']) : null;
		$this->is_wh_moscow =			!empty($opt['is_wh_moscow']);
		$this->is_wh_europe =			!empty($opt['is_wh_europe']);
		$this->is_wh_remote =			!empty($opt['is_wh_remote']);
		$this->is_comments =			!empty($opt['is_comments']);
		$this->is_brands =				!empty($opt['is_brands']);
		$this->is_disable_sales =		!empty($opt['is_disable_sales']);
		$this->is_branding =			!empty($opt['is_branding']);
		$this->is_up_photo =			!empty($opt['is_up_photo']);
		$this->is_cdn_photo =			!empty($opt['is_cdn_photo']);
		$this->is_fast_import =			!empty($opt['is_fast_import']);

		$this->is_init = true;
	}

	public function initRelation() {
		if($this->is_init_rel){
			return;
		}

		foreach($this->categories_rel as $cat_id => $rel){
			$this->categories_rel[$cat_id]['rel_label'] = $this->getRelLabel($rel['id']);
		}
		if(isset($this->category_rel)){
			$this->category_rel_label = $this->getRelLabel($this->category_rel);
		}

		$this->is_init_rel = true;
	}

	private function getRelLabel(int $rel_id) {
		$parents = get_ancestors($rel_id, 'product_cat', 'taxonomy');
		array_unshift($parents, $rel_id);

		$list = [];
		foreach (array_reverse($parents) as $term_id) {
			$parent = get_term($term_id, 'product_cat');
			$list []= $parent->name;
		}
		return implode(' / ', $list);
	}

	public function progressOn() {
		$this->is_progress = true;
	}

	public function progressStart(int $total, int $step_total) {
		if (!$this->is_progress) {
			return;
		}

		$this->progress['total'] = $total;
		$this->progress['step_total'] = $step_total;
		$this->progress['step_item'] = 0;
		update_option('oasis_progress', $this->progress);
	}

	public function progressUp() {
		if (!$this->is_progress) {
			return;
		}

		$this->progress['step_item']++;
		update_option('oasis_progress', $this->progress);
	}

	public function progressEnd() {
		if (!$this->is_progress) {
			return;
		}

		$dt = (new \DateTime())->format('d.m.Y H:i:s');
		$this->progress['date_step'] = $dt;

		$is_stop_fast_import = false;
		if($this->limit > 0){
			$this->progress['item'] += $this->progress['step_item'];

			if(($this->limit * ($this->progress['step'] + 1)) > $this->progress['total']){
				$this->progress['step'] = 0;
				$this->progress['item'] = 0;
				$this->progress['date'] = $dt;
				$is_stop_fast_import = true;
			}
			else{
				$this->progress['step']++;
			}
		}
		else{
			$this->progress['item'] = 0;
			$this->progress['date'] = $dt;
			$is_stop_fast_import = true;
		}

		$this->progress['step_item'] = 0;
		$this->progress['step_total'] = 0;

		if($this->is_fast_import && $is_stop_fast_import){
			$this->is_fast_import = false;
			$_opt = get_option('oasis_options', []);
			$_opt['is_fast_import'] = false;
			update_option('oasis_options', $_opt);
		}

		update_option('oasis_progress', $this->progress);
	}

	public function progressClear() {
		$this->progress['total'] = 0;
		$this->progress['step'] = 0;
		$this->progress['item'] = 0;
		$this->progress['step_item'] = 0;
		$this->progress['step_total'] = 0;
		$this->progress['date'] = '';
		$this->progress['date_step'] = '';
		update_option('oasis_progress', $this->progress);
	}

	public function getOptBar() {
		$is_process = $this->checkLockProcess();

		$opt = $this->progress;
		$p_total = 0;
		$p_step = 0;

		if (!empty($opt['step_item']) && !empty($opt['step_total'])) {
			$p_step = round(($opt['step_item'] / $opt['step_total']) * 100, 2, PHP_ROUND_HALF_DOWN );
			$p_step = min($p_step, 100);
		}

		if (!(empty($opt['item']) && empty($opt['step_item'])) && !empty($opt['total'])) {
			$p_total = round((($opt['item'] + $opt['step_item']) / $opt['total']) * 100, 2, PHP_ROUND_HALF_DOWN );
			$p_total = min($p_total, 100);
		}

		return [
			'is_process' =>	$is_process,
			'p_total' =>	$p_total,
			'p_step' =>		$p_step,
			'step' =>		$opt['step'] ?? 0,
			'steps' =>		($this->limit > 0 && !empty($opt['total'])) ? (ceil($opt['total'] / $this->limit)) : 0,
			'date' =>		$opt['date_step'] ?? ''
		];
	}

	public function checkCronKey(string $cron_key): bool {
		return $cron_key === md5($this->api_key);
	}

	public function getCronKey(): string {
		return md5($this->api_key);
	}

	public function checkApi(): bool {
		return !empty(Api::getCurrenciesOasis(false));
	}

	public function lock($fn, $fn_error) {
		$lock = fopen($this->upload_path . '/start.lock', 'w');
		if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
			$fn();
		}
		else{
			$fn_error();
		}
	}

	public function checkLockProcess(): bool {
		$lock = fopen($this->upload_path . '/start.lock', 'w');
		if (!($lock && flock( $lock, LOCK_EX | LOCK_NB ))) {
			return true;
		}
		return false;
	}

	public function checkPermissionImport(): bool {
		if(!$this->is_import_anytime && 
			$this->import_date &&
			$this->import_date->format("Y-m-d") == (new \DateTime())->format("Y-m-d")){
				return false;
		}
		return true;
	}

	public function log($str) {
		if ($this->is_debug || $this->is_debug_log) {
			$str = date('H:i:s').' '.$str;

			if ($this->is_debug_log) {
				file_put_contents($this->upload_path . '/oasis_'.date('Y-m-d').'.log', $str . "\n", FILE_APPEND);
			} else {
				echo $str . PHP_EOL;
			}
		}
	}

	public function deleteLogFile() {
		$filePath = $this->upload_path . '/oasis.log';
		if (file_exists($filePath)) {
			unlink($filePath);
		}
	}

	public function getRelCategoryId($oasis_cat_id) {
		if(isset($this->categories_rel[$oasis_cat_id])){
			return $this->categories_rel[$oasis_cat_id]['id'];
		}
		if(isset($this->category_rel)){
			return $this->category_rel;
		}
		return null;
	}

	public function activate() {
		if (!is_dir($this->upload_path)) {
			if(!wp_mkdir_p($this->upload_path)){
				die('Failed to create directories: ' . $this->upload_path);
			}
		}
	}

	public function loadCurrencies(): bool {
		$data = Api::getCurrenciesOasis(false);
		if(empty($data))
			return false;

		$currencies = [];
		foreach ($data as $currency) {
			$currencies[] = [
				'code' => $currency->code,
				'name' => $currency->full_name
			];
		}
		$this->currencies = $currencies;
		return true;
	}

	public function deactivate() {
		delete_option('oasis_options');
		delete_option('oasis_progress');
		$this->rmdDir($this->upload_path);
	}

	private function rmdDir($dir) {
		foreach (glob( $dir . '/*') as $file) {
			if (is_dir($file)){
				self::rmdDir($file);
			} else {
				unlink($file);
			}
		}
		rmdir($dir);
	}
}