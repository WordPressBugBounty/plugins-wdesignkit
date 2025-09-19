<?php

if ( ! defined( 'ABSPATH' ) ) exit;

use Elementor\Base_Data_Control;

class Tpae_Need_Help_Control extends Base_Data_Control {

	public function get_type() {
		return 'tpae_need_help';
	}

	public function content_template() {
		?>
		<#
			var defaults = [
				{ label: '<?php echo esc_js( __( 'Read Docs', 'tpebl' ) ); ?>',       url: 'https://theplusaddons.com/docs/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget' },
				{ label: '<?php echo esc_js( __( 'Watch Video', 'tpebl' ) ); ?>',     url: 'https://www.youtube.com/@posimyth?sub_confirmation=1' },
				{ label: '<?php echo esc_js( __( 'Request Feature', 'tpebl' ) ); ?>', url: 'https://roadmap.theplusaddons.com/boards/feature-request' },
				{ label: '<?php echo esc_js( __( 'Plugin Roadmap', 'tpebl' ) ); ?>',  url: 'https://roadmap.theplusaddons.com/roadmap' }
			];

			var buttons = [];
			for (var i = 0; i < 4; i++) {
				if (data.controlValue && data.controlValue[i] && (data.controlValue[i].label || data.controlValue[i].url)) {
					buttons[i] = {
						label: data.controlValue[i].label || defaults[i].label,
						url: data.controlValue[i].url || defaults[i].url
					};
				} else {
					buttons[i] = defaults[i];
				}
			}
		#>
		<?php

		echo '<div class="tpae-need-help">';
			echo '<div class="tpae-need-help-req-buttons">';
			?>
				<# for (var i = 0; i < buttons.length; i++) { #>
					<# if (buttons[i]) { #>
						<a class="tp-docs-link-container" href="{{ buttons[i].url }}" target="_blank" rel="noopener noreferrer">
							<div class="tp-docs-link">{{ buttons[i].label }}</div>
						</a>
					<# } #>
				<# } #>
			<?php
			echo '</div>';

			echo '<a class="tpae-ask-ai" href="https://theplusaddons.com/chat/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=links" target="_blank" rel="noopener noreferrer">';
				echo '<span>' . esc_html__('Ask AI', 'tpebl') . '</span>';
				echo '<svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7.5 5.25L7.11311 6.29554C6.6058 7.6665 6.35215 8.352 5.85209 8.8521C5.35202 9.35213 4.66653 9.60577 3.29554 10.1131L2.25 10.5L3.29554 10.8869C4.66653 11.3942 5.35202 11.6479 5.85209 12.1479C6.35215 12.648 6.6058 13.3335 7.11311 14.7044L7.5 15.75L7.88685 14.7044C8.39423 13.3335 8.64787 12.648 9.1479 12.1479C9.648 11.6479 10.3335 11.3942 11.7044 10.8869L12.75 10.5L11.7044 10.1131C10.3335 9.60577 9.648 9.35213 9.1479 8.8521C8.64787 8.352 8.39423 7.6665 7.88685 6.29554L7.5 5.25Z" stroke="black" stroke-width="1.125" stroke-linejoin="round"/><path d="M13.5 2.25L13.3342 2.69809C13.1168 3.28565 13.0081 3.57944 12.7937 3.79375C12.5795 4.00806 12.2857 4.11677 11.6981 4.33419L11.25 4.5L11.6981 4.66581C12.2857 4.88323 12.5795 4.99194 12.7937 5.20625C13.0081 5.42056 13.1168 5.71435 13.3342 6.30191L13.5 6.75L13.6658 6.30191C13.8832 5.71435 13.9919 5.42056 14.2063 5.20624C14.4205 4.99194 14.7143 4.88323 15.3019 4.66581L15.75 4.5L15.3019 4.33419C14.7143 4.11677 14.4205 4.00806 14.2063 3.79375C13.9919 3.57944 13.8832 3.28565 13.6658 2.69809L13.5 2.25Z" fill="black" stroke="black" stroke-width="1.125" stroke-linejoin="round"/></svg>';
			echo '</a>';

			echo '<div class="tpae-join-discord-container">';
				echo '<span class="tpae-join-discord-text">';
					echo esc_html__('Join our community to shape the future of', 'tpebl');
					echo '<span class="tpae-join-discord-text-bold">';
						echo esc_html__(' The Plus Addons For Elementor ', 'tpebl');
					echo '</span>';
					echo esc_html__('Get early access and exclusive perks!', 'tpebl');
					echo '<br>';
					echo '<a href="https://go.posimyth.com/plus-elementor-discord" target="_blank" rel="noopener noreferrer">';
						echo esc_html__('- Join Discord Now', 'tpebl');
					echo '</a>';
				echo '</span>';
			echo '</div>';
		echo '</div>';
	}
}
