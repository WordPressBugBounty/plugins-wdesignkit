<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WDKIT_Block_Processor class
 *
 * @package WDKIT
 * @subpackage WDKIT_Block_Processor
 * @since 2.0.0
 */
class WDKIT_Nexter_Block_Processor {

    /**
     * Run the block processor
     *
     * @param array $blocks
     * @return array
     */
	public function run( $blocks ) {
		return $this->process_blocks( $blocks );
	}

    /**
     * Process blocks
     *
     * @param array $blocks
     * @return array
     */
	private function process_blocks( $blocks ) {

		foreach ( $blocks as &$block ) {

			$name  = $block['blockName'] ?? '';
			$attrs = $block['attrs'] ?? [];

			switch ( $name ) {

				case 'tpgb/tp-accordion':
					$block = $this->process_accordion( $block, $attrs );
					break;
                
                case 'tpgb/tp-accordion-inner':
                    $block = $this->process_accordion_inner( $block, $attrs );
                    break;

				case 'tpgb/tp-blockquote':
					$block = $this->process_blockquote( $block, $attrs );
					break;

				case 'tpgb/tp-heading':
					$block = $this->process_heading( $block, $attrs );
					break;

				case 'tpgb/tp-pro-paragraph':
					$block = $this->process_pro_paragraph( $block, $attrs );
					break;

				case 'tpgb/tp-button-core':
					$block = $this->process_button_core( $block, $attrs );
					break;

				case 'tpgb/tp-image':
					$block = $this->process_image( $block, $attrs );
					break;

				case 'tpgb/tp-tabs-tours':
					$block = $this->process_tabs_tours( $block, $attrs );
					break;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->process_blocks( $block['innerBlocks'] );
			}
		}

		return $blocks;
	}

    /**
     * Process accordion block
     *
     * @param array $block
     * @param array $attrs
     * @return array
     */
    private function process_accordion( $block, $attrs ) {

        if ( empty( $attrs['accordianList'] ) || empty( $block['innerHTML'] ) ) {
            return $block;
        }
        $is_editor = isset( $attrs['accorType'] ) && $attrs['accorType'] === 'editor';

        if ( $is_editor ) {
            return $block;
        }

        libxml_use_internal_errors(true);
    
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $block['innerHTML']);
        $xpath = new DOMXPath($dom);
    
        $items = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " tpgb-accor-item ")]'
        );
    
        foreach ($items as $index => $item) {
    
            if (!isset($attrs['accordianList'][$index])) {
                continue;
            }
    
            $title = wp_kses_post($attrs['accordianList'][$index]['title'] ?? '');
            $desc  = wp_kses_post($attrs['accordianList'][$index]['desc'] ?? '');
    
            // Update title (ANY TAG with accordion-title class)
            $titleNode = $xpath->query(
                './/*[contains(concat(" ", normalize-space(@class), " "), " accordion-title ")]',
                $item
            )->item(0);
    
            if ($titleNode) {
                $titleNode->nodeValue = $title;
            }
    
            // Update description
            $descNode = $xpath->query(
                './/*[contains(concat(" ", normalize-space(@class), " "), " tpgb-content-editor ")]',
                $item
            )->item(0);
    
            if ($descNode) {
                $descNode->nodeValue = $desc;
            }
        }
    
        $new_html = $dom->saveHTML($dom->getElementsByTagName('body')->item(0));
        $new_html = preg_replace('/^<body>|<\/body>$/', '', $new_html);
    
        $block['innerHTML']       = $new_html;
        $block['innerContent'][0] = $new_html;
    
        return $block;
    }			

    private function process_accordion_inner( $block, $attrs ) {

        if ( empty( $block['innerContent'] ) ) {
            return $block;
        }
    
        $is_editor = isset( $attrs['contentType'] ) && $attrs['contentType'] === 'editor';
    
        foreach ( $block['innerContent'] as $i => $chunk ) {
    
            if ( $chunk === null ) {
                continue;
            }
            
            // Update title
            if ( ! empty( $attrs['title'] ) && strpos( $chunk, 'accordion-title' ) !== false ) {
                $chunk = preg_replace_callback(
                    '/(<([a-z][a-z0-9]*)\b[^>]*?class="[^"]*\baccordion-title\b[^"]*"[^>]*?>)([^<]*)(<\/\2>)/si',
                    function( $m ) use ( $attrs ) {
                        return $m[1] . esc_html( $attrs['title'] ) . $m[4];
                    },
                    $chunk,
                    1
                );
            }
    
            // Skip desc replacement entirely when contentType = editor
            // because tpgb-content-editor contains inner blocks split across innerContent
            if ( ! $is_editor && ! empty( $attrs['desc'] ) && strpos( $chunk, 'tpgb-content-editor' ) !== false ) {
                $chunk = preg_replace(
                    '/(<div\b[^>]*class="[^"]*\btpgb-content-editor\b[^"]*"[^>]*>)([\s\S]*?)(<\/div>)/i',
                    '$1' . wp_kses_post( $attrs['desc'] ) . '$3',
                    $chunk,
                    1
                );
            }
    
            $block['innerContent'][ $i ] = $chunk;
        }
    
        return $block;
    }

    /**
     * Process blockquote block
     *
     * @param array $block
     * @param array $attrs
     * @return array
     */
    private function process_blockquote( $block, $attrs ) {

        if ( empty( $block['innerHTML'] ) ) {
            return $block;
        }
    
        $content = wp_kses_post( $attrs['content'] ?? '' );
        $author  = wp_kses_post( $attrs['authorName'] ?? '' );
    
        $html = $block['innerHTML'];
    
        // Update quote text
        if ( $content ) {
    
            $html = preg_replace(
                '/(<span[^>]*class="[^"]*quote-text[^"]*"[^>]*>)([\s\S]*?)(<\/span>)/',
                '$1' . $content . '$3',
                $html,
                1
            );
    
            $block['attrs']['content'] = $content;
        }
    
        // Update author name
        if ( $author ) {
    
            $html = preg_replace(
                '/(<div[^>]*class="[^"]*tpgb-quote-author[^"]*"[^>]*>)([\s\S]*?)(<\/div>)/',
                '$1' . $author . '$3',
                $html,
                1
            );
    
            $block['attrs']['authorName'] = $author;
        }
    
        $block['innerHTML']       = $html;
        $block['innerContent'][0] = $html;
    
        return $block;
    }	

    /**
     * Process heading block
     *
     * @param array $block
     * @param array $attrs
     * @return array
     */
    private function process_heading( $block, $attrs ) {

        // Take exTitle and map it to title
        if ( empty( $attrs['exTitle'] ) ) {
            return $block;
        }
    
        $new_title = wp_kses_post( $attrs['exTitle'] );
    
        if ( empty( $block['innerHTML'] ) ) {
            return $block;
        }
    
        libxml_use_internal_errors(true);
    
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $block['innerHTML']);
        $xpath = new DOMXPath($dom);
    
        // Find element having tp-core-heading class
        $heading_node = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " tp-core-heading ")]'
        )->item(0);
    
        if ( $heading_node ) {
            $heading_node->nodeValue = $new_title;
        }
    
        // Clean HTML (remove body wrapper)
        $new_html = $dom->saveHTML($dom->getElementsByTagName('body')->item(0));
        $new_html = preg_replace('/^<body>|<\/body>$/', '', $new_html);
    
        // Update block HTML
        $block['innerHTML']       = $new_html;
        $block['innerContent'][0] = $new_html;
    
        // Also update attribute for editor sync
        $block['attrs']['title']   = $new_title;
        $block['attrs']['exTitle'] = $new_title;
    
        return $block;
    }

    /**
     * Process pro paragraph block
     *
     * @param array $block
     * @param array $attrs
     * @return array
     */
    private function process_pro_paragraph( $block, $attrs ) {

        if ( empty( $block['innerHTML'] ) ) {
            return $block;
        }
    
        $new_title   = ! empty( $attrs['exTitle'] )   ? wp_kses_post( $attrs['exTitle'] )   : '';
        $new_content = ! empty( $attrs['exproCnt'] )  ? wp_kses_post( $attrs['exproCnt'] )  : '';
    
        $title_tag = ! empty( $attrs['titleTag'] ) ? strtolower( $attrs['titleTag'] ) : 'h3';
        $desc_tag  = ! empty( $attrs['descTag'] )  ? strtolower( $attrs['descTag'] )  : 'p';
    
        libxml_use_internal_errors(true);
    
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $block['innerHTML']);
        $xpath = new DOMXPath($dom);
    
        /*
        |--------------------------------------------------------------------------
        | 🔹 Update Title
        |--------------------------------------------------------------------------
        */
        if ( $new_title ) {
    
            $title_node = $xpath->query(
                '//*[contains(concat(" ", normalize-space(@class), " "), " pro-heading-inner ")]'
            )->item(0);
    
            if ( $title_node ) {
    
                // If tag is different, replace tag but keep class
                if ( strtolower($title_node->nodeName) !== $title_tag ) {
    
                    $new_node = $dom->createElement($title_tag);
                    $new_node->setAttribute('class', $title_node->getAttribute('class'));
                    $new_node->nodeValue = $new_title;
    
                    $title_node->parentNode->replaceChild($new_node, $title_node);
    
                } else {
                    $title_node->nodeValue = $new_title;
                }
            }
    
            $block['attrs']['title']   = $new_title;
            $block['attrs']['exTitle'] = $new_title;
        }
    
        /*
        |--------------------------------------------------------------------------
        | 🔹 Update Description
        |--------------------------------------------------------------------------
        */
        if ( $new_content ) {
    
            $desc_node = $xpath->query(
                '//*[contains(concat(" ", normalize-space(@class), " "), " pro-paragraph-inner ")]/*'
            )->item(0);
    
            if ( $desc_node ) {
    
                if ( strtolower($desc_node->nodeName) !== $desc_tag ) {
    
                    $new_node = $dom->createElement($desc_tag);
                    $new_node->nodeValue = $new_content;
    
                    $desc_node->parentNode->replaceChild($new_node, $desc_node);
    
                } else {
                    $desc_node->nodeValue = $new_content;
                }
            }
    
            $block['attrs']['content']  = $new_content;
            $block['attrs']['exproCnt'] = $new_content;
        }
    
        /*
        |--------------------------------------------------------------------------
        | 🔹 Clean HTML
        |--------------------------------------------------------------------------
        */
        $new_html = $dom->saveHTML($dom->getElementsByTagName('body')->item(0));
        $new_html = preg_replace('/^<body>|<\/body>$/', '', $new_html);
    
        $block['innerHTML']       = $new_html;
        $block['innerContent'][0] = $new_html;
    
        return $block;
    }		

    /**
     * Process button core block
     *
     * @param array $block
     * @param array $attrs
     * @return array
     */
    private function process_button_core( $block, $attrs ) {

        if ( empty( $block['innerHTML'] ) ) {
            return $block;
        }
    
        $new_text = ! empty( $attrs['exbtxt'] ) ? wp_kses_post( $attrs['exbtxt'] ): '';
    
        $new_link = ! empty( $attrs['bLink']['url'] ) ? esc_url( $attrs['bLink']['url'] ) : '';
    
        libxml_use_internal_errors(true);
    
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $block['innerHTML']);
        $xpath = new DOMXPath($dom);
    

        if ( $new_text ) {
    
            $text_node = $xpath->query(
                '//*[contains(concat(" ", normalize-space(@class), " "), " tpgb-btn-txt ")]'
            )->item(0);
    
            if ( $text_node ) {
                $text_node->nodeValue = $new_text;
            }
    
            $block['attrs']['btxt']   = $new_text;
            $block['attrs']['exbtxt'] = $new_text;
        }
    
        if ( $new_link ) {
    
            $link_node = $xpath->query('//a[contains(@class,"tpgb-btn-link")]')->item(0);
    
            if ( $link_node ) {
                $link_node->setAttribute('href', $new_link);
            }
    
            $block['attrs']['bLink'] = $new_link;
        }
    

        $new_html = $dom->saveHTML($dom->getElementsByTagName('body')->item(0));
        $new_html = preg_replace('/^<body>|<\/body>$/', '', $new_html);
    
        $block['innerHTML']       = $new_html;
        $block['innerContent'][0] = $new_html;
    
        return $block;
    }
    
    /**
     * Process image block
     *
     * @param array $block
     * @param array $attrs
     * @return array
     */
    private function process_image( $block, $attrs ) {

        if ( empty( $block['innerHTML'] ) ) {
            return $block;
        }
    
        $new_img  = ! empty( $attrs['tImg']['url'] )
            ? esc_url( $attrs['tImg']['url'] )
            : '';
    
        $new_alt  = isset( $attrs['tImg']['alt'] )
            ? wp_kses_post( $attrs['tImg']['alt'] )
            : '';
    
        $new_id   = ! empty( $attrs['tImg']['id'] )
            ? intval( $attrs['tImg']['id'] )
            : '';
    
        $new_link = ! empty( $attrs['tiLink']['url'] )
            ? esc_url( $attrs['tiLink']['url'] )
            : '';
    
        libxml_use_internal_errors(true);
    
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $block['innerHTML']);
        $xpath = new DOMXPath($dom);
    
        /*
        |--------------------------------------------------------------------------
        | 🔹 Update <img>
        |--------------------------------------------------------------------------
        */
        $img_node = $xpath->query('//img[contains(@class,"tpgb-img-inner")]')->item(0);
    
        if ( $img_node ) {
    
            if ( $new_img ) {
                $img_node->setAttribute('src', $new_img);
                $block['attrs']['tImg']['url'] = $new_img;
            }
    
            if ( $new_alt ) {
                $img_node->setAttribute('alt', $new_alt);
                $block['attrs']['tImg']['alt'] = $new_alt;
            }
    
            if ( $new_id ) {
                $img_node->setAttribute('class', 'tpgb-img-inner wp-image-' . $new_id);
                $block['attrs']['tImg']['id'] = $new_id;
            }
        }
    
        /*
        |--------------------------------------------------------------------------
        | 🔹 Update Link
        |--------------------------------------------------------------------------
        */
        if ( $new_link ) {
    
            $link_node = $xpath->query('//a')->item(0);
    
            if ( $link_node ) {
                $link_node->setAttribute('href', $new_link);
            }
    
            $block['attrs']['tiLink']['url'] = $new_link;
        }
    
        /*
        |--------------------------------------------------------------------------
        | 🔹 Clean HTML
        |--------------------------------------------------------------------------
        */
        $new_html = $dom->saveHTML($dom->getElementsByTagName('body')->item(0));
        $new_html = preg_replace('/^<body>|<\/body>$/', '', $new_html);
    
        $block['innerHTML']       = $new_html;
        $block['innerContent'][0] = $new_html;
    
        return $block;
    }

    /**
     * Process tabs tours block
     *
     * @param array $block
     * @param array $attrs
     * @return array
     */

    private function process_tabs_tours( $block, $attrs ) {
    
        if ( empty( $attrs['tablistRepeater'] ) ) {
            return $block;
        }
    
        $is_editor = isset( $attrs['tabType'] ) && $attrs['tabType'] === 'editor';
    
        foreach ( $attrs['tablistRepeater'] as $index => $item ) {
    
            $tab_number = $index + 1;
            $title      = sanitize_text_field( $item['tabTitle'] ?? '' );
            $desc       = wp_kses_post( $item['tabDescription'] ?? '' );
    
            /*
            |--------------------------------------------------------------------------
            | Update Desktop Nav Title — innerContent[0] only
            |--------------------------------------------------------------------------
            */
            if ( isset( $block['innerContent'][0] ) ) {
                $block['innerContent'][0] = preg_replace(
                    '/(<div[^>]*class="[^"]*tpgb-tab-header[^"]*"[^>]*data-tab="' . $tab_number . '"[^>]*>\s*<span>)(.*?)(<\/span>)/s',
                    '$1' . esc_html( $title ) . '$3',
                    $block['innerContent'][0],
                    1
                );
            }
    
            /*
            |--------------------------------------------------------------------------
            | Sync Title in attrs
            |--------------------------------------------------------------------------
            */
            $block['attrs']['tablistRepeater'][ $index ]['tabTitle'] = $title;
    
            /*
            |--------------------------------------------------------------------------
            | Description mode only — NOT editor
            |--------------------------------------------------------------------------
            */
            if ( ! $is_editor && ! empty( $desc ) ) {
    
                /*
                |----------------------------------------------------------------------
                | Update Mobile Title — innerContent[0] only
                |----------------------------------------------------------------------
                */
                if ( isset( $block['innerContent'][0] ) ) {
                    $block['innerContent'][0] = preg_replace(
                        '/(<div[^>]*class="[^"]*tab-mobile-title[^"]*"[^>]*data-tab="' . $tab_number . '"[^>]*>\s*<span>)(.*?)(<\/span>)/s',
                        '$1' . esc_html( $title ) . '$3',
                        $block['innerContent'][0],
                        1
                    );
                }
    
                /*
                |----------------------------------------------------------------------
                | Update Description — innerContent[0] only
                |----------------------------------------------------------------------
                */
                if ( isset( $block['innerContent'][0] ) ) {
                    $block['innerContent'][0] = preg_replace(
                        '/(<div[^>]*class="[^"]*tpgb-tab-content[^"]*"[^>]*data-tab="' . $tab_number . '"[^>]*>.*?<div[^>]*class="[^"]*tpgb-content-editor[^"]*"[^>]*>)(.*?)(<\/div>\s*<\/div>)/s',
                        '$1' . $desc . '$3',
                        $block['innerContent'][0],
                        1
                    );
                }
    
                /*
                |----------------------------------------------------------------------
                | Sync Description in attrs
                |----------------------------------------------------------------------
                */
                $block['attrs']['tablistRepeater'][ $index ]['tabDescription'] = $desc;
            }
        }
    
        return $block;
    }
}