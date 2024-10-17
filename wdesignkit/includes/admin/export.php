protected function wdkit_put_save_template() {
			$email    = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : false;
			$response = '';

			if ( empty( $email ) ) {
				$response = array(
					'id'          => 0,
					'editpage'    => '',
					'message'     => $this->e_msg_login,
					'description' => $this->e_desc_login,
					'success'     => false,
				);

				wp_send_json( $response );
				wp_die();
			}

			$args          = $this->wdkit_parse_args( $_POST );
			$args['token'] = $this->wdkit_login_user_token( $email );
			unset( $args['email'] );

			global $post;

			$post_id       = get_the_ID();
			$custom_fields = array();
			if ( ! empty( $post_id ) ) {
				$meta_fields = get_post_custom( get_the_ID() );

				foreach ( $meta_fields as $key => $value ) {
					if ( str_contains( $key, 'nxt-' ) ) {
						$custom_fields[ $key ] = $value;
					}
				}

				if ( ! empty( $custom_fields ) ) {
					$data                = json_decode( $args['data'], true );
					$data['custom_meta'] = $custom_fields;
					$args['data']        = wp_json_encode( $data );
				}
			}

			$response = WDesignKit_Data_Query::get_data( 'save_template', $args );

			wp_send_json( $response );
			wp_die();
		}