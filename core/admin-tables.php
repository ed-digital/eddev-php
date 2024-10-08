<?php

class EDColumnManager {

	public $columnDefs = array();
	public $postType = null;

	public function __construct($postType, $defs) {
		$this->postType = $postType;
		$this->columnDefs = $defs;
	}

	public function alterColumnLayout($original) {

		// Create a pool of all the items
		$cols = array();

		// Add original items first
		$index = 0;
		foreach ($original as $key => $title) {
			$cols[$key] = array(
				"label" => $title,
				"order" => $index++
			);
		}

		// Add custom columns (and delete any originals if a def is null)
		$index = 0;
		foreach ($this->columnDefs as $key => $def) {
			$index++;
			if (!$def) {
				unset($cols[$key]);
			} else {
				$cols[$key] = $def;
				if (!isset($cols[$key]['order'])) {
					$cols[$key]['order'] = $index;
				}
			}
		}

		uasort($cols, function ($a, $b) {
			return $a['order'] - $b['order'];
		});

		$output = array();

		foreach ($cols as $k => $col) {
			$output[$k] = $col['label'];
		}

		return $output;
	}

	public function printColumn($columnName, $ID = null) {
		global $post;
		if (isset($this->columnDefs[$columnName])) {
			$colDef = $this->columnDefs[$columnName];
			if ($colDef && @$colDef['render']) {
				$colDef['render']($post);
			}
		}
	}
}
