<?php
/**
 * Mint short-lived IndieAuth tokens and wp-admin cookies for the web-testing
 * suite, on a disposable local site. Run with WP-CLI's eval-file; prints JSON.
 *
 *   studio wp eval-file <this file>       (from the site directory)
 *
 * Users (created when missing, so a fresh site works too):
 *   outpost_e2e_editor      editor: can create posts and upload media.
 *   outpost_e2e_subscriber  subscriber: no edit_posts, so it can't create a
 *                           post (Micropub) or open the wp-admin Appearance
 *                           page ("anyone who can edit posts", docs/settings).
 *
 * The token shape matches tests/e2e/global-setup.ts. `expired` is an editor
 * token that lapses one second after minting, for the expired sign-in tests.
 * Output goes to git-ignored files only; never commit it.
 *
 * @package Outpost
 */

$outpost_wt_users = array(
	'editor'     => array( 'outpost_e2e_editor', 'editor' ),
	'subscriber' => array( 'outpost_e2e_subscriber', 'subscriber' ),
);

$outpost_wt_out = array();
foreach ( $outpost_wt_users as $outpost_wt_key => list( $outpost_wt_login, $outpost_wt_role ) ) {
	$outpost_wt_user = get_user_by( 'login', $outpost_wt_login );
	$outpost_wt_uid  = $outpost_wt_user ? $outpost_wt_user->ID : wp_insert_user(
		array(
			'user_login' => $outpost_wt_login,
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => $outpost_wt_login . '@example.test',
			'role'       => $outpost_wt_role,
		)
	);
	$outpost_wt_out[ $outpost_wt_key ] = array(
		'login'  => $outpost_wt_login,
		'userId' => $outpost_wt_uid,
		'token'  => outpost_wt_mint( $outpost_wt_uid, HOUR_IN_SECONDS ),
		'me'     => get_author_posts_url( $outpost_wt_uid ),
		'cookies' => outpost_wt_cookies( $outpost_wt_uid ),
	);
}
$outpost_wt_out['expired'] = array(
	'login' => 'outpost_e2e_editor',
	'token' => outpost_wt_mint( $outpost_wt_out['editor']['userId'], 1 ),
	'me'    => $outpost_wt_out['editor']['me'],
);

/**
 * An IndieAuth bearer token for a user, as the token endpoint would issue it.
 *
 * @param int $uid     User ID.
 * @param int $seconds Lifetime.
 */
function outpost_wt_mint( $uid, $seconds ) {
	return ( new IndieAuth\Token\User( '_indieauth_token_', $uid ) )->set(
		array(
			'token_type' => 'Bearer',
			'scope'      => 'create update media',
			'me'         => get_author_posts_url( $uid ),
			'client_id'  => home_url( '/post/' ),
			'issued_by'  => rest_url( 'indieauth/1.0/token' ),
			'issued_at'  => time(),
			'user'       => $uid,
		),
		$seconds
	);
}

/**
 * The wp-admin auth and logged-in cookies for a user (plain HTTP site).
 *
 * @param int $uid User ID.
 */
function outpost_wt_cookies( $uid ) {
	$expires = time() + HOUR_IN_SECONDS;
	return array(
		array(
			'name'  => AUTH_COOKIE,
			'value' => wp_generate_auth_cookie( $uid, $expires, 'auth' ),
			'path'  => '/wp-admin',
		),
		array(
			'name'  => LOGGED_IN_COOKIE,
			'value' => wp_generate_auth_cookie( $uid, $expires, 'logged_in' ),
			'path'  => '/',
		),
	);
}

echo wp_json_encode( $outpost_wt_out );
