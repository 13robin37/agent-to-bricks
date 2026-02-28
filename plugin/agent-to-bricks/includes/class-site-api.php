<?php
/**
 * Site info and framework detection REST API endpoints.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ATB_Site_API {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( 'agent-bricks/v1', '/site/info', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_info' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
		) );

		register_rest_route( 'agent-bricks/v1', '/site/frameworks', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_frameworks' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
		) );

		register_rest_route( 'agent-bricks/v1', '/site/element-types', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_element_types' ],
			'permission_callback' => [ __CLASS__, 'check_permission' ],
		] );

		register_rest_route( 'agent-bricks/v1', '/pages', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_pages' ],
			'permission_callback' => [ __CLASS__, 'check_permission' ],
			'args'                => [
				'search'   => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				],
				'per_page' => [
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => 20,
				],
			],
		] );
	}

	public static function check_permission() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * GET /site/info — Bricks environment info.
	 */
	public static function get_info() {
		$breakpoints = get_option( 'bricks_breakpoints', array() );
		if ( empty( $breakpoints ) && class_exists( '\Bricks\Breakpoints' ) ) {
			$breakpoints = \Bricks\Breakpoints::$breakpoints ?? array();
		}

		$element_types = array();
		if ( class_exists( '\Bricks\Elements' ) && ! empty( \Bricks\Elements::$elements ) ) {
			$element_types = array_keys( \Bricks\Elements::$elements );
		}

		return new WP_REST_Response( array(
			'bricksVersion'  => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : null,
			'contentMetaKey' => ATB_Bricks_Lifecycle::content_meta_key(),
			'elementTypes'   => $element_types,
			'breakpoints'    => $breakpoints,
			'pluginVersion'  => defined( 'AGENT_BRICKS_VERSION' ) ? AGENT_BRICKS_VERSION : null,
			'phpVersion'     => current_user_can( 'manage_options' ) ? PHP_VERSION : null,
			'wpVersion'      => get_bloginfo( 'version' ),
			'locale'         => get_locale(),
		), 200 );
	}

	/**
	 * GET /site/frameworks — detect CSS frameworks (ACSS, etc.).
	 */
	public static function get_frameworks() {
		$frameworks = array();

		// Detect Automatic.css
		$active_plugins = get_option( 'active_plugins', array() );
		$acss_active = false;
		foreach ( $active_plugins as $plugin ) {
			if ( stripos( $plugin, 'automaticcss' ) !== false || stripos( $plugin, 'acss' ) !== false ) {
				$acss_active = true;
				break;
			}
		}

		if ( $acss_active ) {
			$acss_settings = get_option( 'automatic_css_settings', array() );
			$settings_keys = is_array( $acss_settings ) ? array_keys( $acss_settings ) : array();

			// Count ACSS-imported global classes
			$all_classes = get_option( 'bricks_global_classes', array() );
			$acss_classes = array_filter( $all_classes, function( $c ) {
				return strpos( $c['id'] ?? '', 'acss_import_' ) === 0;
			} );

			// Extract key design tokens
			$colors = array();
			foreach ( array( 'primary', 'secondary', 'accent', 'base', 'neutral' ) as $family ) {
				$key = "color-$family";
				if ( isset( $acss_settings[ $key ] ) ) {
					$colors[ $family ] = $acss_settings[ $key ];
				}
			}

			$frameworks['acss'] = array(
				'name'         => 'Automatic.css',
				'active'       => true,
				'version'      => get_option( 'automatic_css_db_version', '' ),
				'settingsKeys' => $settings_keys,
				'classCount'   => count( $acss_classes ),
				'colors'       => $colors,
				'spacing'      => array(
					'scale'          => $acss_settings['space-scale'] ?? '',
					'sectionPadding' => $acss_settings['section-padding-block'] ?? '',
				),
				'typography'   => array(
					'rootFontSize'    => $acss_settings['root-font-size'] ?? '',
					'textFontFamily'  => $acss_settings['text-font-family'] ?? '',
					'headingFontFamily' => $acss_settings['heading-font-family'] ?? '',
				),
			);
		}

		return new WP_REST_Response( array( 'frameworks' => $frameworks ), 200 );
	}

	/**
	 * Locale-independent English descriptions for known Bricks element types.
	 *
	 * Bricks element labels (e.g. "Basic Text") are translated via WordPress i18n,
	 * so on non-English installs an AI agent may see e.g. "Einfacher Text" (German)
	 * and not know which element to use. This map provides a stable English
	 * description keyed by the element slug (which never changes with locale).
	 *
	 * @return array<string, string>
	 */
	private static function element_descriptions(): array {
		return [
			// Layout
			'section'            => 'Top-level page section wrapper (every page is built from sections)',
			'container'          => 'Content container inside a section',
			'block'              => 'Flex layout block for rows and columns',
			'div'                => 'Generic wrapper element',

			// Typography
			'heading'            => 'Heading text (h1–h6, set level via "tag" setting)',
			'text-basic'         => 'Simple text / paragraph — use for standard body text',
			'rich-text'          => 'Rich text editor with full inline HTML formatting (bold, italic, links)',
			'text-link'          => 'Clickable inline text link',

			// Interactive
			'button'             => 'Clickable button element',
			'icon'               => 'Icon element (SVG or icon-font)',
			'image'              => 'Image element',
			'video'              => 'Video embed element',

			// Navigation
			'nav-menu'           => 'WordPress menu rendered as navigation',
			'nav-nested'         => 'Nested navigation with custom menu items',
			'offcanvas'          => 'Off-canvas slide-out panel',

			// Components
			'accordion'          => 'Collapsible accordion',
			'accordion-nested'   => 'Nested accordion with custom content per item',
			'tabs'               => 'Tabbed content panels',
			'tabs-nested'        => 'Nested tabs with custom content per tab',
			'slider'             => 'Content slider / slideshow',
			'slider-nested'      => 'Nested slider with custom slide content',
			'carousel'           => 'Horizontal scrolling carousel',

			// Data / dynamic
			'form'               => 'Form with input fields',
			'map'                => 'Google Maps or OpenStreetMap embed',
			'code'               => 'Raw HTML / CSS / JS code block',
			'template'           => 'Reusable Bricks template reference',
			'post-content'       => 'Dynamic post / page content area',
			'posts'              => 'Post listing / query loop',
			'pagination'         => 'Page navigation for post lists',

			// Extra
			'list'               => 'Ordered or unordered list',
			'social-icons'       => 'Social media icon links',
			'alert'              => 'Alert / notice banner',
			'progress-bar'       => 'Progress bar indicator',
			'countdown'          => 'Countdown timer',
			'counter'            => 'Animated number counter',
			'pricing-tables'     => 'Pricing comparison table',
			'team-members'       => 'Team member profile cards',
			'testimonials'       => 'Testimonial / review display',
			'logo'               => 'Site logo element',
			'search'             => 'Search input element',
			'sidebar'            => 'Widget sidebar area',
			'wordpress'          => 'WordPress widget element',
			'shortcode'          => 'WordPress shortcode embed',
		];
	}

	/**
	 * GET /site/element-types — rich element type metadata with optional controls.
	 *
	 * The response includes a locale-independent `description` field for every
	 * known element type so that AI agents can identify elements regardless of
	 * the WordPress display language.
	 */
	public static function get_element_types( WP_REST_Request $request ): WP_REST_Response {
		$include_controls = (bool) $request->get_param( 'include_controls' );
		$category_filter  = sanitize_text_field( $request->get_param( 'category' ) ?? '' ) ?: null;

		if ( ! class_exists( '\Bricks\Elements' ) || empty( \Bricks\Elements::$elements ) ) {
			return new WP_REST_Response( [
				'elementTypes' => [],
				'count'        => 0,
			], 200 );
		}

		$descriptions = self::element_descriptions();
		$types        = [];

		foreach ( \Bricks\Elements::$elements as $name => $entry ) {
			$label    = '';
			$category = 'general';
			$icon     = '';
			$controls = [];

			// Bricks stores entries as arrays with 'class', 'name', 'label' keys.
			// Use get_element() to retrieve the fully populated element data.
			if ( is_array( $entry ) && method_exists( '\Bricks\Elements', 'get_element' ) ) {
				$full = \Bricks\Elements::get_element( [ 'name' => $name ] );
				if ( is_array( $full ) ) {
					$label    = $full['label'] ?? $name;
					$category = $full['category'] ?? 'general';
					$icon     = $full['icon'] ?? '';

					if ( $include_controls && ! empty( $full['controls'] ) ) {
						$controls = $full['controls'];
					}
				} else {
					$label = $entry['label'] ?? $name;
				}
			} elseif ( is_object( $entry ) ) {
				$label    = $entry->label ?? $name;
				$category = $entry->category ?? 'general';
				$icon     = $entry->icon ?? '';

				if ( $include_controls && method_exists( $entry, 'set_controls' ) ) {
					$entry->set_controls();
					$controls = $entry->controls ?? [];
				}
			} elseif ( is_string( $entry ) && class_exists( $entry ) ) {
				try {
					$instance = new $entry();
					$label    = $instance->label ?? $name;
					$category = $instance->category ?? 'general';
					$icon     = $instance->icon ?? '';

					if ( $include_controls && method_exists( $instance, 'set_controls' ) ) {
						$instance->set_controls();
						$controls = $instance->controls ?? [];
					}
				} catch ( \Throwable $e ) {
					$label = $name;
				}
			}

			if ( $category_filter && $category !== $category_filter ) {
				continue;
			}

			$type_data = [
				'name'        => $name,
				'label'       => $label,
				'description' => $descriptions[ $name ] ?? null,
				'category'    => $category,
				'icon'        => $icon,
			];

			if ( $include_controls && ! empty( $controls ) ) {
				$type_data['controls'] = self::sanitize_controls( $controls );
			}

			$types[] = $type_data;
		}

		usort( $types, fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );

		return new WP_REST_Response( [
			'elementTypes' => $types,
			'count'        => count( $types ),
		], 200 );
	}

	/**
	 * GET /pages — search pages on the site.
	 */
	public static function get_pages( WP_REST_Request $request ): WP_REST_Response {
		$search   = $request->get_param( 'search' );
		$per_page = min( (int) $request->get_param( 'per_page' ), 50 );
		if ( $per_page < 1 ) {
			$per_page = 20;
		}

		$args = [
			'post_type'      => 'page',
			'post_status'    => [ 'publish', 'draft', 'private' ],
			'posts_per_page' => $per_page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		];

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );
		$pages = [];

		foreach ( $query->posts as $post ) {
			// Filter by access control
			if ( ATB_Access_Control::can_access_post( $post->ID ) !== true ) {
				continue;
			}
			$pages[] = [
				'id'       => $post->ID,
				'title'    => $post->post_title ?: '(no title)',
				'slug'     => $post->post_name,
				'status'   => $post->post_status,
				'modified' => $post->post_modified,
			];
		}

		return new WP_REST_Response( $pages, 200 );
	}

	/**
	 * Sanitize controls for API response — strip closures and internal fields.
	 */
	private static function sanitize_controls( array $controls ): array {
		$clean = [];
		foreach ( $controls as $key => $control ) {
			if ( ! is_array( $control ) ) continue;

			$entry = [];
			foreach ( [ 'type', 'label', 'default', 'options', 'placeholder', 'description', 'units', 'min', 'max', 'step' ] as $field ) {
				if ( isset( $control[ $field ] ) && ! ( $control[ $field ] instanceof \Closure ) ) {
					$entry[ $field ] = $control[ $field ];
				}
			}

			if ( ! empty( $entry ) ) {
				$clean[ $key ] = $entry;
			}
		}
		return $clean;
	}
}
