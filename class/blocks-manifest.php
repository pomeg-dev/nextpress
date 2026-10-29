<?php
/**
 * Durable store for the block manifest (the compiled fields.json files).
 *
 * The manifest is pushed from the frontend (`npm run blocks:push` →
 * POST nextpress/blocks/sync) and kept in a non-autoloaded option, so block
 * registration never depends on the frontend being deployed or reachable.
 * Sites that haven't had a push yet fall back to pulling /api/blocks once,
 * and that result is persisted the same way.
 *
 * The manifest is stored as the raw JSON string: decoding to PHP arrays would
 * turn objects like `{ "0": "None" }` into lists, so GET /blocks/manifest
 * couldn't hand back exactly what was pushed for the CLI to diff against.
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class Blocks_Manifest {
  const OPTION         = 'nextpress_blocks_manifest';
  const HISTORY_OPTION = 'nextpress_blocks_manifest_history';
  const HISTORY_LIMIT  = 5;

  /**
   * Helpers.
   */
  public $helpers;

  /**
   * Decoded manifest, memoised per request.
   */
  private $manifest = null;

  private $pull_attempted = false;

  public function __construct( $helpers ) {
    $this->helpers = $helpers;
  }

  /**
   * Blocks keyed by theme ({ theme: Block[] }), or a flat Block[] for the
   * given theme(s) — the same shapes /api/blocks returns, so callers of the
   * old fetch_blocks_from_api() are unchanged.
   */
  public function get_blocks( $theme = null ) {
    $manifest = $this->get() ?: $this->pull();
    if ( ! $manifest ) {
      return null;
    }

    $themes = $manifest['themes'];
    if ( ! $theme ) {
      return $themes;
    }

    $blocks = [];
    foreach ( (array) $theme as $name ) {
      if ( ! empty( $themes[ $name ] ) ) {
        $blocks = array_merge( $blocks, $themes[ $name ] );
      }
    }
    return $blocks;
  }

  /**
   * Stored manifest as an array, or null if nothing has been stored yet.
   */
  public function get() {
    if ( $this->manifest === null ) {
      $record = get_option( self::OPTION );
      $this->manifest = $record ? $this->decode( $record ) : false;
    }
    return $this->manifest ?: null;
  }

  /**
   * Stored record without the manifest body (for status displays).
   */
  public function get_meta() {
    $record = get_option( self::OPTION );
    if ( ! $record ) {
      return null;
    }
    unset( $record['json'] );
    return $record;
  }

  /**
   * Stored manifest JSON, decoded to objects so it re-encodes byte-for-byte.
   */
  public function get_raw() {
    $record = get_option( self::OPTION );
    if ( ! $record ) {
      return null;
    }
    $manifest = json_decode( $record['json'] );
    $manifest->hash      = $record['hash'];
    $manifest->source    = $record['source'];
    $manifest->stored_at = $record['stored_at'];
    return $manifest;
  }

  /**
   * Validate and store a manifest JSON string. Returns the stored record meta
   * or a WP_Error. The previous manifest is kept in history for rollback.
   */
  public function store( $json, $source = 'push' ) {
    $manifest = json_decode( $json, true );
    $valid = $this->validate( $manifest );
    if ( is_wp_error( $valid ) ) {
      return $valid;
    }

    $record = [
      'json'         => $json,
      'hash'         => $manifest['hash'] ?? hash( 'sha256', wp_json_encode( $manifest['themes'] ) ),
      'generated_at' => $manifest['generatedAt'] ?? null,
      'stored_at'    => time(),
      'source'       => $source,
      'blocks'       => $this->count_blocks( $manifest['themes'] ),
      'themes'       => array_keys( $manifest['themes'] ),
    ];

    $previous = get_option( self::OPTION );
    if ( $previous && $previous['hash'] !== $record['hash'] ) {
      $history = get_option( self::HISTORY_OPTION, [] );
      array_unshift( $history, $previous );
      update_option( self::HISTORY_OPTION, array_slice( $history, 0, self::HISTORY_LIMIT ), false );
    }

    update_option( self::OPTION, $record, false );
    $this->manifest = null;

    do_action( 'nextpress_blocks_manifest_stored', $record );

    unset( $record['json'] );
    return $record;
  }

  /**
   * Restore the most recent manifest from history.
   */
  public function rollback() {
    $history = get_option( self::HISTORY_OPTION, [] );
    $previous = array_shift( $history );
    if ( ! $previous ) {
      return new \WP_Error( 'nextpress_no_history', 'No previous manifest to roll back to' );
    }

    update_option( self::OPTION, $previous, false );
    update_option( self::HISTORY_OPTION, $history, false );
    $this->manifest = null;

    unset( $previous['json'] );
    return $previous;
  }

  public function get_history() {
    return array_map(
      function( $record ) {
        unset( $record['json'] );
        return $record;
      },
      get_option( self::HISTORY_OPTION, [] )
    );
  }

  /**
   * Legacy fallback: fetch /api/blocks from the frontend and persist it, so
   * this only happens until the first success (or a push).
   */
  public function pull( $force = false ) {
    // One attempt per request unless forced (admin "Pull now").
    if ( $this->pull_attempted && ! $force ) {
      return null;
    }
    $this->pull_attempted = true;

    if ( $force ) {
      $this->helpers->cache_flush_group( 'nextpress_blocks' );
    }

    $themes = $this->helpers->fetch_blocks_from_api( null, 'manifest' );
    if ( empty( $themes ) || ! is_array( $themes ) ) {
      return null;
    }

    $stored = $this->store( wp_json_encode( [ 'version' => 1, 'themes' => $themes ] ), 'pull' );
    if ( is_wp_error( $stored ) ) {
      error_log( 'Nextpress: pulled blocks manifest is invalid: ' . $stored->get_error_message() );
      return null;
    }
    return $this->get();
  }

  public function validate( $manifest ) {
    if ( ! is_array( $manifest ) || empty( $manifest['themes'] ) || ! is_array( $manifest['themes'] ) ) {
      return new \WP_Error( 'nextpress_invalid_manifest', 'Manifest must contain a non-empty "themes" object', [ 'status' => 400 ] );
    }

    foreach ( $manifest['themes'] as $theme => $blocks ) {
      if ( ! is_array( $blocks ) ) {
        return new \WP_Error( 'nextpress_invalid_manifest', "Theme \"$theme\" must be a list of blocks", [ 'status' => 400 ] );
      }
      foreach ( $blocks as $block ) {
        if (
          empty( $block['id'] ) ||
          empty( $block['blockName'] ) ||
          ! isset( $block['fields'] ) ||
          ! is_array( $block['fields'] )
        ) {
          return new \WP_Error( 'nextpress_invalid_manifest', "Theme \"$theme\" has a block without id/blockName/fields", [ 'status' => 400 ] );
        }
      }
    }

    return true;
  }

  private function decode( $record ) {
    $manifest = json_decode( $record['json'] ?? '', true );
    return is_array( $manifest ) && isset( $manifest['themes'] ) ? $manifest : false;
  }

  private function count_blocks( $themes ) {
    return array_sum( array_map( 'count', $themes ) );
  }
}
