<?php
/**
 * Shared admin interface components.
 */

trait PSM_UI_Components_Trait {

	public function psm_get_per_page() {
		$per_page = absint( get_option( 'psm_ai_master_per_page', PSM_AI_DEFAULT_PER_PAGE ) );
		return min( 100, max( 5, $per_page ) );
	}

	public function psm_pagination_html( $page, $total_pages, $total_items, $per_page, $page_param, $item_label, $link_base = '' ) {
		$total_pages = max( 1, (int) $total_pages );
		$page        = max( 1, min( $total_pages, (int) $page ) );
		$start       = $total_items > 0 ? ( ( $page - 1 ) * $per_page ) + 1 : 0;
		$end         = min( $page * $per_page, $total_items );

		$html  = '<div class="psm-pagination"><div class="psm-pagination-info">Showing <strong>' . number_format_i18n( $start ) . '&ndash;' . number_format_i18n( $end ) . '</strong> of <strong>' . number_format_i18n( $total_items ) . '</strong> ' . esc_html( $item_label ) . '</div><nav class="psm-pagination-links" aria-label="' . esc_attr( ucfirst( $item_label ) . ' pagination' ) . '">';
		$html .= $this->psm_page_button_html( 1, '', 'dashicons-controls-skipback', 'First page', 1 === $page, false, $page_param, $link_base );
		$html .= $this->psm_page_button_html( $page - 1, '', 'dashicons-arrow-left-alt', 'Previous page', 1 === $page, false, $page_param, $link_base );

		$page_numbers = array( 1, $total_pages );
		for ( $number = max( 1, $page - 2 ); $number <= min( $total_pages, $page + 2 ); $number++ ) {
			$page_numbers[] = $number;
		}
		$page_numbers = array_values( array_unique( $page_numbers ) );
		sort( $page_numbers );
		$last_number = 0;
		foreach ( $page_numbers as $number ) {
			if ( $last_number && $number - $last_number > 1 ) {
				$html .= '<span class="psm-page-ellipsis">&hellip;</span>';
			}
			$html       .= $this->psm_page_button_html( $number, (string) $number, '', 'Page ' . $number, false, $number === $page, $page_param, $link_base );
			$last_number = $number;
		}

		$html .= $this->psm_page_button_html( $page + 1, '', 'dashicons-arrow-right-alt', 'Next page', $page === $total_pages, false, $page_param, $link_base );
		$html .= $this->psm_page_button_html( $total_pages, '', 'dashicons-controls-skipforward', 'Last page', $page === $total_pages, false, $page_param, $link_base );
		$html .= '</nav></div>';
		return $html;
	}

	public function render_per_page_setting_html() {
		$per_page_options = array();
		foreach ( array( 5, 10, 15, 25, 50, 75, 100 ) as $per_page_value ) {
			$per_page_options[] = array(
				'value'    => $per_page_value,
				'label'    => $per_page_value,
				'selected' => (int) $per_page_value === $this->psm_get_per_page(),
			);
		}
		$select = $this->psm_select_html(
			array(
				'name'    => 'per_page',
				'id'      => 'psm_per_page',
				'options' => $per_page_options,
				'tooltip' => 'Choose how many rows each master table and batch page displays',
			)
		);
		$save_button = $this->psm_button_html(
			'Save Pagination',
			'psm-btn-primary',
			array( 'id' => 'psm_save_per_page' )
		);
		$html        = '<div class="psm-per-page-setting"><label class="psm-filter-field psm-per-page-field" for="psm_per_page"><span>Rows per page</span>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_select_html() and psm_button_html() escape their output.
		$html .= $select . '</label>' . $save_button . '</div>';
		return $html;
	}

	public function psm_button_html( $label, $classes = '', $attributes = array(), $icon = '', $base_class = 'psm-btn' ) {
		$attributes = array_merge(
			array(
				'type'  => 'button',
				'class' => trim( $base_class . ' ' . $classes ),
			),
			$attributes
		);
		$attrs = $this->psm_attributes_html( $attributes, true );
		$html  = '<button' . $attrs . '>';
		if ( '' !== $icon ) {
			$html .= '<span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
		}
		return $html . esc_html( $label ) . '</button>';
	}

	public function psm_tabs_html( $tabs, $active_tab ) {
		$html = '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $tab ) {
			$classes = 'nav-tab' . ( $active_tab === $slug ? ' nav-tab-active' : '' );
			$html   .= '<a href="' . esc_url( $tab['url'] ) . '" class="' . esc_attr( $classes ) . '">' . esc_html( $tab['label'] ) . '</a>';
		}
		return $html . '</nav>';
	}

	public function psm_text_input_html( $args ) {
		$defaults = array(
			'name'        => '',
			'id'          => '',
			'value'       => '',
			'type'        => 'text',
			'class'       => '',
			'placeholder' => '',
			'required'    => false,
			'tooltip'     => '',
		);
		$args     = wp_parse_args( $args, $defaults );
		$attrs    = array(
			'name'         => $args['name'],
			'id'           => $args['id'],
			'type'         => $args['type'],
			'class'        => $args['class'],
			'value'        => $args['value'],
			'placeholder'  => $args['placeholder'],
			'data-tooltip' => $args['tooltip'],
		);
		if ( $args['required'] ) {
			$attrs['required'] = 'required';
		}
		return '<input' . $this->psm_attributes_html( $attrs ) . '>';
	}

	public function psm_select_html( $args ) {
		$defaults = array(
			'name'     => '',
			'id'       => '',
			'class'    => '',
			'selected' => '',
			'required' => false,
			'tooltip'  => '',
			'options'  => array(),
		);
		$args     = wp_parse_args( $args, $defaults );
		$attrs    = array(
			'name'         => $args['name'],
			'id'           => $args['id'],
			'class'        => $args['class'],
			'data-tooltip' => $args['tooltip'],
		);
		if ( $args['required'] ) {
			$attrs['required'] = 'required';
		}
		$html = '<select' . $this->psm_attributes_html( $attrs ) . '>';
		foreach ( $args['options'] as $option ) {
			$option       = wp_parse_args(
				$option,
				array(
					'value'    => '',
					'label'    => '',
					'selected' => false,
					'disabled' => false,
					'attrs'    => array(),
				)
			);
			$option_attrs = array_merge(
				$option['attrs'],
				array(
					'value' => $option['value'],
				)
			);
			if ( $option['selected'] || (string) $args['selected'] === (string) $option['value'] ) {
				$option_attrs['selected'] = 'selected';
			}
			if ( $option['disabled'] ) {
				$option_attrs['disabled'] = 'disabled';
			}
			$html .= '<option' . $this->psm_attributes_html( $option_attrs ) . '>' . esc_html( $option['label'] ) . '</option>';
		}
		return $html . '</select>';
	}

	public function psm_notice_html( $notice ) {
		if ( empty( $notice['text'] ) ) {
			return '';
		}
		$type = in_array( $notice['type'], array( 'error', 'warning', 'success', 'info' ), true ) ? $notice['type'] : 'info';
		return '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['text'] ) . '</p></div>';
	}

	private function psm_page_button_html( $target, $label, $icon, $tooltip, $disabled, $current, $page_param, $link_base = '' ) {
		$classes = 'psm-page-btn' . ( $disabled ? ' is-disabled' : '' ) . ( $current ? ' is-current' : '' );
		$content = '';
		if ( '' !== $icon ) {
			$content .= '<span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
		}
		if ( '' !== $label ) {
			$content .= '<span>' . esc_html( $label ) . '</span>';
		}
		$attrs = ' data-page="' . esc_attr( $target ) . '" data-page-param="' . esc_attr( $page_param ) . '" data-tooltip="' . esc_attr( $tooltip ) . '"';
		if ( $disabled || $current ) {
			return '<span class="' . esc_attr( $classes ) . '" aria-disabled="true"' . $attrs . '>' . $content . '</span>';
		}
		if ( '' !== $link_base ) {
			$url = add_query_arg( $page_param, $target, $link_base );
			return '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $classes ) . '" aria-label="' . esc_attr( $tooltip ) . '"' . $attrs . '>' . $content . '</a>';
		}
		return '<button type="button" class="' . esc_attr( $classes ) . '" aria-label="' . esc_attr( $tooltip ) . '"' . $attrs . '>' . $content . '</button>';
	}

	private function psm_attributes_html( $attributes, $allow_empty = false ) {
		$html = '';
		foreach ( $attributes as $name => $value ) {
			if ( false === $value || null === $value || ( '' === $value && ! $allow_empty ) ) {
				continue;
			}
			$html .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}
		return $html;
	}
}
