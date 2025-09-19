<?php
use Elementor\Base_Data_Control;

if ( ! defined( 'ABSPATH' ) ) exit;

class Tpae_Preset_Controller extends Base_Data_Control {
	
	public function get_type() {
		return 'tpae_preset_button';
	}

	public function get_default_settings() {
		return [
			'temp_id' => '',
		];
	}

	public function content_template() {
		?>
		<#
			var temp_id = data.temp_id || '';

		#>
		<?php
		echo '<div class="tpae-preset-main-raw-main">';
			echo '<a class="tp-preset-editor-raw" id="tp-preset-editor-raw" data-temp_id="{{temp_id}}" target="_blank" rel="noopener noreferrer">';
				echo esc_html__( 'Import Presets', 'tpebl' );
			echo '</a>';
		echo '</div>';
	}
}
