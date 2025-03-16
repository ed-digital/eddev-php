<?php

class EDAdminTables {

	public $columns = [];
	public $postType = null;
	public $perPage = null;
	public $defaultSort = null;

	public function __construct($postType, $columns, $perPage = null, $defaultSort = null) {
		$this->postType = $postType;
		$this->columns = $columns;
		$this->perPage = $perPage;
		$this->defaultSort = $defaultSort;
		if ($columns) {
			add_filter('manage_edit-' . $postType . '_columns', [$this, 'alterColumnLayout'], 16);
			add_action('manage_' . $postType . '_posts_custom_column', [$this, 'printColumn'], 16);
			add_filter('manage_edit-' . $postType . '_sortable_columns', [$this, 'sortableColumns']);
		}
		if ($defaultSort || $columns) {
			add_filter('pre_get_posts', [$this, 'applySorting']);
		}
		if ($perPage) {
			add_filter('edit_' . $postType . '_per_page', [$this, 'filterPerPage'], 10, 1);
		}
	}

	static function init() {
		add_action('admin_init', [__CLASS__, "initAdmin"]);
	}

	static function initAdmin() {
		$postTypes = get_post_types();

		foreach ($postTypes as $name => $label) {
			$object = get_post_type_object($name);
			$manager = new EDAdminTables(
				postType: $name,
				columns: $object->admin_columns ?? $object->adminColumns ?? [],
				perPage: $object->admin_per_paeg ?? $object->adminPerPage ?? null,
				defaultSort: $object->admin_sort ?? $object->adminSort ?? null
			);
		}
	}

	/**
	 * Override the default number of items per page in the admin
	 */
	public function filterPerPage($value) {
		// Only apply an updated value if the value is 20, which is WordPress' default
		// This allows the user to customize in page settings
		if ($value === 20) {
			return $this->perPage;
		}
		return $value;
	}

	public function alterColumnLayout($original) {

		// Create a pool of all the items
		$cols = [];

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
		foreach ($this->columns as $key => $def) {
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

		$output = [];

		foreach ($cols as $k => $col) {
			$output[$k] = $col['label'];
		}

		return $output;
	}

	public function sortableColumns($columns) {
		foreach ($this->columns as $key => $def) {
			if ($def && @$def['sortable']) {
				$columns[$key] = 'col_' . $key;
			}
		}
		return $columns;
	}

	public function printColumn($columnName, $ID = null) {
		global $post;
		if (isset($this->columns[$columnName])) {
			$colDef = $this->columns[$columnName];
			if ($colDef && @$colDef['render']) {
				$colDef['render']($post);
			}
		}
	}

	public function applySorting($query) {
		if (!is_admin()) {
			return;
		}

		if ($query->get('post_type') == $this->postType) {
			$orderby = $query->get('orderby');
			// Apply custom column-based sorting
			if ($orderby && is_string($orderby) && substr($orderby, 0, 4) == 'col_') {
				$column = substr($orderby, 4);
				if (isset($this->columns[$column])) {
					$def = $this->columns[$column];
					if ($def && isset($def['sortable'])) {
						foreach ($def['sortable'] as $key => $value) {
							$query->set($key, $value);
						}
					}
				}
			}
			if ($this->defaultSort) {
				$query->set('orderby', $this->defaultSort);
			}
		}
		return $query;
	}
}

EDAdminTables::init();
