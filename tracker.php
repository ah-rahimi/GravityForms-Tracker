<?php
/*
Plugin Name: پلاگین پیگیری وضعیت سفارش
Plugin URI: https://zil.ink/am_ho_ra
Description: این پلاگین اجازه پیگیری وضعیت سفارش را بر اساس کد رهگیری را در اختیار کاربران میگذارد
Version: 1.0.1
Author: AmirHosein Rahimi
Author URI: https://zil.ink/am_ho_ra
*/
if (!defined('ABSPATH')) {
	exit;
}

class GravityForms_Tracking_by_Transaction_Id
{

	private static $input_id = 0;
	private static $ajax_script_fired = false;

	public function __construct()
	{

		add_action('widgets_init', array($this, 'register_widget'));
		add_shortcode('gform_tracker', array($this, 'gform_tracker_shortcode'));
		add_action('wp_ajax_nopriv_gform_tracking_result', array($this, 'gform_tracking_result'));
		add_action('wp_ajax_gform_tracking_result', array($this, 'gform_tracking_result'));

		if (is_admin()) {
			add_action('gform_entry_detail', array($this, 'set_status'), 10, 2);
			add_action('wp_ajax_hgft_merge_tage_var_ajax', array($this, 'hgft_merge_tage_var_ajax'));
		}
	}

	public function register_widget()
	{
		register_widget('GF_HANNANStd_Tracker');
	}

	public function set_status($form, $entry)
	{
		if (empty($entry["transaction_id"])) {
			return;
		}

		if (rgpost("new_status_submit")) {

			global $current_user;
			$user_id   = 0;
			$user_name = __('مهمان', 'GF_TR');
			if ($current_user && $user_data = get_userdata($current_user->ID)) {
				$user_id   = $current_user->ID;
				$user_name = $user_data->display_name;
			}

			if (rgpost("new_status") != gform_get_meta($entry["id"], "hannanstd_entry_status")) {
				RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__('وضعیت جدیدی برای این پیام ثبت شد : %s', 'GF_TR'), rgpost("new_status")));
			}

			gform_update_meta($entry["id"], "hannanstd_entry_status", rgpost("new_status"));
			gform_update_meta($entry["id"], "hannanstd_form_id", $form['id']);

			gform_delete_meta($entry["id"], "hannanstd_all_field");
			gform_delete_meta($entry["id"], "hannanstd_status_position");
		}


		add_action('media_buttons', array($this, 'merge_tags_media_buttons'), 0);

?>
		<div class="postbox">
			<h3>
				<label for="name"><?php echo sprintf(__('توضیحات وضعیت سفارش برای کد رهگیری %s', 'GF_TR'), $entry["transaction_id"]) ?></label>
			</h3>
			<div class="inside">
				<table cellspacing="0" class="widefat fixed entry-detail-statuses">
					<tbody class="list:comment" id="the-comment-list">
						<tr>
							<td class="lastrow" style="padding:10px;" colspan="3">


								<?php

								$editor_id = 'new_status';
								$settings  = array(
									'media_buttons'    => true,
									'textarea_rows'    => 10,
									'drag_drop_upload' => true
								);
								$content   = gform_get_meta($entry["id"], "hannanstd_entry_status");
								/*
							if (empty($content))
								$content = $this->settings('default_message', $form['id']);
							*/
								wp_editor($content, $editor_id, $settings);
								?>

								<br>

								<input type="submit" name="new_status_submit" style="width:auto;padding-bottom:2px;" class="button button-primary" value="<?php _e('ثبت وضعیت سفارش', 'GF_TR') ?>" />

							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	<?php
		$this->merge_tags_js(true, $form['id'], $entry['id']);
	}

	public function merge_tags_media_buttons()
	{
	?>
		<select id="new_status_variable_select" onchange="HGFT_InsertMegeTag( 'new_status' , 'variable_select', jQuery(this).val());" title="">
			<?php $form_meta = RGFormsModel::get_form_meta(rgget('id'));
			echo $this->get_form_fields_merge($form_meta); ?>
		</select>
		<span id="hgft_loading" style="width: 23px;display: inline-block;"></span>
	<?php
	}

	public function get_form_fields_merge($form)
	{

		$str = "<option value=''>" . __("Merge Tags", "gravityforms") . "</option>";

		if (empty($form)) {
			return $str;
		}

		$str .= "<option value='{all_fields}'>" . __("All Submitted Fields", "gravityforms") . "</option>";

		$required_fields = array();
		$optional_fields = array();
		$pricing_fields  = array();

		foreach ((array) $form["fields"] as $field) {

			if ($field["displayOnly"]) {
				continue;
			}

			$input_type = RGFormsModel::get_input_type($field);

			if ($field["isRequired"]) {

				switch ($input_type) {

					case "name":
						if ($field["nameFormat"] == "extended") {
							$prefix                   = GFCommon::get_input($field, $field["id"] + 0.2);
							$suffix                   = GFCommon::get_input($field, $field["id"] + 0.8);
							$optional_field           = $field;
							$optional_field["inputs"] = array($prefix, $suffix);
							$optional_fields[]        = $optional_field;
							unset($field["inputs"][0]);
							unset($field["inputs"][3]);
						}
						$required_fields[] = $field;
						break;

					default:
						$required_fields[] = $field;
				}
			} else {
				$optional_fields[] = $field;
			}

			if (GFCommon::is_pricing_field($field["type"])) {
				$pricing_fields[] = $field;
			}
		}

		if (!empty($required_fields)) {
			$str .= "<optgroup label='" . __("Required form fields", "gravityforms") . "'>";
			foreach ((array) $required_fields as $field) {
				$str .= $this->get_fields_options($field);
			}
			$str .= "</optgroup>";
		}

		if (!empty($optional_fields)) {
			$str .= "<optgroup label='" . __("Optional form fields", "gravityforms") . "'>";
			foreach ((array) $optional_fields as $field) {
				$str .= $this->get_fields_options($field);
			}
			$str .= "</optgroup>";
		}

		if (!empty($pricing_fields)) {
			$str .= "<optgroup label='" . __("Pricing form fields", "gravityforms") . "'>";
			foreach ((array) $pricing_fields as $field) {
				$str .= $this->get_fields_options($field);
			}
			$str .= "</optgroup>";
		}

		$str .= "<optgroup label='" . __("Other", "gravityforms") . "'>
					<option value='{ip}'>" . __("IP", "gravityforms") . "</option>
					<option value='{date_mdy}'>" . __("Date", "gravityforms") . " (mm/dd/yyyy)</option>
					<option value='{date_dmy}'>" . __("Date", "gravityforms") . " (dd/mm/yyyy)</option>
					<option value='{embed_post:ID}'>" . __("Embed Post/Page Id", "gravityforms") . "</option>
					<option value='{embed_post:post_title}'>" . __("Embed Post/Page Title", "gravityforms") . "</option>
					<option value='{embed_url}'>" . __("Embed URL", "gravityforms") . "</option>
					<option value='{entry_id}'>" . __("Entry Id", "gravityforms") . "</option>
					<option value='{entry_url}'>" . __("Entry URL", "gravityforms") . "</option>
					<option value='{form_id}'>" . __("Form Id", "gravityforms") . "</option>
					<option value='{form_title}'>" . __("Form Title", "gravityforms") . "</option>
					<option value='{user_agent}'>" . __("HTTP User Agent", "gravityforms") . "</option>";

		if (GFCommon::has_post_field($form["fields"])) {
			$str .= "<option value='{post_id}'>" . __("Post Id", "gravityforms") . "</option>
                    <option value='{post_edit_url}'>" . __("Post Edit URL", "gravityforms") . "</option>";
		}

		$str .= "<option value='{user:display_name}'>" . __("User Display Name", "gravityforms") . "</option>
				<option value='{user:user_email}'>" . __("User Email", "gravityforms") . "</option>
				<option value='{user:user_login}'>" . __("User Login", "gravityforms") . "</option>
			</optgroup>";
		$str .= "<optgroup label='" . __("Custom", "gravityforms") . "'>";

		if (class_exists('GFParsi')) {

			$str .= "<option value='{payment_gateway}'>" . __("Simple Payment Gateway", "GF_FA") . "</option>
				<option value='{payment_status}'>" . __("Simple Payment Status", "GF_FA") . "</option>
				<option value='{transaction_id}'>" . __("Simple Transaction ID", "GF_FA") . "</option>
				<option value='{payment_gateway_css}'>" . __("Styled Payment Gateway", "GF_FA") . "</option>
				<option value='{payment_status_css}'>" . __("Styled Payment Status", "GF_FA") . "</option>
				<option value='{transaction_id_css}'>" . __("Styled Transaction ID", "GF_FA") . "</option>
				<option value='{payment_pack}'>" . __("Styled Payment Pack", "GF_FA") . "</option>
				<option value='{rtl_start}'>" . __("Start of RTL Div", "GF_FA") . "</option>
				<option value='{rtl_end}'>" . __("End of RTL Div", "GF_FA") . "</option>";
		} else {
			$str .= "<option value='{payment_gateway}'>" . __("Payment Gateway", "gravityforms") . "</option>
				<option value='{payment_status}'>" . __("Payment Status", "gravityforms") . "</option>
				<option value='{transaction_id}'>" . __("Transaction Id", "gravityforms") . "</option>";
		}

		$str .= "</optgroup>";

		return $str;
	}

	private function get_fields_options($field, $max_label_size = 100)
	{
		$str = '';
		if (is_array($field["inputs"])) {
			foreach ((array) $field["inputs"] as $input) {
				$str .= "<option value='{" . esc_attr(GFCommon::get_label($field, $input["id"])) . ":" . $input["id"] . "}'>" . esc_html(GFCommon::truncate_middle(GFCommon::get_label($field, $input["id"]), $max_label_size)) . "</option>";
			}
		} else {
			$str .= "<option value='{" . esc_html(GFCommon::get_label($field)) . ":" . $field["id"] . "}'>" . esc_html(GFCommon::truncate_middle(GFCommon::get_label($field), $max_label_size)) . "</option>";
		}

		return $str;
	}

	public function merge_tags_js($ajax = false, $form_id = 0, $entry_id = 0)
	{ ?>
		<script type="text/javascript">
			function HGFT_InsertMegeTag(element_id, ex_id, variable) {
				ex_id = '_' + ex_id;
				<?php if ($ajax) : ?>
					jQuery("#hgft_loading").html('<img src="<?php echo esc_url(GFCommon::get_base_url()) ?>/images/spinner.gif" />');
					jQuery.ajax({
						url: "<?php echo admin_url('admin-ajax.php') ?>",
						type: "post",
						data: {
							action: "hgft_merge_tage_var_ajax",
							security: "<?php echo wp_create_nonce("hgft_entry_ajax"); ?>",
							form_id: "<?php echo $form_id ?>",
							entry_id: "<?php echo $entry_id ?>",
							variable: variable,
						},
						success: function(response) {
							jQuery("#hgft_loading").html('');
							variable = response;
							HGFT_InsertMegeTagValue(element_id, variable, ex_id);
						}
					});
				<?php else : ?>
					HGFT_InsertMegeTagValue(element_id, variable, ex_id);
				<?php endif; ?>
			}

			function HGFT_InsertMegeTagValue(element_id, variable, ex_id) {

				if (typeof(tinyMCE) != "undefined") {
					if (tinyMCE.get(element_id) != null && tinyMCE.get(element_id).isHidden() != true) {
						tinyMCE.get(element_id).execCommand('mceInsertContent', false, variable);
					}
				}

				var messageElement = jQuery("#" + element_id);
				if (document.selection) {
					messageElement[0].focus();
					document.selection.createRange().text = variable;
				} else if (messageElement[0].selectionStart) {
					obj = messageElement[0];
					obj.value = obj.value.substr(0, obj.selectionStart) + variable + obj.value.substr(obj.selectionEnd, obj.value.length);
				} else {
					messageElement.val(variable + messageElement.val());
				}
				jQuery('#' + element_id + ex_id)[0].selectedIndex = 0;
				/*if (window[callback])
				    window[callback].call();*/
			}
		</script>
	<?php
	}

	public function hgft_merge_tage_var_ajax()
	{
		check_ajax_referer('hgft_entry_ajax', 'security');

		$variable = isset($_POST['variable']) ? trim($_POST['variable']) : '';
		$form_id  = isset($_POST['form_id']) ? intval($_POST['form_id']) : rgget('id');
		$entry_id = isset($_POST['entry_id']) ? intval($_POST['entry_id']) : rgget('lid');

		if (ob_get_length()) {
			ob_clean();
		}

		if ($form_id && $entry_id) {

			$form  = RGFormsModel::get_form_meta($form_id);
			$entry = GFAPI::get_entry($entry_id);
			if (is_wp_error($entry)) {
				$entry = false;
			}

			echo GFCommon::replace_variables($variable, $form, $entry, false, true, false);
		} else {
			echo $variable;
		}

		die();
	}


	public function gform_tracker_shortcode()
	{

		add_action('wp_footer', array($this, 'ajax_script'), 9999);

		self::$input_id = self::$input_id + 1;

		$value = __('کد رهگیری را وارد نمایید', 'GF_TR');

		$content = '<input type="text" style="margin-bottom:12px;" id="gform_tr_id_' . self::$input_id . '" class="gform_tr_id" placeholder="' . $value . '" value="" />
			<input style="margin-bottom:16px;" id="gform_tr_submit_' . self::$input_id . '" class="button gform_tr_submit" type="submit" value=" ' . __('پیگیری', 'GF_TR') . ' " name="trackingsubmit"/>';

		return '<div class="tr_result" id="tr_result_' . self::$input_id . '">' . $content . '<div class="tr_result_response" id="tr_result_response_' . self::$input_id . '"></div></div>';
	}

	public function ajax_script()
	{

		if (self::$ajax_script_fired) {
			return;
		}
		self::$ajax_script_fired = true;
	?>
		<script type="text/javascript">
			jQuery(document).ready(function($) {
				$(document).on("click", ".gform_tr_submit", function() {
					var input_id = $(this).attr('id').replace('gform_tr_submit_', '');
					var transaction_id = $("#gform_tr_id_" + input_id).val();
					$("#tr_result_response_" + input_id).html("<?php _e('در حال بررسی...', 'GF_TR'); ?>");
					$.ajax({
						url: "<?php echo admin_url('admin-ajax.php'); ?>",
						type: "post",
						data: {
							action: "gform_tracking_result",
							security: "<?php echo wp_create_nonce("gform-tracking"); ?>",
							transaction_id: transaction_id,
						},
						success: function(response) {
							$("#tr_result_response_" + input_id).html(response);
						}
					});
					return false;
				});
			});
		</script>
	<?php
	}


	public function gform_tracking_result()
	{

		global $wpdb;
		check_ajax_referer('gform-tracking', 'security');
		$entry_id       = 0;
		$transaction_id = isset($_POST['transaction_id']) ? $_POST['transaction_id'] : '';


		if (!empty($transaction_id) && $transaction_id) {

			$version = GFCommon::$version;
			if (method_exists('GFFormsModel', 'get_database_version')) {
				$version = GFFormsModel::get_database_version();
			}
			if (version_compare($version, '2.3-dev-1', '>=')) {
				$type = 'entry';
			} else {
				$type = 'lead';
			}

			if ($type == 'entry' && method_exists('GFFormsModel', 'get_entry_table_name')) {
				$entry_table_name = GFFormsModel::get_entry_table_name();
			} else {
				$entry_table_name = GFFormsModel::get_lead_table_name();
			}

			$result = $wpdb->get_results($wpdb->prepare("SELECT id, form_id FROM {$entry_table_name} WHERE transaction_id=%s", $transaction_id), ARRAY_A);

			$count = sizeof($result);

			for ($i = 0; $i < $count; $i++) {

				$entry_id = !empty($result[$i]['id']) ? $result[$i]['id'] : '';
				$form_id  = !empty($result[$i]['form_id']) ? $result[$i]['form_id'] : '';

				if (!empty($entry_id) && $form_id == gform_get_meta($entry_id, "hannanstd_form_id")) {
					break;
				}
			}
		}

		if (!empty($entry_id) && $entry_id) {

			$entry = GFAPI::get_entry($entry_id);
			if (is_wp_error($entry)) {
				$entry = false;
			}

			$form_id = !empty($form_id) ? $form_id : rgget($entry, 'form_id');
			$form    = RGFormsModel::get_form_meta($form_id);

			$status_text = gform_get_meta($entry_id, "hannanstd_entry_status");
			$status_text = wptexturize(do_shortcode($status_text));

			$all_field = gform_get_meta($entry_id, "hannanstd_all_field");

			$variables_all_fields = GFCommon::replace_variables('{all_fields}', $form, $entry, false, true, false);

			if ($all_field == 'true') {

				$position             = gform_get_meta($entry_id, "hannanstd_status_position");

				if ($position == 'top') {
					echo $variables_all_fields . '/' . $status_text;
				} else {
					echo $status_text . $variables_all_fields;
				}
			} else if (!empty($status_text) && $status_text) {
				echo '<p>' . 'پیغام: ' . GFCommon::replace_variables($status_text, $form, $entry, false, true, false) . '</p>';
				echo $variables_all_fields;
			} else {
				echo $variables_all_fields;
			}
		} else {
			echo __('کد وارد شده در سیستم وجود ندارد .');
		}
		die();
	}
}

new GravityForms_Tracking_by_Transaction_Id();

class GF_HANNANStd_Tracker extends WP_Widget
{

	function __construct()
	{
		parent::__construct(
			'GF_HANNANStd_Tracker',
			__('پیگیری وضعیت سفارش', 'GF_TR'),
			array('description' => __('ابزارک پیگیری وضعیت سفارش بر اساس کد رهگیری', 'GF_TR'),)
		);
	}

	public function form($instance)
	{
		if (isset($instance['title'])) {
			$title = $instance['title'];
		} else {
			$title = __('پیگیری وضعیت سفارش', 'GF_TR');
		}
	?>
		<p>
			<label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($title); ?>" />
		</p>
<?php
	}

	public function widget($args, $instance)
	{
		$title = apply_filters('widget_title', $instance['title']);
		echo $args['before_widget'];
		if (!empty($title)) {
			echo $args['before_title'] . $title . $args['after_title'];
		}
		echo do_shortcode('[gform_tracker]');
		echo $args['after_widget'];
	}

	public function update($new_instance, $old_instance)
	{
		$instance          = array();
		$instance['title'] = (!empty($new_instance['title'])) ? strip_tags($new_instance['title']) : '';

		return $instance;
	}
}
