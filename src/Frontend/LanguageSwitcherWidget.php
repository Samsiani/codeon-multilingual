<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Frontend;

use WP_Widget;

/**
 * Classic WP widget that wraps LanguageSwitcher::render with admin-form controls.
 *
 * Block-editor widgets call the legacy widgets via "Legacy Widget" block, so
 * shipping a classic widget gives us both worlds without a separate block.
 */
final class LanguageSwitcherWidget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'cml_language_switcher',
			__( 'CodeOn Language Switcher', 'codeon-multilingual' ),
			array(
				'description' => __( 'Lets visitors switch between site languages.', 'codeon-multilingual' ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $args
	 * @param array<string, mixed> $instance
	 */
	public function widget( $args, $instance ): void {
		$title        = isset( $instance['title'] )        ? (string) $instance['title']        : '';
		$style        = isset( $instance['style'] )        ? (string) $instance['style']        : 'list';
		$show_flag    = ! empty( $instance['show_flag'] );
		$show_current = ! isset( $instance['show_current'] ) || (bool) $instance['show_current'];
		$show_native  = ! isset( $instance['show_native'] )  || (bool) $instance['show_native'];
		$show_code    = ! empty( $instance['show_code'] );

		$markup = LanguageSwitcher::render( $style, $show_flag, $show_current, $show_native, $show_code );
		if ( '' === $markup ) {
			return;
		}

		echo isset( $args['before_widget'] ) ? wp_kses_post( (string) $args['before_widget'] ) : '';

		if ( '' !== $title ) {
			$title_filtered = apply_filters( 'widget_title', $title, $instance, $this->id_base );
			echo isset( $args['before_title'] ) ? wp_kses_post( (string) $args['before_title'] ) : '';
			echo esc_html( (string) $title_filtered );
			echo isset( $args['after_title'] ) ? wp_kses_post( (string) $args['after_title'] ) : '';
		}

		// Markup comes from LanguageSwitcher::render() which escapes its own outputs.
		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo isset( $args['after_widget'] ) ? wp_kses_post( (string) $args['after_widget'] ) : '';
	}

	/**
	 * @param array<string, mixed> $instance
	 */
	public function form( $instance ): string {
		$title        = isset( $instance['title'] )        ? (string) $instance['title']        : '';
		$style        = isset( $instance['style'] )        ? (string) $instance['style']        : 'list';
		$show_flag    = ! empty( $instance['show_flag'] );
		$show_current = ! isset( $instance['show_current'] ) || (bool) $instance['show_current'];
		$show_native  = ! isset( $instance['show_native'] )  || (bool) $instance['show_native'];
		$show_code    = ! empty( $instance['show_code'] );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'codeon-multilingual' ); ?></label>
			<input class="widefat" type="text"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>"><?php esc_html_e( 'Style:', 'codeon-multilingual' ); ?></label>
			<select class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'style' ) ); ?>">
				<option value="list"     <?php selected( $style, 'list' ); ?>><?php esc_html_e( 'List',     'codeon-multilingual' ); ?></option>
				<option value="dropdown" <?php selected( $style, 'dropdown' ); ?>><?php esc_html_e( 'Dropdown', 'codeon-multilingual' ); ?></option>
				<option value="flags"    <?php selected( $style, 'flags' ); ?>><?php esc_html_e( 'Flags',    'codeon-multilingual' ); ?></option>
			</select>
		</p>
		<p>
			<label><input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'show_flag' ) ); ?>" value="1" <?php checked( $show_flag ); ?>> <?php esc_html_e( 'Show flags', 'codeon-multilingual' ); ?></label><br>
			<label><input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'show_current' ) ); ?>" value="1" <?php checked( $show_current ); ?>> <?php esc_html_e( 'Include current language', 'codeon-multilingual' ); ?></label><br>
			<label><input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'show_native' ) ); ?>" value="1" <?php checked( $show_native ); ?>> <?php esc_html_e( 'Show native name', 'codeon-multilingual' ); ?></label><br>
			<label><input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'show_code' ) ); ?>" value="1" <?php checked( $show_code ); ?>> <?php esc_html_e( 'Show language code', 'codeon-multilingual' ); ?></label>
		</p>
		<?php
		return '';
	}

	/**
	 * @param array<string, mixed> $new_instance
	 * @param array<string, mixed> $old_instance
	 * @return array<string, mixed>
	 */
	public function update( $new_instance, $old_instance ): array {
		return array(
			'title'        => isset( $new_instance['title'] ) ? sanitize_text_field( (string) $new_instance['title'] ) : '',
			'style'        => isset( $new_instance['style'] ) && in_array( $new_instance['style'], array( 'list', 'dropdown', 'flags' ), true )
				? (string) $new_instance['style']
				: 'list',
			'show_flag'    => ! empty( $new_instance['show_flag'] ) ? 1 : 0,
			'show_current' => ! empty( $new_instance['show_current'] ) ? 1 : 0,
			'show_native'  => ! empty( $new_instance['show_native'] ) ? 1 : 0,
			'show_code'    => ! empty( $new_instance['show_code'] ) ? 1 : 0,
		);
	}
}
