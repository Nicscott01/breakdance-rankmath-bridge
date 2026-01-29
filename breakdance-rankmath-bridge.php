<?php
/**
 * Plugin Name: Breakdance Rank Math Bridge
 * Plugin URI: https://www.crearewebsolutions.com/blog/breakdance-rank-math-scores/
 * Description: Injects rendered Breakdance output into Rank Math analysis (editor + bulk recalculation)
 * Version: 2.1.0
 * Author: Nic Scott
 * Author URI: https://crearewebsolutions.com
 * License: GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Breakdance_RankMath_Bridge {

	/**
	 * Instance of this class
	 *
	 * @var object
	 */
	private static $instance;

	/**
	 * Cache for rendered content
	 *
	 * @var array
	 */
	private $content_cache = array();

	/**
	 * Content mode: breakdance (default) or combine
	 *
	 * @var string
	 */
	private $content_mode = 'breakdance';

	/**
	 * Debug flag
	 *
	 * @var bool
	 */
	private $debug_enabled = false;


	/**
	 * Get instance
	 *
	 * @return Breakdance_RankMath_Bridge
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'rank_math/recalculate_score/data', array( $this, 'filter_recalculate_score_data' ), 10, 2 );
	}

	/**
	 * Enqueue admin assets for Rank Math editor analysis
	 *
	 * @param string $hook
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			return;
		}

		$post_id = $this->get_current_post_id();
		if ( ! $post_id ) {
			return;
		}

		$handle = 'breakdance-rankmath-bridge';
		wp_register_script(
			$handle,
			plugin_dir_url( __FILE__ ) . 'breakdance-rankmath-bridge.js',
			array( 'wp-hooks', 'wp-api-fetch', 'rank-math-analyzer' ),
			'2.1.0',
			true
		);
		wp_enqueue_script( $handle );

		wp_add_inline_script(
			$handle,
			'window.BreakdanceRankMathBridge = ' . wp_json_encode(
				array(
					'restUrl' => esc_url_raw( rest_url( 'breakdance-rankmath/v1/rendered-content' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'postId'  => (int) $post_id,
					'mode'    => $this->content_mode,
					'debug'   => $this->debug_enabled,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Register REST routes
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			'breakdance-rankmath/v1',
			'/rendered-content',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_rendered_content' ),
				'permission_callback' => array( $this, 'rest_can_render_content' ),
				'args'                => array(
					'post_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);
	}

	/**
	 * Permission callback for REST route
	 *
	 * @param \WP_REST_Request $request
	 * @return bool
	 */
	public function rest_can_render_content( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( ! $post_id ) {
			return false;
		}
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * REST callback for rendered content
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function rest_get_rendered_content( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$content = $this->get_rendered_content_for_post( $post_id );

		return rest_ensure_response(
			array(
				'post_id' => $post_id,
				'content' => $content,
			)
		);
	}

	/**
	 * Filter Rank Math bulk recalculation data
	 *
	 * @param array $values
	 * @param \WP_Post $post
	 * @return array
	 */
	public function filter_recalculate_score_data( $values, $post ) {
		if ( ! is_array( $values ) || ! $post || ! isset( $post->ID ) ) {
			return $values;
		}

		$post_id = (int) $post->ID;
		if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
			return $values;
		}

		$content = $this->get_rendered_content_for_post( $post_id );
		if ( ! empty( $content ) ) {
			if ( 'combine' === $this->content_mode ) {
				$original = isset( $values['content'] ) ? trim( (string) $values['content'] ) : '';
				$values['content'] = $original ? ( $original . "\n\n" . $content ) : $content;
			} else {
				$values['content'] = $content;
			}
		}

		return $values;
	}

	/**
	 * Get rendered frontend content by fetching the actual page
	 *
	 * @param int $post_id Post ID
	 * @return string Extracted text content
	 */
	private function get_frontend_rendered_content( $post_id ) {
		$content = '';

		$post      = get_post( $post_id );
		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return $content;
		}

		$preview_link = '';
		if ( $post && 'publish' !== get_post_status( $post_id ) && function_exists( 'get_preview_post_link' ) ) {
			$preview_link = get_preview_post_link( $post );
		}

		$request_url = $preview_link ? $preview_link : $permalink;
		$cookies     = $preview_link ? $this->get_request_cookies_for_preview() : array();

		$response = wp_remote_get( $request_url, array(
			'timeout'     => 15,
			'sslverify'   => false,
			'redirection' => 5,
			'user-agent'  => 'Breakdance-RankMath-Bridge/2.1',
			'cookies'     => $cookies,
		) );

		if ( is_wp_error( $response ) ) {
			if ( $this->debug_enabled && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Breakdance RankMath Bridge] wp_remote_get error: ' . $response->get_error_message() );
			}
			return $content;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $this->debug_enabled && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Breakdance RankMath Bridge] wp_remote_get status=' . $status_code . ' url=' . $request_url );
		}

		if ( in_array( (int) $status_code, array( 401, 403, 404 ), true ) && $preview_link && $request_url !== $preview_link ) {
			$response = wp_remote_get( $preview_link, array(
				'timeout'     => 15,
				'sslverify'   => false,
				'redirection' => 5,
				'user-agent'  => 'Breakdance-RankMath-Bridge/2.1',
				'cookies'     => $cookies,
			) );
			if ( is_wp_error( $response ) ) {
				if ( $this->debug_enabled && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[Breakdance RankMath Bridge] wp_remote_get preview error: ' . $response->get_error_message() );
				}
				return $content;
			}
			$status_code = wp_remote_retrieve_response_code( $response );
			if ( $this->debug_enabled && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Breakdance RankMath Bridge] wp_remote_get preview status=' . $status_code . ' url=' . $preview_link );
			}
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			if ( $this->debug_enabled && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Breakdance RankMath Bridge] wp_remote_get empty body for ' . $request_url );
			}
			return $content;
		}

		return $this->extract_content_from_html( $html );
	}

	/**
	 * Extract text content from HTML
	 *
	 * @param string $html Raw HTML
	 * @return string Extracted text content
	 */
	private function extract_content_from_html( $html ) {
		$html = preg_replace( '/<script\b[^>]*>(.*?)<\/script>/is', '', $html );
		$html = preg_replace( '/<style\b[^>]*>(.*?)<\/style>/is', '', $html );

		if ( class_exists( 'DOMDocument' ) ) {
			$internal_errors = libxml_use_internal_errors( true );
			$doc             = new \DOMDocument();
			$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
			libxml_clear_errors();
			libxml_use_internal_errors( $internal_errors );

			$xpath = new \DOMXPath( $doc );
			$remove_tags = array( 'script', 'style', 'nav', 'header', 'footer', 'aside' );
			foreach ( $remove_tags as $tag ) {
				foreach ( $xpath->query( '//' . $tag ) as $node ) {
					$node->parentNode->removeChild( $node );
				}
			}

			$remove_keywords = array( 'menu', 'nav', 'breadcrumb', 'footer', 'header', 'sidebar', 'skip', 'cookie', 'popup', 'modal', 'newsletter' );
			foreach ( $remove_keywords as $keyword ) {
				$query = '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $keyword . ' ") or contains(concat(" ", normalize-space(@id), " "), " ' . $keyword . ' ")]';
				foreach ( $xpath->query( $query ) as $node ) {
					$node->parentNode->removeChild( $node );
				}
			}

			foreach ( $xpath->query( '//*[@role="navigation"]' ) as $node ) {
				$node->parentNode->removeChild( $node );
			}

			$main_node = null;
			$breakdance_nodes = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " breakdance ")]' );
			if ( $breakdance_nodes && $breakdance_nodes->length > 0 ) {
				$main_node = $breakdance_nodes->item( 0 );
			}
			if ( ! $main_node ) {
				$main_nodes = $xpath->query( '//main | //article' );
				if ( $main_nodes && $main_nodes->length > 0 ) {
					$main_node = $main_nodes->item( 0 );
				}
			}
			if ( ! $main_node ) {
				$body_nodes = $xpath->query( '//body' );
				$main_node  = ( $body_nodes && $body_nodes->length > 0 ) ? $body_nodes->item( 0 ) : $doc->documentElement;
			}

			if ( $main_node ) {
				$main_content = $doc->saveHTML( $main_node );
			} else {
				$main_content = '';
			}
		} else {
			$main_content = $html;
			$patterns     = array(
				'/<main[^>]*>(.*?)<\/main>/is',
				'/<article[^>]*>(.*?)<\/article>/is',
				'/<div[^>]*class=["\'][^"\']*content[^"\']*["\'][^>]*>(.*?)<\/div>/is',
				'/<div[^>]*id=["\']content["\'][^>]*>(.*?)<\/div>/is',
			);
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $html, $matches ) ) {
					$main_content = $matches[1];
					break;
				}
			}
			if ( empty( $main_content ) && preg_match( '/<body[^>]*>(.*?)<\/body>/is', $html, $matches ) ) {
				$main_content = $matches[1];
			}
			$main_content = preg_replace( '/<nav[^>]*>.*?<\/nav>/is', '', $main_content );
			$main_content = preg_replace( '/<header[^>]*>.*?<\/header>/is', '', $main_content );
			$main_content = preg_replace( '/<footer[^>]*>.*?<\/footer>/is', '', $main_content );
			$main_content = preg_replace( '/<aside[^>]*>.*?<\/aside>/is', '', $main_content );
			// Keep HTML in fallback path as well.
		}

		$main_content = preg_replace( '/\n{3,}/', "\n\n", $main_content );
		$main_content = preg_replace( '/[ \t]+/', ' ', $main_content );

		return trim( (string) $main_content );
	}

	/**
	 * Get current post ID in the editor
	 *
	 * @return int
	 */
	private function get_current_post_id() {
		if ( isset( $_GET['post'] ) ) {
			return (int) $_GET['post'];
		}
		if ( isset( $_POST['post_ID'] ) ) {
			return (int) $_POST['post_ID'];
		}
		return 0;
	}

	/**
	 * Get rendered content for a post (Breakdance render first, then frontend HTML)
	 *
	 * @param int $post_id
	 * @return string
	 */
	private function get_rendered_content_for_post( $post_id ) {
		if ( isset( $this->content_cache[ $post_id ] ) ) {
			return $this->content_cache[ $post_id ];
		}

		$content = '';
		$source  = '';

		if ( $this->is_breakdance_available() ) {
			$content = $this->get_breakdance_rendered_content_for_post( $post_id );
			$source  = 'breakdance_render';
		}

		if ( empty( $content ) ) {
			$content = $this->get_frontend_rendered_content( $post_id );
			$source  = $this->is_breakdance_available() ? 'frontend_fetch_fallback' : 'frontend_fetch_no_breakdance';
		}

		if ( $this->debug_enabled && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Breakdance RankMath Bridge] content source=' . $source . ' post_id=' . $post_id . ' len=' . strlen( (string) $content ) );
		}

		$this->content_cache[ $post_id ] = $content;

		return $content;
	}

	/**
	 * Render Breakdance output for a post in a simulated request context
	 *
	 * @param int $post_id
	 * @return string
	 */
	private function get_breakdance_rendered_content_for_post( $post_id ) {
		if ( ! function_exists( '\\Breakdance\\Render\\render' ) ) {
			return '';
		}

		return (string) $this->with_post_query_context( $post_id, function () use ( $post_id ) {
			if ( function_exists( 'bdox_run_action' ) ) {
				bdox_run_action( 'breakdance_register_template_types_and_conditions' );
			}

			$rendered_html = '';

			if ( $this->is_breakdance_post( $post_id ) ) {
				try {
					$rendered_html = \Breakdance\Render\render( $post_id );
				} catch ( \Throwable $e ) {
					$rendered_html = '';
				}
			}

			if ( empty( $rendered_html ) && function_exists( '\\Breakdance\\Themeless\\getTemplateForRequest' ) && class_exists( '\\Breakdance\\Themeless\\ThemelessController' ) ) {
				$template = \Breakdance\Themeless\getTemplateForRequest( \Breakdance\Themeless\ThemelessController::getInstance()->templates );
				if ( $template && isset( $template['id'] ) ) {
					\Breakdance\Themeless\ThemelessController::getInstance()->buildTemplateHierarchyForRequest( $template['id'] );
					$template_id = \Breakdance\Themeless\ThemelessController::getInstance()->popHierarchy();
					if ( $template_id ) {
						try {
							$rendered_html = \Breakdance\Render\render( $template_id );
						} catch ( \Throwable $e ) {
							$rendered_html = '';
						}
					}
				}
			}

			if ( empty( $rendered_html ) ) {
				$rendered_html = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
			}

			return $this->extract_content_from_html( (string) $rendered_html );
		} );
	}

	/**
	 * Run callback with a simulated singular query context
	 *
	 * @param int $post_id
	 * @param callable $callback
	 * @return mixed
	 */
	private function with_post_query_context( $post_id, $callback ) {
		$old_wp_query     = $GLOBALS['wp_query'] ?? null;
		$old_wp_the_query = $GLOBALS['wp_the_query'] ?? null;
		$old_post         = $GLOBALS['post'] ?? null;

		$query = new \WP_Query(
			array(
				'p'         => $post_id,
				'post_type' => get_post_type( $post_id ),
			)
		);

		$GLOBALS['wp_query']     = $query;
		$GLOBALS['wp_the_query'] = $query;

		if ( $query->have_posts() ) {
			$query->the_post();
		}

		$result = call_user_func( $callback );

		wp_reset_postdata();

		$GLOBALS['wp_query']     = $old_wp_query;
		$GLOBALS['wp_the_query'] = $old_wp_the_query;
		$GLOBALS['post']         = $old_post;

		return $result;
	}

	/**
	 * Build cookies for preview requests (logged-in user context)
	 *
	 * @return array
	 */
	private function get_request_cookies_for_preview() {
		if ( ! is_user_logged_in() ) {
			return array();
		}

		$cookies = array();
		foreach ( $_COOKIE as $name => $value ) {
			$cookies[] = new \WP_Http_Cookie(
				array(
					'name'  => $name,
					'value' => $value,
				)
			);
		}

		return $cookies;
	}


	/**
	 * Check if Breakdance is available
	 *
	 * @return bool
	 */
	private function is_breakdance_available() {
		return class_exists( '\\Breakdance\\PluginAPI' ) || defined( 'BREAKDANCE_VERSION' );
	}

	/**
	 * Check if a post uses Breakdance
	 *
	 * @param int $post_id
	 * @return bool
	 */
	private function is_breakdance_post( $post_id ) {
		$is_breakdance = get_post_meta( $post_id, '_breakdance_data', true );
		if ( ! $is_breakdance ) {
			$is_breakdance = get_post_meta( $post_id, '_breakdance_tree', true );
		}

		return ! empty( $is_breakdance );
	}
}

// Initialize the bridge
Breakdance_RankMath_Bridge::get_instance();
