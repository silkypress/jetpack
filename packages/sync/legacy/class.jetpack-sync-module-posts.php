<?php

use Automattic\Jetpack\Constants;

class Jetpack_Sync_Module_Posts extends Jetpack_Sync_Module {

	private $just_published  = array();
	private $previous_status = array();
	private $action_handler;
	private $import_end = false;

	const DEFAULT_PREVIOUS_STATE = 'new';

	public function name() {
		return 'posts';
	}

	public function get_object_by_id( $object_type, $id ) {
		if ( $object_type === 'post' && $post = get_post( intval( $id ) ) ) {
			return $this->filter_post_content_and_add_links( $post );
		}

		return false;
	}

	public function init_listeners( $callable ) {
		$this->action_handler = $callable;

		add_action( 'wp_insert_post', array( $this, 'wp_insert_post' ), 11, 3 );
		add_action( 'jetpack_sync_save_post', $callable, 10, 4 );

		add_action( 'deleted_post', $callable, 10 );
		add_action( 'jetpack_published_post', $callable, 10, 2 );

		add_action( 'transition_post_status', array( $this, 'save_published' ), 10, 3 );
		add_filter( 'jetpack_sync_before_enqueue_jetpack_sync_save_post', array( $this, 'filter_blacklisted_post_types' ) );

		// listen for meta changes
		$this->init_listeners_for_meta_type( 'post', $callable );
		$this->init_meta_whitelist_handler( 'post', array( $this, 'filter_meta' ) );

		add_action( 'jetpack_daily_akismet_meta_cleanup_before', array( $this, 'daily_akismet_meta_cleanup_before' ) );
		add_action( 'jetpack_daily_akismet_meta_cleanup_after', array( $this, 'daily_akismet_meta_cleanup_after' ) );
		add_action( 'jetpack_post_meta_batch_delete', $callable, 10, 2 );

	}


	public function daily_akismet_meta_cleanup_before( $feedback_ids ) {
		remove_action( 'deleted_post_meta', $this->action_handler );
		/**
		 * Used for syncing deletion of batch post meta
		 *
		 * @since 6.1.0
		 *
		 * @module sync
		 *
		 * @param array $feedback_ids feedback post IDs
		 * @param string $meta_key to be deleted
		 */
		do_action( 'jetpack_post_meta_batch_delete', $feedback_ids, '_feedback_akismet_values' );
	}

	public function daily_akismet_meta_cleanup_after( $feedback_ids ) {
		add_action( 'deleted_post_meta', $this->action_handler );
	}

	public function init_full_sync_listeners( $callable ) {
		add_action( 'jetpack_full_sync_posts', $callable ); // also sends post meta
	}

	public function init_before_send() {
		add_filter( 'jetpack_sync_before_send_jetpack_sync_save_post', array( $this, 'expand_jetpack_sync_save_post' ) );

		// full sync
		add_filter( 'jetpack_sync_before_send_jetpack_full_sync_posts', array( $this, 'expand_post_ids' ) );
	}

	public function enqueue_full_sync_actions( $config, $max_items_to_enqueue, $state ) {
		global $wpdb;

		return $this->enqueue_all_ids_as_action( 'jetpack_full_sync_posts', $wpdb->posts, 'ID', $this->get_where_sql( $config ), $max_items_to_enqueue, $state );
	}

	public function estimate_full_sync_actions( $config ) {
		global $wpdb;

		$query = "SELECT count(*) FROM $wpdb->posts WHERE " . $this->get_where_sql( $config );
		$count = $wpdb->get_var( $query );

		return (int) ceil( $count / self::ARRAY_CHUNK_SIZE );
	}

	private function get_where_sql( $config ) {
		$where_sql = Jetpack_Sync_Settings::get_blacklisted_post_types_sql();

		// config is a list of post IDs to sync
		if ( is_array( $config ) ) {
			$where_sql .= ' AND ID IN (' . implode( ',', array_map( 'intval', $config ) ) . ')';
		}

		return $where_sql;
	}

	function get_full_sync_actions() {
		return array( 'jetpack_full_sync_posts' );
	}

	/**
	 * Process content before send
	 *
	 * @param array $args wp_insert_post arguments
	 *
	 * @return array
	 */
	function expand_jetpack_sync_save_post( $args ) {
		list( $post_id, $post, $update, $previous_state ) = $args;
		return array( $post_id, $this->filter_post_content_and_add_links( $post ), $update, $previous_state );
	}

	function filter_blacklisted_post_types( $args ) {
		$post = $args[1];

		if ( in_array( $post->post_type, Jetpack_Sync_Settings::get_setting( 'post_types_blacklist' ) ) ) {
			return false;
		}

		return $args;
	}

	// Meta
	function filter_meta( $args ) {
		if ( $this->is_post_type_allowed( $args[1] ) && $this->is_whitelisted_post_meta( $args[2] ) ) {
			return $args;
		}

		return false;
	}

	function is_whitelisted_post_meta( $meta_key ) {
		// _wpas_skip_ is used by publicize
		return in_array( $meta_key, Jetpack_Sync_Settings::get_setting( 'post_meta_whitelist' ) ) || wp_startswith( $meta_key, '_wpas_skip_' );
	}

	function is_post_type_allowed( $post_id ) {
		$post = get_post( intval( $post_id ) );
		if ( $post->post_type ) {
			return ! in_array( $post->post_type, Jetpack_Sync_Settings::get_setting( 'post_types_blacklist' ) );
		}
		return false;
	}

	function remove_embed() {
		global $wp_embed;
		remove_filter( 'the_content', array( $wp_embed, 'run_shortcode' ), 8 );
		// remove the embed shortcode since we would do the part later.
		remove_shortcode( 'embed' );
		// Attempts to embed all URLs in a post
		remove_filter( 'the_content', array( $wp_embed, 'autoembed' ), 8 );
	}

	function add_embed() {
		global $wp_embed;
		add_filter( 'the_content', array( $wp_embed, 'run_shortcode' ), 8 );
		// Shortcode placeholder for strip_shortcodes()
		add_shortcode( 'embed', '__return_false' );
		// Attempts to embed all URLs in a post
		add_filter( 'the_content', array( $wp_embed, 'autoembed' ), 8 );
	}

	// Expands wp_insert_post to include filtered content
	function filter_post_content_and_add_links( $post_object ) {
		global $post;
		$post = $post_object;

		// return non existant post
		$post_type = get_post_type_object( $post->post_type );
		if ( empty( $post_type ) || ! is_object( $post_type ) ) {
			$non_existant_post                    = new stdClass();
			$non_existant_post->ID                = $post->ID;
			$non_existant_post->post_modified     = $post->post_modified;
			$non_existant_post->post_modified_gmt = $post->post_modified_gmt;
			$non_existant_post->post_status       = 'jetpack_sync_non_registered_post_type';
			$non_existant_post->post_type         = $post->post_type;

			return $non_existant_post;
		}
		/**
		 * Filters whether to prevent sending post data to .com
		 *
		 * Passing true to the filter will prevent the post data from being sent
		 * to the WordPress.com.
		 * Instead we pass data that will still enable us to do a checksum against the
		 * Jetpacks data but will prevent us from displaying the data on in the API as well as
		 * other services.
		 *
		 * @since 4.2.0
		 *
		 * @param boolean false prevent post data from being synced to WordPress.com
		 * @param mixed $post WP_POST object
		 */
		if ( apply_filters( 'jetpack_sync_prevent_sending_post_data', false, $post ) ) {
			// We only send the bare necessary object to be able to create a checksum.
			$blocked_post                    = new stdClass();
			$blocked_post->ID                = $post->ID;
			$blocked_post->post_modified     = $post->post_modified;
			$blocked_post->post_modified_gmt = $post->post_modified_gmt;
			$blocked_post->post_status       = 'jetpack_sync_blocked';
			$blocked_post->post_type         = $post->post_type;

			return $blocked_post;
		}

		// lets not do oembed just yet.
		$this->remove_embed();

		if ( 0 < strlen( $post->post_password ) ) {
			$post->post_password = 'auto-' . wp_generate_password( 10, false );
		}

		/** This filter is already documented in core. wp-includes/post-template.php */
		if ( Jetpack_Sync_Settings::get_setting( 'render_filtered_content' ) && $post_type->public ) {
			global $shortcode_tags;
			/**
			 * Filter prevents some shortcodes from expanding.
			 *
			 * Since we can can expand some type of shortcode better on the .com side and make the
			 * expansion more relevant to contexts. For example [galleries] and subscription emails
			 *
			 * @since 4.5.0
			 *
			 * @param array of shortcode tags to remove.
			 */
			$shortcodes_to_remove        = apply_filters(
				'jetpack_sync_do_not_expand_shortcodes',
				array(
					'gallery',
					'slideshow',
				)
			);
			$removed_shortcode_callbacks = array();
			foreach ( $shortcodes_to_remove as $shortcode ) {
				if ( isset( $shortcode_tags[ $shortcode ] ) ) {
					$removed_shortcode_callbacks[ $shortcode ] = $shortcode_tags[ $shortcode ];
				}
			}

			array_map( 'remove_shortcode', array_keys( $removed_shortcode_callbacks ) );

			$post->post_content_filtered = apply_filters( 'the_content', $post->post_content );
			$post->post_excerpt_filtered = apply_filters( 'the_excerpt', $post->post_excerpt );

			foreach ( $removed_shortcode_callbacks as $shortcode => $callback ) {
				add_shortcode( $shortcode, $callback );
			}
		}

		$this->add_embed();

		if ( has_post_thumbnail( $post->ID ) ) {
			$image_attributes = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'full' );
			if ( is_array( $image_attributes ) && isset( $image_attributes[0] ) ) {
				$post->featured_image = $image_attributes[0];
			}
		}

		$post->permalink = get_permalink( $post->ID );
		$post->shortlink = wp_get_shortlink( $post->ID );

		if ( function_exists( 'amp_get_permalink' ) ) {
			$post->amp_permalink = amp_get_permalink( $post->ID );
		}

		return $post;
	}

	public function save_published( $new_status, $old_status, $post ) {
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			$this->just_published[ $post->ID ] = true;
		}

		$this->previous_status[ $post->ID ] = $old_status;
	}

	/*
	 * When publishing or updating a post, the Gutenberg editor sends two requests:
	 * 1. sent to WP REST API endpoint `wp-json/wp/v2/posts/$id`
	 * 2. sent to wp-admin/post.php `?post=$id&action=edit&classic-editor=1&meta_box=1`
	 *
	 * The 2nd request is to update post meta, which is not supported on WP REST API.
	 * When syncing post data, we will include if this was a meta box update.
	 */
	public function is_gutenberg_meta_box_update() {
		return (
			isset( $_POST['action'], $_GET['classic-editor'], $_GET['meta_box'] ) &&
			'editpost' === $_POST['action'] &&
			'1' === $_GET['classic-editor'] &&
			'1' === $_GET['meta_box']
		);
	}

	public function wp_insert_post( $post_ID, $post = null, $update = null ) {
		if ( ! is_numeric( $post_ID ) || is_null( $post ) ) {
			return;
		}

		// workaround for https://github.com/woocommerce/woocommerce/issues/18007
		if ( $post && 'shop_order' === $post->post_type ) {
			$post = get_post( $post_ID );
		}

		$previous_status = isset( $this->previous_status[ $post_ID ] ) ?
			$this->previous_status[ $post_ID ] :
			self::DEFAULT_PREVIOUS_STATE;

		$just_published = isset( $this->just_published[ $post_ID ] ) ?
			$this->just_published[ $post_ID ] :
			false;

		$state = array(
			'is_auto_save'                 => (bool) Constants::get_constant( 'DOING_AUTOSAVE' ),
			'previous_status'              => $previous_status,
			'just_published'               => $just_published,
			'is_gutenberg_meta_box_update' => $this->is_gutenberg_meta_box_update(),
		);
		/**
		 * Filter that is used to add to the post flags ( meta data ) when a post gets published
		 *
		 * @since 5.8.0
		 *
		 * @param int $post_ID the post ID
		 * @param mixed $post WP_POST object
		 * @param bool  $update Whether this is an existing post being updated or not.
		 * @param mixed $state state
		 *
		 * @module sync
		 */
		do_action( 'jetpack_sync_save_post', $post_ID, $post, $update, $state );
		unset( $this->previous_status[ $post_ID ] );
		$this->send_published( $post_ID, $post );
	}

	public function send_published( $post_ID, $post ) {
		if ( ! isset( $this->just_published[ $post_ID ] ) ) {
			return;
		}

		// Post revisions cause race conditions where this send_published add the action before the actual post gets synced
		if ( wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) ) {
			return;
		}

		$post_flags = array(
			'post_type' => $post->post_type,
		);

		$author_user_object = get_user_by( 'id', $post->post_author );
		if ( $author_user_object ) {
			$post_flags['author'] = array(
				'id'              => $post->post_author,
				'wpcom_user_id'   => get_user_meta( $post->post_author, 'wpcom_user_id', true ),
				'display_name'    => $author_user_object->display_name,
				'email'           => $author_user_object->user_email,
				'translated_role' => Jetpack::translate_user_to_role( $author_user_object ),
			);
		}

		/**
		 * Filter that is used to add to the post flags ( meta data ) when a post gets published
		 *
		 * @since 4.4.0
		 *
		 * @param mixed array post flags that are added to the post
		 * @param mixed $post WP_POST object
		 */
		$flags = apply_filters( 'jetpack_published_post_flags', $post_flags, $post );

		/**
		 * Action that gets synced when a post type gets published.
		 *
		 * @since 4.4.0
		 *
		 * @param int $post_ID
		 * @param mixed array $flags post flags that are added to the post
		 */
		do_action( 'jetpack_published_post', $post_ID, $flags );
		unset( $this->just_published[ $post_ID ] );

		/**
		 * Send additional sync action for Activity Log when post is a Customizer publish
		 */
		if ( 'customize_changeset' == $post->post_type ) {
			$post_content = json_decode( $post->post_content, true );
			foreach ( $post_content as $key => $value ) {
				// Skip if it isn't a widget
				if ( 'widget_' != substr( $key, 0, strlen( 'widget_' ) ) ) {
					continue;
				}
				// Change key from "widget_archives[2]" to "archives-2"
				$key = str_replace( 'widget_', '', $key );
				$key = str_replace( '[', '-', $key );
				$key = str_replace( ']', '', $key );

				global $wp_registered_widgets;
				if ( isset( $wp_registered_widgets[ $key ] ) ) {
					$widget_data = array(
						'name'  => $wp_registered_widgets[ $key ]['name'],
						'id'    => $key,
						'title' => $value['value']['title'],
					);
					do_action( 'jetpack_widget_edited', $widget_data );
				}
			}
		}
	}

	public function expand_post_ids( $args ) {
		list( $post_ids, $previous_interval_end) = $args;

		$posts = array_filter( array_map( array( 'WP_Post', 'get_instance' ), $post_ids ) );
		$posts = array_map( array( $this, 'filter_post_content_and_add_links' ), $posts );
		$posts = array_values( $posts ); // reindex in case posts were deleted

		return array(
			$posts,
			$this->get_metadata( $post_ids, 'post', Jetpack_Sync_Settings::get_setting( 'post_meta_whitelist' ) ),
			$this->get_term_relationships( $post_ids ),
			$previous_interval_end,
		);
	}
}
