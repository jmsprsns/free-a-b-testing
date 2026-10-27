<?php
/**
 * REST controller for variants (/wp/v2/fabt-variants), used by the block
 * editor to load and save them.
 *
 * WordPress's own posts controller lets anyone read a *published* post of
 * any type that's in the REST API. Variants are published (so the editor
 * shows "Save" rather than "Publish") but are unreleased page content, so
 * every read here needs the right to edit the variant.
 *
 * @package Free_A_B_Testing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Variants REST controller.
 */
class Free_A_B_Testing_Variants_Controller extends WP_REST_Posts_Controller {

	/**
	 * Listing variants needs manage_options, whatever the request asks for.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! current_user_can( Free_A_B_Testing_Tests::CAP ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'free-a-b-testing' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return parent::get_items_permissions_check( $request );
	}

	/**
	 * Reading one variant needs the right to edit it.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function check_read_permission( $post ) {
		return current_user_can( 'edit_post', $post->ID ) && parent::check_read_permission( $post );
	}
}
