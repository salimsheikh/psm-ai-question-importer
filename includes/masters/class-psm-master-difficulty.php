<?php

class PSM_Master_Difficulty extends PSM_Master_Question_Type {

	public function __construct() {
		$this->entity = 'difficulty';
	}

	protected function get_search_placeholder() {
		return 'Search difficulty name';
	}

	protected function get_item_label() {
		return 'difficulties';
	}

	protected function get_table_name() {
		return PSM_AI_TABLE_PREFIX . 'difficulties';
	}
}
