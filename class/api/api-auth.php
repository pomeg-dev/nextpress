<?php
/**
 * Nextpress API auth class
 * Verifies HMAC-signed requests from the Next.js frontend, used as the
 * permission_callback for all public nextpress/* REST routes.
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class API_Auth {
  /**
   * How much clock drift (in seconds) to tolerate between the frontend
   * signing a request and WordPress verifying it. Also bounds how long a
   * captured signature could be replayed.
   */
  const TIMESTAMP_TOLERANCE = 300;

  /**
   * permission_callback for nextpress REST routes.
   *
   * If NEXTPRESS_API_SECRET isn't configured, the route stays open (matches
   * pre-existing behaviour) so this is opt-in until a secret is set.
   */
  public static function verify_signature( $request ) {
    if ( ! defined( 'NEXTPRESS_API_SECRET' ) || ! NEXTPRESS_API_SECRET ) {
      return true;
    }

    $timestamp = $request->get_header( 'x-nextpress-timestamp' );
    $signature = $request->get_header( 'x-nextpress-signature' );

    if ( ! $timestamp || ! $signature || ! ctype_digit( $timestamp ) ) {
      return new \WP_Error( 'nextpress_missing_signature', 'Missing or invalid request signature', [ 'status' => 401 ] );
    }

    if ( abs( time() - (int) $timestamp ) > self::TIMESTAMP_TOLERANCE ) {
      return new \WP_Error( 'nextpress_expired_signature', 'Request signature has expired', [ 'status' => 401 ] );
    }

    $payload = $request->get_method() . ':' . $request->get_route() . ':' . $timestamp;
    $expected = hash_hmac( 'sha256', $payload, NEXTPRESS_API_SECRET );

    if ( ! hash_equals( $expected, $signature ) ) {
      return new \WP_Error( 'nextpress_invalid_signature', 'Invalid request signature', [ 'status' => 401 ] );
    }

    return true;
  }

  /**
   * Shared secret for the blocks sync routes: wp-config NEXTPRESS_SYNC_SECRET,
   * else one generated in Nextpress > Blocks (so self-hosted sites can enable
   * pushes without a code deploy).
   */
  public static function sync_secret() {
    if ( defined( 'NEXTPRESS_SYNC_SECRET' ) && NEXTPRESS_SYNC_SECRET ) {
      return NEXTPRESS_SYNC_SECRET;
    }
    return get_option( 'nextpress_sync_secret' ) ?: '';
  }

  /**
   * permission_callback for the blocks sync routes. Unlike verify_signature()
   * this never passes through: a secret must be configured, and the signature
   * also covers a hash of the body, METHOD:ROUTE:TIMESTAMP:sha256(body).
   * Admins (e.g. via application passwords) are allowed without a signature.
   */
  public static function verify_sync_signature( $request ) {
    if ( current_user_can( 'manage_options' ) ) {
      return true;
    }

    $secret = self::sync_secret();
    if ( ! $secret ) {
      return new \WP_Error( 'nextpress_sync_disabled', 'Block sync is not configured on this site', [ 'status' => 403 ] );
    }

    $timestamp = $request->get_header( 'x-nextpress-timestamp' );
    $signature = $request->get_header( 'x-nextpress-signature' );

    if ( ! $timestamp || ! $signature || ! ctype_digit( $timestamp ) ) {
      return new \WP_Error( 'nextpress_missing_signature', 'Missing or invalid request signature', [ 'status' => 401 ] );
    }

    if ( abs( time() - (int) $timestamp ) > self::TIMESTAMP_TOLERANCE ) {
      return new \WP_Error( 'nextpress_expired_signature', 'Request signature has expired', [ 'status' => 401 ] );
    }

    $body_hash = hash( 'sha256', (string) $request->get_body() );
    $payload   = $request->get_method() . ':' . $request->get_route() . ':' . $timestamp . ':' . $body_hash;
    $expected  = hash_hmac( 'sha256', $payload, $secret );

    if ( ! hash_equals( $expected, $signature ) ) {
      return new \WP_Error( 'nextpress_invalid_signature', 'Invalid request signature', [ 'status' => 401 ] );
    }

    return true;
  }
}
