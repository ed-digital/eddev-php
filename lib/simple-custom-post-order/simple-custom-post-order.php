<?php

/**
 * Plugin Name: Simple Custom Post Order
 * Plugin URI: https://wordpress.org/plugins-wp/simple-custom-post-order/
 * Description: Order Items (Posts, Pages, and Custom Post Types) using a Drag and Drop Sortable JavaScript.
 * Version: 2.5.10
 * Author: Colorlib
 * Author URI: https://colorlib.com/
 * Tested up to: 6.7
 * Requires: 6.2 or higher
 * License: GPLv3 or later
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP: 7.2.5
 * Text Domain: simple-custom-post-order
 * Domain Path: /languages
 *
 * Copyright 2013-2017 Sameer Humagain im@hsameer.com.np
 * Copyright 2017-2023 Colorlib support@colorlib.com
 *
 * SVN commit with ownership change: https://plugins.trac.wordpress.org/changeset/1590135/simple-custom-post-order
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 3, as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */


define('SCPORDER_URL', str_replace(ED()->themePath, ED()->themeURL, __DIR__));
define('SCPORDER_VERSION', '2.5.8');

$scporder = new ED_SCPO_Engine();

class ED_SCPO_Engine {

	function __construct() {
		if (! get_option('scporder_install')) {
			$this->scporder_install();
		}

		add_action('admin_init', array($this, 'refresh'));

		add_action('admin_init', array($this, 'load_script_css'));

		add_action('wp_ajax_update-menu-order', array($this, 'update_menu_order'));
		add_action('wp_ajax_update-menu-order-tags', array($this, 'update_menu_order_tags'));

		add_action('pre_get_posts', array($this, 'scporder_pre_get_posts'));

		add_filter('get_previous_post_where', array($this, 'scporder_previous_post_where'));
		add_filter('get_previous_post_sort', array($this, 'scporder_previous_post_sort'));
		add_filter('get_next_post_where', array($this, 'scporder_next_post_where'));
		add_filter('get_next_post_sort', array($this, 'scporder_next_post_sort'));

		add_filter('get_terms_orderby', array($this, 'scporder_get_terms_orderby'), 10, 3);
		add_filter('wp_get_object_terms', array($this, 'scporder_get_object_terms'), 10, 3);
		add_filter('get_terms', array($this, 'scporder_get_object_terms'), 10, 3);

		add_filter('scpo_post_types_args', array($this, 'scpo_filter_post_types'), 10, 2);

		$this->scporder_install();
	}

	public function scpo_filter_post_types($args, $options) {

		if (isset($options['show_advanced_view']) && '1' == $options['show_advanced_view']) {
			unset($args['show_in_menu']);
		}

		return $args;
	}

	public function scporder_install() {
		global $wpdb;
		$result = $wpdb->query("DESCRIBE $wpdb->terms `term_order`");
		if (! $result) {
			$query  = "ALTER TABLE $wpdb->terms ADD `term_order` INT( 4 ) NULL DEFAULT '0'";
			$result = $wpdb->query($query);
		}
		update_option('scporder_install', 1);
	}

	public function _check_load_script_css() {

		$active = false;

		$objects = $this->get_scporder_options_objects();
		$tags    = $this->get_scporder_options_tags();

		if (empty($objects) && empty($tags)) {
			return false;
		}

		if (isset($_GET['orderby']) || strstr($_SERVER['REQUEST_URI'], 'action=edit') || strstr($_SERVER['REQUEST_URI'], 'wp-admin/post-new.php')) {
			return false;
		}

		if (! empty($objects)) {
			if (isset($_GET['post_type']) && ! isset($_GET['taxonomy']) && in_array($_GET['post_type'], $objects)) { // if page or custom post types
				$active = true;
			}
			if (! isset($_GET['post_type']) && strstr($_SERVER['REQUEST_URI'], 'wp-admin/edit.php') && in_array('post', $objects)) { // if post
				$active = true;
			}
		}

		if (! empty($tags)) {
			if (isset($_GET['taxonomy']) && in_array($_GET['taxonomy'], $tags)) {
				$active = true;
			}
		}

		return $active;
	}

	//TODO corrigé
	public function load_script_css() {
		if ($this->_check_load_script_css()) {
			wp_enqueue_script('jquery');
			wp_enqueue_script('jquery-ui-sortable');
			wp_enqueue_script('scporderjs', SCPORDER_URL . '/assets/scporder.min.js', array('jquery'), SCPORDER_VERSION, true);
			wp_localize_script('scporderjs', 'scporder_vars', array(
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce'    => wp_create_nonce('scporder_nonce_action'),
			));
			add_action('admin_print_styles', array($this, 'print_scpo_style'));
		}
	}

	//TODO corrigé
	public function refresh() {

		if (scporder_doing_ajax()) {
			return;
		}

		global $wpdb;
		$objects = $this->get_scporder_options_objects();
		$tags    = $this->get_scporder_options_tags();

		if (! empty($objects)) {

			foreach ($objects as $object) {
				$query = $wpdb->prepare(
					"
					SELECT COUNT(*) AS cnt, MAX(menu_order) AS max, MIN(menu_order) AS min
					FROM $wpdb->posts
					WHERE post_type = %s AND post_status IN ('publish', 'pending', 'draft', 'private', 'future')
					",
					$object
				);

				$result = $wpdb->get_results($query); // à corriger

				if ($result[0]->cnt == 0 || $result[0]->cnt == $result[0]->max) {
					continue;
				}

				// Here's the optimization
				$wpdb->query('SET @row_number = 0;');
				$wpdb->query(
					"UPDATE $wpdb->posts as pt JOIN (

                  SELECT ID, (@row_number:=@row_number + 1) AS `rank`
                  FROM $wpdb->posts
                  WHERE post_type = '$object' AND post_status IN ( 'publish', 'pending', 'draft', 'private', 'future' )
                  ORDER BY menu_order ASC
                ) as pt2
                ON pt.id = pt2.id
                SET pt.menu_order = pt2.`rank`;"
				);
			}
		}

		if (! empty($tags)) {
			foreach ($tags as $taxonomy) {
				$query = $wpdb->prepare(
					"
					SELECT COUNT(*) AS cnt, MAX(term_order) AS max, MIN(term_order) AS min
					FROM $wpdb->terms AS terms
					INNER JOIN $wpdb->term_taxonomy AS term_taxonomy ON ( terms.term_id = term_taxonomy.term_id )
					WHERE term_taxonomy.taxonomy = %s
					",
					$taxonomy
				);
				$result = $wpdb->get_results($query); //Passage en requette préparée
				if ($result[0]->cnt == 0 || $result[0]->cnt == $result[0]->max) {
					continue;
				}

				$query = $wpdb->prepare(
					"
					SELECT terms.term_id
					FROM $wpdb->terms AS terms
					INNER JOIN $wpdb->term_taxonomy AS term_taxonomy ON ( terms.term_id = term_taxonomy.term_id )
					WHERE term_taxonomy.taxonomy = %s
					ORDER BY term_order ASC
					",
					$taxonomy
				);

				$results = $wpdb->get_results($query); //Passage en requette préparée
				foreach ($results as $key => $result) {
					$wpdb->update($wpdb->terms, array('term_order' => $key + 1), array('term_id' => $result->term_id));
				}
			}
		}
	}

	//TODO corrigé
	public function update_menu_order() {
		global $wpdb;

		check_ajax_referer('scporder_nonce_action', 'nonce');

		if (! current_user_can('edit_posts')) {
			return;
		}

		parse_str($_POST['order'], $data);

		if (! is_array($data)) {
			return false;
		}

		$index = $data['post'];

		print_r($index);

		foreach ($index as $index => $id) {
			$wpdb->update(
				$wpdb->posts,
				array('menu_order' => (int)$index),
				array('ID' => (int)$id)
			);
			print_r($wpdb->last_query);
		}

		wp_cache_flush();

		do_action('scp_update_menu_order');
	}


	public function update_menu_order_tags() {
		global $wpdb;

		check_ajax_referer('scporder_nonce_action', 'nonce');

		if (! current_user_can('edit_posts')) {
			return;
		}

		parse_str($_POST['order'], $data);

		if (! is_array($data)) {
			return false;
		}

		$id_arr = array();
		foreach ($data as $key => $values) {
			foreach ($values as $position => $id) {
				$id_arr[] = $id;
			}
		}

		$menu_order_arr = array();
		foreach ($id_arr as $key => $id) {
			$id = intval($id); // Nettoyage variable ID
			$results = $wpdb->get_results($wpdb->prepare("SELECT term_order FROM $wpdb->terms WHERE term_id = %d", $id)); // Passage en requette préparée
			foreach ($results as $result) {
				$menu_order_arr[] = $result->term_order;
			}
		}
		sort($menu_order_arr);

		foreach ($data as $key => $values) {
			foreach ($values as $position => $id) {
				$id = intval($id); // Nettoyage variable ID
				$wpdb->update(
					$wpdb->terms,
					array('term_order' => $menu_order_arr[$position]),
					array('term_id' => $id),
					array('%d'),
					array('%d')
				); // Passage en requette préparée
			}
		}

		wp_cache_flush();

		do_action('scp_update_menu_order_tags');
	}

	public function scporder_previous_post_where($where) {
		global $post;

		$objects = $this->get_scporder_options_objects();
		if (empty($objects)) {
			return $where;
		}

		if (isset($post->post_type) && in_array($post->post_type, $objects)) {
			$where = preg_replace("/p.post_date < \'[0-9\-\s\:]+\'/i", "p.menu_order > '" . $post->menu_order . "'", $where);
		}
		return $where;
	}

	public function scporder_previous_post_sort($orderby) {
		global $post;

		$objects = $this->get_scporder_options_objects();
		if (empty($objects)) {
			return $orderby;
		}

		if (isset($post->post_type) && in_array($post->post_type, $objects)) {
			$orderby = 'ORDER BY p.menu_order ASC LIMIT 1';
		}
		return $orderby;
	}

	public function scporder_next_post_where($where) {
		global $post;

		$objects = $this->get_scporder_options_objects();
		if (empty($objects)) {
			return $where;
		}

		if (isset($post->post_type) && in_array($post->post_type, $objects)) {
			$where = preg_replace("/p.post_date > \'[0-9\-\s\:]+\'/i", "p.menu_order < '" . $post->menu_order . "'", $where);
		}
		return $where;
	}

	public function scporder_next_post_sort($orderby) {
		global $post;

		$objects = $this->get_scporder_options_objects();
		if (empty($objects)) {
			return $orderby;
		}

		if (isset($post->post_type) && in_array($post->post_type, $objects)) {
			$orderby = 'ORDER BY p.menu_order DESC LIMIT 1';
		}
		return $orderby;
	}

	public function scporder_pre_get_posts($wp_query) {
		$objects = $this->get_scporder_options_objects();

		if (empty($objects)) {
			return false;
		}

		if (is_admin() && ! wp_doing_ajax()) {

			if (isset($wp_query->query['post_type']) && ! isset($_GET['orderby'])) {
				if (in_array($wp_query->query['post_type'], $objects)) {
					if (! $wp_query->get('orderby')) {
						$wp_query->set('orderby', 'menu_order');
					}
					if (! $wp_query->get('order')) {
						$wp_query->set('order', 'ASC');
					}
				}
			}
		} else {

			$active = false;

			if (isset($wp_query->query['post_type'])) {
				if (! is_array($wp_query->query['post_type'])) {
					if (in_array($wp_query->query['post_type'], $objects)) {
						$active = true;
					}
				}
			} else {
				if (in_array('post', $objects)) {
					$active = true;
				}
			}

			if (! $active) {
				return false;
			}

			if (isset($wp_query->query['suppress_filters'])) {
				if ($wp_query->get('orderby') == 'date') {
					$wp_query->set('orderby', 'menu_order');
				}
				if ($wp_query->get('order') == 'DESC') {
					$wp_query->set('order', 'ASC');
				}
			} else {
				if (! $wp_query->get('orderby')) {
					$wp_query->set('orderby', 'menu_order');
				}
				if (! $wp_query->get('order')) {
					$wp_query->set('order', 'ASC');
				}
			}
		}
	}


	public function scporder_get_terms_orderby($orderby, $args) {

		if (is_admin() && ! wp_doing_ajax()) {
			return $orderby;
		}

		$tags = $this->get_scporder_options_tags();

		if (! isset($args['taxonomy'])) {
			return $orderby;
		}

		if (is_array($args['taxonomy'])) {
			if (isset($args['taxonomy'][0])) {
				$taxonomy = $args['taxonomy'][0];
			} else {
				$taxonomy = false;
			}
		} else {
			$taxonomy = $args['taxonomy'];
		}

		if (! in_array($taxonomy, $tags)) {
			return $orderby;
		}

		$orderby = 't.term_order';
		return $orderby;
	}

	public function scporder_get_object_terms($terms) {
		$tags = $this->get_scporder_options_tags();

		if (is_admin() && ! wp_doing_ajax() && isset($_GET['orderby'])) {
			return $terms;
		}

		foreach ($terms as $key => $term) {
			if (is_object($term) && isset($term->taxonomy)) {
				$taxonomy = $term->taxonomy;
				if (! in_array($taxonomy, $tags, true)) {
					return $terms;
				}
			} else {
				return $terms;
			}
		}

		if (is_array($terms)) {
			usort($terms, array($this, 'taxcmp'));
		}
		return $terms;
	}


	public function taxcmp($a, $b) {
		if ($a->term_order == $b->term_order) {
			return 0;
		}
		return ($a->term_order < $b->term_order) ? -1 : 1;
	}

	// Return post types which have sortable => true defined when registering post type
	public function get_scporder_options_objects() {
		return array_keys(array_filter(get_post_types([], 'objects'), function ($object) {
			return @($object->sortable || $object->admin_sortable || $object->adminSortable);
		}));
	}

	// Return taxonomies which have sortable => true defined when registering taxonomy
	public function get_scporder_options_tags() {
		return array_keys(array_filter(get_taxonomies([], 'objects'), function ($object) {
			return @($object->sortable || $object->admin_sortable || $object->adminSortable);
		}));
	}

	/**
	 * Print inline admin style
	 *
	 * @since 2.5.4
	 */


	public function print_scpo_style() {
?>
		<style>
			.ui-sortable tr:hover {
				cursor: move;
			}

			.ui-sortable tr.alternate {
				background-color: #F9F9F9;
			}

			.ui-sortable tr.ui-sortable-helper {
				background-color: #F9F9F9;
				border-top: 1px solid #DFDFDF;
			}
		</style>
<?php
	}
}

function scporder_doing_ajax() {

	if (function_exists('wp_doing_ajax')) {
		return wp_doing_ajax();
	}

	if (defined('DOING_AJAX') && DOING_AJAX) {
		return true;
	}

	return false;
}
