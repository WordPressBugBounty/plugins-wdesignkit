<?php
/**
 * Exit if accessed directly.
 *
 * @link       https://posimyth.com/
 * @since      1.0.2
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes/gutenberg
 * */

/**
 * Exit if accessed directly.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Gutenberg_Files_Load' ) ) {

	/**
	 * This class used for only gutenberg widget load
	 *
	 * @since 1.0.2
	 */
	class Wdkit_Gutenberg_Files_Load {

		/**
		 * Instance
		 *
		 * @since 1.0.2
		 * @var The single instance of the class.
		 */
		private static $instance = null;

		/**
		 * Instance
		 *
		 * Ensures only one instance of the class is loaded or can be loaded.
		 *
		 * @since 1.0.2
		 * @return instance of the class.
		 */
		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Perform some compatibility checks to make sure basic requirements are meet.
		 *
		 * @since 1.0.2
		 */
		public function __construct() {
			add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) );
			$this->wdkit_register_gutenberg_widgets();

			add_filter( 'render_block', [ $this, 'render_block' ], 1000, 2 );
		}

		public function tpgb_validate_block_instance( $block, $block_content ) {

            if ( empty( $block['attrs']['block_id'] ) ) {
                return false;
            }

            $prefix = substr( sanitize_key( $block['attrs']['block_id'] ), 0, 5 );

            if ( strpos( $block_content, 'tpgb-block-' . $prefix ) === false ) {
                return false;
            }

            return $prefix;
        }

        public function render_block( $block_content, $block ) {

            $attrs = $block['attrs'] ?? [];
        
            // Heading Block
            if ( isset( $block['blockName'] ) && $block['blockName'] === 'tpgb/tp-heading' ) {
        
                if ( ! $this->tpgb_validate_block_instance( $block, $block_content ) ) {
                    return $block_content;
                }
        
                $new_title = ! empty( $attrs['exTitle'] ) ? $attrs['exTitle'] : '';
                $t_tag     = ! empty( $attrs['tTag'] ) ? $attrs['tTag'] : 'h3';

                if ( ! empty( $new_title ) ) {

                    $pattern = '/<' . preg_quote( $t_tag, '/' ) . '([^>]*)>(.*?)<\/' . preg_quote( $t_tag, '/' ) . '>/is';

                    $block_content = preg_replace_callback(
                        $pattern,
                        function ( $matches ) use ( $t_tag, $new_title ) {
                            return '<' . $t_tag . $matches[1] . '>' . wp_kses_post( $new_title ) . '</' . $t_tag . '>';
                        },
                        $block_content
                    );
                }
            }
        
            // Pro Paragraph Block
            if ( isset( $block['blockName'] ) && $block['blockName'] === 'tpgb/tp-pro-paragraph' ) {
        
                if ( ! $this->tpgb_validate_block_instance( $block, $block_content ) ) {
                    return $block_content;
                }
        
                $title   = ! empty( $attrs['exTitle'] ) ? $attrs['exTitle'] : '';
                $content = ! empty( $attrs['exproCnt'] ) ? $attrs['exproCnt'] : '';
        
                $title_tag = ! empty( $attrs['titleTag'] ) ? $attrs['titleTag'] : 'h3';
                $desc_tag  = ! empty( $attrs['descTag'] ) ? $attrs['descTag'] : 'p';
        
                if ( ! empty( $title ) ) {
                    $block_content = preg_replace(
                        '/<' . preg_quote( $title_tag, '/' ) . '([^>]*)class="([^"]*pro-heading-inner[^"]*)"([^>]*)>.*?<\/' . preg_quote( $title_tag, '/' ) . '>/is',
                        '<' . $title_tag . '$1class="$2"$3>' . wp_kses_post( $title ) . '</' . $title_tag . '>',
                        $block_content
                    );
                }
        
                if ( ! empty( $content ) ) {
                    $block_content = preg_replace(
                        '/<div([^>]*)class="([^"]*pro-paragraph-inner[^"]*)"([^>]*)>.*?<\/div>/is',
                        '<div$1class="$2"$3><' . $desc_tag . '>' . wp_kses_post( $content ) . '</' . $desc_tag . '></div>',
                        $block_content
                    );
                }
            }
        
            // Button Block
            if ( isset( $block['blockName'] ) && $block['blockName'] === 'tpgb/tp-button-core' ) {

                if ( ! $this->tpgb_validate_block_instance( $block, $block_content ) ) {
                    return $block_content;
                }
            
                $btnLink = ! empty( $attrs['bLink']['url'] ) ? esc_url( $attrs['bLink']['url'] ) : '';
                $btn_text = ! empty( $attrs['exbtxt'] ) ? $attrs['exbtxt'] : '';
            
                // Replace button text
                if ( ! empty( $btn_text ) ) {
                    $block_content = preg_replace(
                        '/<span([^>]*)class="([^"]*tpgb-btn-txt[^"]*)"([^>]*)>.*?<\/span>/is',
                        '<span$1class="$2"$3>' . wp_kses_post( $btn_text ) . '</span>',
                        $block_content
                    );
                }
            
                // Replace href in <a> tag having tpgb-btn-link class
                if ( ! empty( $btnLink ) ) {
                    $block_content = preg_replace_callback(
                        '/<a\b([^>]*)class="([^"]*tpgb-btn-link[^"]*)"([^>]*)>/is',
                        function ( $matches ) use ( $btnLink ) {
                
                            $attrs = $matches[1] . 'class="' . $matches[2] . '"' . $matches[3];
                
                            // If href exists → replace it
                            if ( preg_match( '/href="[^"]*"/i', $attrs ) ) {
                                $attrs = preg_replace(
                                    '/href="[^"]*"/i',
                                    'href="' . esc_url( $btnLink ) . '"',
                                    $attrs
                                );
                            } 
                            // If href does NOT exist → add it
                            else {
                                $attrs .= ' href="' . esc_url( $btnLink ) . '"';
                            }
                
                            return '<a' . $attrs . '>';
                        },
                        $block_content
                    );
                }
            }


            // Accordion Block
            if ( isset( $block['blockName'] ) && $block['blockName'] === 'tpgb/tp-accordion' ) {
        
                if ( empty( $attrs['accordianList'] ) ) {
                    return $block_content;
                }
        
                if ( ! $this->tpgb_validate_block_instance( $block, $block_content ) ) {
                    return $block_content;
                }
        
                $block_content = $this->tpgb_safe_replace_accordion_items(
                    $block_content,
                    $attrs['accordianList']
                );
            }
            // Image Block
            if ( isset( $block['blockName'] ) && $block['blockName'] === 'tpgb/tp-image' && ! empty( $attrs['tImg']['url'] ) ) {

                if ( strpos( $attrs['tImg']['url'], 'tpgb-dynamicurl=tImg!#' ) !== false ) {
					return $block_content;
				}

                if ( ! $this->tpgb_validate_block_instance( $block, $block_content ) ) {
                    return $block_content;
                }
        
                $img_url = esc_url( $attrs['tImg']['url'] );
        
                $block_content = preg_replace(
                    '/(<img(?=[^>]*class="[^"]*tpgb-img-inner[^"]*")[^>]*\s+src=")[^"]*(")/i',
                    '$1' . $img_url . '$2',
                    $block_content,
                    1
                );
            }
        
            return $block_content;
        }


        public function tpgb_safe_replace_accordion_items( $block_content, $accordianList ) {

            // Match EACH accordion item safely
            preg_match_all(
                '/<div class="tpgb-accor-item\b[\s\S]*?<\/div>\s*<\/div>/i',
                $block_content,
                $matches
            );
        
            if ( empty( $matches[0] ) ) {
                return $block_content;
            }
        
            foreach ( $matches[0] as $index => $item_html ) {
        
                if ( empty( $accordianList[ $index ] ) ) {
                    continue;
                }
        
                $item = $accordianList[ $index ];
        
                // Replace TITLE inside THIS item only
                if ( ! empty( $item['title'] ) ) {
                    $item_html = preg_replace(
                        '/(<h[1-6][^>]*class="[^"]*accordion-title[^"]*"[^>]*>)(.*?)(<\/h[1-6]>)/i',
                        '$1' . wp_kses_post( $item['title'] ) . '$3',
                        $item_html,
                        1
                    );
                }
        
                // Replace DESCRIPTION inside THIS item only
                if ( ! empty( $item['desc'] ) ) {
                    $item_html = preg_replace(
                        '/(<div[^>]*class="[^"]*tpgb-content-editor[^"]*"[^>]*>)([\s\S]*?)(<\/div>)/i',
                        '$1' . wp_kses_post( $item['desc'] ) . '$3',
                        $item_html,
                        1
                    );
                }
        
                // Replace the original item with updated one
                $block_content = str_replace(
                    $matches[0][ $index ],
                    $item_html,
                    $block_content
                );
            }
        
            return $block_content;
        }
		/**
		 * Load Gutenburg Builder js and css for controller.
		 *
		 * @since 1.0.2
		 */
		public function editor_assets() {

			$wp_localize_tpgb = array(
				'category'  => 'tpgb',
				'admin_url' => esc_url( admin_url() ),
				'home_url'  => home_url(),
				'ajax_url'  => esc_url( admin_url( 'admin-ajax.php' ) ),
			);

			global $pagenow;
			$scripts_dep = array( 'wp-blocks', 'wp-i18n', 'wp-plugins', 'wp-element', 'wp-components', 'wp-api-fetch', 'media-upload', 'media-editor' );
			if ( 'widgets.php' !== $pagenow && 'customize.php' !== $pagenow ) {
				$scripts_dep = array_merge( $scripts_dep, array( 'wp-editor', 'wp-edit-post' ) );
				wp_enqueue_script( 'wkit-editor-block-pmgc', WDKIT_URL . '/assets/js/main/gutenberg/wkit_g_pmgc.js', $scripts_dep, WDKIT_VERSION, false );
				wp_localize_script( 'wkit-editor-block-pmgc', 'wdkit_blocks_load', $wp_localize_tpgb );
			}
		}

		/**
		 * Here is Register Gutenberg Widgets
		 *
		 * @since 1.0.2
		 */
		public function wdkit_register_gutenberg_widgets() {
			$dir = trailingslashit( WDKIT_BUILDER_PATH ) . '/gutenberg/';

			if ( ! is_dir( $dir ) ) {
				return false;
			}

			$list = ! empty( $dir ) ? scandir( $dir ) : array();
			if ( empty( $list ) || count( $list ) <= 2 ) {
				return false;
			}

			$get_db_widget = get_option( 'wkit_deactivate_widgets', [] );
			$server_w_unique = array_column($get_db_widget, 'w_unique');

			foreach ( $list as $key => $value ) {
				if ( in_array( $value, array( '..', '.' ), true ) ) {
					continue;
				}

				if (! is_dir( trailingslashit( $dir ) . $value ) ){
					return false;
				}

				if ( ! strpos( $value, '.' ) ) {
					$sub_dir = scandir( trailingslashit( $dir ) . '/' . $value );

					foreach ( $sub_dir as $sub_dir_value ) {
						if ( in_array( $sub_dir_value, array( '..', '.' ), true ) ) {
							continue;
						}

						$file      = new SplFileInfo( $sub_dir_value );
						$check_ext = $file->getExtension();
						$ext       = pathinfo( $sub_dir_value, PATHINFO_EXTENSION );

						if ( 'php' === $ext ) {
							$json_file   = str_replace( '.php', '.json', $sub_dir_value );
							$str_replace = str_replace( '.php', '', $sub_dir_value );

							$json_path = trailingslashit( WDKIT_BUILDER_PATH ) . "/gutenberg/{$value}/{$json_file}";
							$json_data = wp_json_file_decode( $json_path );

							$w_type = ! empty( $json_data->widget_data->widgetdata->publish_type ) ? $json_data->widget_data->widgetdata->publish_type : '';
							$widget_id = ! empty( $json_data->widget_data->widgetdata->widget_id ) ? $json_data->widget_data->widgetdata->widget_id : '';
							if ( ! empty( $w_type ) && 'Publish' === $w_type ) {
								if( ! in_array( $widget_id , $server_w_unique ) ){	
									include trailingslashit( WDKIT_BUILDER_PATH ) . "/gutenberg/{$value}/{$sub_dir_value}";
								}
							}
						}
					}
				}
			}
		}
	}

	add_filter( 'block_categories_all', 'wdkit_register_block_category', 9999992, 1 );

	/**
	 * Gutenberg block category for The Plus Addon.
	 *
	 * @since 1.0.2
	 *
	 * @param array $categories Block categories.
	 */
	function wdkit_register_block_category( $categories ) {
		$category_list  = get_option( 'wkit_builder' );
		$new_categories = array();

		foreach ( $category_list as $value ) {
			$new_categories[] = array(
				'slug'  => $value,
				'title' => esc_html( $value ),
			);
		}

		return array_merge( $new_categories, $categories );
	}

	Wdkit_Gutenberg_Files_Load::instance();
}
