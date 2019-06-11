<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Executes after installing via Composer.
 *
 * @author kraftbj
 * @package Automattic/jetpack-standards
 */

namespace Automattic\Jetpack;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Script\Event;

/**
 * Standards class.
 *
 * @package Automattic\jetpack-standards
 */
class Standards implements PluginInterface, EventSubscriberInterface {
	/**
	 * List of directories where we want to look for files, and where to copy them.
	 *
	 * @var array $standard_dirs
	 */
	private static $standard_dirs = array(
		'/standards' => '',
		'/github'    => '/.github',
	);

	/**
	 * Composer Plugin activation.
	 *
	 * Unused. Intended for changing Composer internals, which we don't need.
	 *
	 * @param Composer    $composer Composer.
	 * @param IOInterface $io       IOInterface.
	 */
	public function activate( Composer $composer, IOInterface $io ) {
	}

	/**
	 * Copies standards and GitHub templates from this plugin to the root directory of the included projects.
	 *
	 * @param Event $event Script event class.
	 */
	public static function install( Event $event ) {
		$vendor_dir  = $event->getComposer()->getConfig()->get( 'vendor-dir' );
		$plugin_root = dirname( $vendor_dir );

		foreach ( self::$standard_dirs as $source => $dest ) {
			self::xcopy( ( __DIR__ ) . $source, $plugin_root . $dest );
		}
	}

	/**
	 * Copy a file, or recursively copy a folder and its contents
	 *
	 * @author  Aidan Lister <aidan@php.net>
	 * @version 1.0.1
	 * @link    http://aidanlister.com/2004/04/recursively-copying-directories-in-php/
	 *
	 * @param string $source Source path.
	 * @param string $dest   Destination path.
	 *
	 * @return bool Returns TRUE on success, FALSE on failure
	 */
	private static function xcopy( $source, $dest ) {
		// Simple copy for a file.
		if ( is_file( $source ) ) {
			return copy( $source, $dest );
		}
		// Make destination directory.
		if ( ! is_dir( $dest ) ) {
			mkdir( $dest );
		}
		// Loop through the folder.
		$dir = dir( $source );
		while ( false !== ( $entry = $dir->read() ) ) {
			// Skip pointers.
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			// Deep copy directories.
			self::xcopy( "$source/$entry", "$dest/$entry" );
		}
		// Clean up.
		$dir->close();
		return true;
	}

	/**
	 * Delete a file, or recursively delete all contensts of a folder.
	 *
	 * @param array $copied    Directory or file where we copied files from on installation.
	 * @param array $to_delete Directory or file where we want to look for files, and where to copy them.
	 *
	 * @return bool Returns TRUE on success, FALSE on failure
	 */
	private static function xdelete( $copied, $to_delete ) {
		// Simple delete for a file, if it matches one of the files that were copied.
		if (
			is_file( $to_delete )
			&& basename( $to_delete ) === basename( $copied )
		) {
			return unlink( $to_delete );
		}

		$dir = dir( $to_delete );
		while ( false !== ( $entry = $dir->read() ) ) {
			// Skip pointers.
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			// Delete in directories.
			self::xdelete( "$copied/$entry", "$to_delete/$entry" );
		}
		// Clean up.
		$dir->close();
		return true;
	}

	/**
	 * Remove any of the files that were copied when the package is removed.
	 *
	 * @param Event $event Script event class.
	 */
	public static function cleanup( Event $event ) {
		$vendor_dir  = $event->getComposer()->getConfig()->get( 'vendor-dir' );
		$plugin_root = dirname( $vendor_dir );

		foreach ( self::$standard_dirs as $source => $dest ) {
			self::xdelete( $plugin_root . $source, $plugin_root . $dest );
		}
	}

	/**
	 * Maps internal functions to Composer events.
	 *
	 * We specifically add both `post-install-cmd` and `post-update-cmd`.
	 * The former fires when composer install happens with a composer.lock.
	 * The second fires when composer install happens without a composer.lock (thus an update).
	 *
	 * @return array
	 */
	public static function getSubscribedEvents() {
		return array(
			'post-install-cmd'      => 'install',
			'post-update-cmd'       => 'install',
			'pre-package-uninstall' => 'cleanup',
		);
	}
}
