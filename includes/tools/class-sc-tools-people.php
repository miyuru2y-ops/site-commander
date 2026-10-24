<?php
/**
 * Comments and users.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Tools_People {

	public static function register() {
		$t = 'SC_Tools';

		$t::add( 'list_comments', array(
			'group'       => 'people',
			'description' => 'List comments, e.g. the moderation queue (status "hold") or spam.',
			'properties'  => array(
				'status'   => $t::p_enum( array( 'all', 'hold', 'approve', 'spam', 'trash' ), 'Default hold (awaiting moderation).' ),
				'post_id'  => $t::p( 'integer', 'Only comments on this post.' ),
				'search'   => $t::p( 'string', 'Search text.' ),
				'per_page' => $t::p( 'integer', 'Default 30.' ),
			),
			'callback'    => array( __CLASS__, 'list_comments' ),
		) );

		$t::add( 'moderate_comments', array(
			'group'       => 'people',
			'description' => 'Approve, unapprove, mark as spam, or trash one or more comments.',
			'properties'  => array(
				'ids'    => $t::p_list( 'Comment IDs.', 'integer' ),
				'action' => $t::p_enum( array( 'approve', 'unapprove', 'spam', 'trash' ), 'What to do.' ),
			),
			'required'    => array( 'ids', 'action' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'moderate' ),
		) );

		$t::add( 'reply_to_comment', array(
			'group'       => 'people',
			'description' => 'Post a reply to a comment as the site admin (approved immediately). Draft the text with the user first.',
			'properties'  => array(
				'comment_id' => $t::p( 'integer', 'Comment to reply to.' ),
				'content'    => $t::p( 'string', 'Reply text.' ),
			),
			'required'    => array( 'comment_id', 'content' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'reply' ),
		) );

		$t::add( 'list_users', array(
			'group'       => 'people',
			'description' => 'List users with roles (no passwords or private data beyond name/email).',
			'properties'  => array(
				'role'   => $t::p( 'string', 'Filter by role, e.g. author.' ),
				'search' => $t::p( 'string', 'Search name/email.' ),
			),
			'callback'    => array( __CLASS__, 'list_users' ),
		) );

		$t::add( 'save_user', array(
			'group'       => 'people',
			'description' => 'Create a user (no id; WordPress emails them a link to set their password) or update a user\'s name, bio, website, email or role. Cannot create or modify administrators.',
			'properties'  => array(
				'id'           => $t::p( 'integer', 'User ID to update.' ),
				'username'     => $t::p( 'string', 'Login name (new users).' ),
				'email'        => $t::p( 'string', 'Email.' ),
				'first_name'   => $t::p( 'string', 'First name.' ),
				'last_name'    => $t::p( 'string', 'Last name.' ),
				'display_name' => $t::p( 'string', 'Public name.' ),
				'bio'          => $t::p( 'string', 'Biographical info (author box, good for E-E-A-T).' ),
				'website'      => $t::p( 'string', 'Website URL.' ),
				'role'         => $t::p_enum( array( 'editor', 'author', 'contributor', 'subscriber', 'shop_manager', 'customer' ), 'Role.' ),
			),
			'write'       => true,
			'requires'    => 'allow_users',
			'callback'    => array( __CLASS__, 'save_user' ),
		) );
	}

	public static function list_comments( $a ) {
		$status = SC_Tools::str( $a, 'status', 'hold' );
		$args   = array(
			'status' => $status,
			'number' => min( 100, SC_Tools::int( $a, 'per_page', 30 ) ),
			'search' => SC_Tools::str( $a, 'search', '' ),
		);
		if ( $pid = SC_Tools::int( $a, 'post_id' ) ) {
			$args['post_id'] = $pid;
		}
		return array_map(
			function ( $c ) {
				return array(
					'id'      => (int) $c->comment_ID,
					'post'    => array( 'id' => (int) $c->comment_post_ID, 'title' => get_the_title( $c->comment_post_ID ) ),
					'author'  => $c->comment_author,
					'email'   => $c->comment_author_email,
					'date'    => $c->comment_date,
					'status'  => wp_get_comment_status( $c ),
					'parent'  => (int) $c->comment_parent,
					'content' => $c->comment_content,
				);
			},
			get_comments( $args )
		);
	}

	public static function moderate( $a ) {
		$map    = array( 'approve' => 'approve', 'unapprove' => 'hold', 'spam' => 'spam', 'trash' => 'trash' );
		$action = SC_Tools::str( $a, 'action' );
		if ( ! isset( $map[ $action ] ) ) {
			SC_Tools::fail( 'Invalid action.' );
		}
		$n = 0;
		foreach ( (array) SC_Tools::arr( $a, 'ids' ) as $id ) {
			if ( wp_set_comment_status( (int) $id, $map[ $action ] ) ) {
				$n++;
			}
		}
		return array( 'message' => "$action: $n comments." );
	}

	public static function reply( $a ) {
		$parent = get_comment( (int) $a['comment_id'] );
		if ( ! $parent ) {
			SC_Tools::fail( 'Comment not found.' );
		}
		$user = wp_get_current_user();
		$id   = wp_insert_comment(
			wp_slash(
				array(
					'comment_post_ID'      => $parent->comment_post_ID,
					'comment_parent'       => $parent->comment_ID,
					'comment_content'      => (string) $a['content'],
					'user_id'              => $user->ID,
					'comment_author'       => $user->display_name,
					'comment_author_email' => $user->user_email,
					'comment_author_url'   => $user->user_url,
					'comment_approved'     => 1,
				)
			)
		);
		if ( ! $id ) {
			SC_Tools::fail( 'Could not post the reply.' );
		}
		if ( '0' === $parent->comment_approved ) {
			wp_set_comment_status( $parent->comment_ID, 'approve' );
		}
		return array( 'message' => "Replied to comment #{$parent->comment_ID}.", 'id' => (int) $id, 'link' => get_comment_link( $id ) );
	}

	public static function list_users( $a ) {
		$args = array( 'number' => 200 );
		if ( $r = SC_Tools::str( $a, 'role' ) ) {
			$args['role'] = $r;
		}
		if ( $s = SC_Tools::str( $a, 'search' ) ) {
			$args['search'] = '*' . $s . '*';
		}
		return array_map(
			function ( $u ) {
				return array(
					'id'           => $u->ID,
					'username'     => $u->user_login,
					'display_name' => $u->display_name,
					'email'        => $u->user_email,
					'roles'        => $u->roles,
					'posts'        => (int) count_user_posts( $u->ID ),
					'registered'   => $u->user_registered,
				);
			},
			get_users( $args )
		);
	}

	public static function save_user( $a ) {
		$role = SC_Tools::str( $a, 'role' );
		if ( $role && ( 'administrator' === $role || ! get_role( $role ) ) ) {
			SC_Tools::fail( "Role '$role' is not allowed or does not exist." );
		}
		$data = array();
		foreach ( array( 'first_name', 'last_name', 'display_name' ) as $k ) {
			if ( isset( $a[ $k ] ) ) {
				$data[ $k ] = sanitize_text_field( $a[ $k ] );
			}
		}
		if ( isset( $a['bio'] ) ) {
			$data['description'] = sanitize_textarea_field( $a['bio'] );
		}
		if ( isset( $a['website'] ) ) {
			$data['user_url'] = esc_url_raw( $a['website'] );
		}
		if ( isset( $a['email'] ) ) {
			if ( ! is_email( $a['email'] ) ) {
				SC_Tools::fail( 'Invalid email.' );
			}
			$data['user_email'] = sanitize_email( $a['email'] );
		}
		if ( $role ) {
			$data['role'] = $role;
		}
		$id = SC_Tools::int( $a, 'id' );
		if ( $id ) {
			$user = get_userdata( $id );
			if ( ! $user ) {
				SC_Tools::fail( 'User not found.' );
			}
			if ( user_can( $user, 'manage_options' ) ) {
				SC_Tools::fail( 'Administrators cannot be changed through Site Commander.' );
			}
			$data['ID'] = $id;
			$res        = wp_update_user( $data );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return array( 'message' => "Updated user #$id." );
		}
		$login = sanitize_user( (string) SC_Tools::str( $a, 'username', '' ), true );
		if ( ! $login || empty( $data['user_email'] ) ) {
			SC_Tools::fail( 'username and email are required to create a user.' );
		}
		$data['user_login'] = $login;
		$data['user_pass']  = wp_generate_password( 24 );
		$data['role']       = $role ? $role : get_option( 'default_role', 'subscriber' );
		$new                = wp_insert_user( $data );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		wp_new_user_notification( $new, null, 'user' );
		return array( 'message' => "Created user $login (#$new) as {$data['role']}. They were emailed a link to set a password.", 'id' => $new );
	}
}
