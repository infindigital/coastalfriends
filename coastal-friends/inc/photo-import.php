<?php
/**
 * Appearance → Coastal Friends Photos: install the site's photos from the
 * coastal-friends-photos-N.zip files, from the dashboard (no FTP/File Manager).
 *
 * Each zip is uploaded on its own request (so every upload stays small) and
 * only files listed in inc/photos.json are written, into the standard
 * wp-content/uploads/ folder (they survive theme updates). cf_assets_url()
 * then serves them from there.
 *
 * @package Coastal_Friends
 */

defined( 'ABSPATH' ) || exit;

/**
 * Expected photo files: relative path (e.g. 2024/12/x.jpeg.webp) => size in bytes.
 *
 * @return array
 */
function cf_photos_manifest() {
	static $list = null;
	if ( null === $list ) {
		$file = get_template_directory() . '/inc/photos.json';
		$list = file_exists( $file ) ? (array) json_decode( file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
	return $list;
}

/**
 * How many expected photos are installed.
 *
 * @return array{0:int,1:int} installed, total.
 */
function cf_photos_status() {
	$up    = wp_get_upload_dir();
	$list  = cf_photos_manifest();
	$found = 0;
	foreach ( $list as $rel => $size ) {
		if ( file_exists( $up['basedir'] . '/' . $rel ) || file_exists( get_template_directory() . '/assets/uploads/' . $rel ) ) {
			$found++;
		}
	}
	return array( $found, count( $list ) );
}

/**
 * Admin menu entry.
 */
function cf_photos_menu() {
	add_theme_page( __( 'Coastal Friends Photos', 'coastal-friends' ), __( 'Coastal Friends Photos', 'coastal-friends' ), 'upload_files', 'cf-photos', 'cf_photos_page' );
}
add_action( 'admin_menu', 'cf_photos_menu' );

/**
 * Reminder until all photos are installed.
 */
function cf_photos_notice() {
	if ( ! current_user_can( 'upload_files' ) || ( isset( $_GET['page'] ) && 'cf-photos' === $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	list( $found, $total ) = cf_photos_status();
	if ( $total && $found < $total ) {
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			/* translators: 1: installed photos, 2: total photos. */
			esc_html( sprintf( __( 'Coastal Friends theme: %1$d of %2$d photos are installed.', 'coastal-friends' ), $found, $total ) ),
			esc_url( admin_url( 'themes.php?page=cf-photos' ) ),
			esc_html__( 'Upload the photo files', 'coastal-friends' )
		);
	}
}
add_action( 'admin_notices', 'cf_photos_notice' );

/**
 * The admin page.
 */
function cf_photos_page() {
	list( $found, $total ) = cf_photos_status();
	$max = size_format( wp_max_upload_size() );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Coastal Friends Photos', 'coastal-friends' ); ?></h1>
		<p id="cf-status" style="font-size:15px">
			<?php
			/* translators: 1: installed photos, 2: total photos. */
			echo esc_html( sprintf( __( '%1$d of %2$d photos installed.', 'coastal-friends' ), $found, $total ) );
			?>
		</p>
		<p><?php esc_html_e( 'Select all the coastal-friends-photos-N.zip files at once (hold Ctrl or Cmd to pick several) and click Upload. They are sent one at a time; keep this page open until it says Done.', 'coastal-friends' ); ?></p>
		<p>
			<?php
			/* translators: %s: max upload size. */
			echo esc_html( sprintf( __( 'Your site accepts files up to %s each.', 'coastal-friends' ), $max ) );
			?>
		</p>
		<p><input type="file" id="cf-files" accept=".zip" multiple> <button class="button button-primary" id="cf-go"><?php esc_html_e( 'Upload', 'coastal-friends' ); ?></button></p>
		<ol id="cf-log"></ol>
	</div>
	<script>
	( function () {
		const btn = document.getElementById( 'cf-go' ), input = document.getElementById( 'cf-files' );
		const log = document.getElementById( 'cf-log' ), status = document.getElementById( 'cf-status' );
		btn.addEventListener( 'click', async () => {
			const files = [ ...input.files ];
			if ( ! files.length ) { alert( <?php echo wp_json_encode( __( 'Choose the photo zip files first.', 'coastal-friends' ) ); ?> ); return; }
			btn.disabled = true;
			for ( const f of files ) {
				const li = document.createElement( 'li' ); li.textContent = f.name + ' … uploading'; log.appendChild( li );
				const fd = new FormData();
				fd.append( 'action', 'cf_import_photos' );
				fd.append( '_wpnonce', <?php echo wp_json_encode( wp_create_nonce( 'cf_import_photos' ) ); ?> );
				fd.append( 'zip', f );
				try {
					const r = await fetch( ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } );
					const j = await r.json().catch( () => null );
					if ( j && j.success ) { li.textContent = f.name + ' ✓ ' + j.data.message; status.textContent = j.data.status; }
					else { li.textContent = f.name + ' ✗ ' + ( j && j.data ? j.data : 'HTTP ' + r.status + ' (the file may be larger than the upload limit)' ); li.style.color = '#b32d2e'; }
				} catch ( e ) { li.textContent = f.name + ' ✗ ' + e.message; li.style.color = '#b32d2e'; }
			}
			const done = document.createElement( 'li' ); done.innerHTML = '<strong>' + <?php echo wp_json_encode( __( 'Done.', 'coastal-friends' ) ); ?> + '</strong>'; log.appendChild( done );
			btn.disabled = false;
		} );
	} )();
	</script>
	<?php
}

/**
 * AJAX: extract one photo zip. Only files listed in photos.json are written.
 */
function cf_import_photos() {
	if ( ! current_user_can( 'upload_files' ) || ! check_ajax_referer( 'cf_import_photos', '_wpnonce', false ) ) {
		wp_send_json_error( __( 'Not allowed.', 'coastal-friends' ), 403 );
	}
	if ( empty( $_FILES['zip']['tmp_name'] ) || ! is_uploaded_file( $_FILES['zip']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		wp_send_json_error( __( 'The upload did not arrive (it may be larger than the upload limit).', 'coastal-friends' ) );
	}
	if ( ! class_exists( 'ZipArchive' ) ) {
		wp_send_json_error( __( 'This server has no ZipArchive (PHP zip extension).', 'coastal-friends' ) );
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $_FILES['zip']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		wp_send_json_error( __( 'This is not a valid zip file, or it arrived incomplete. Please try again.', 'coastal-friends' ) );
	}
	$expected = cf_photos_manifest();
	$up       = wp_get_upload_dir();
	$written  = 0;
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = $zip->getNameIndex( $i );
		if ( ! isset( $expected[ $name ] ) ) {
			continue; // Only known photo files; nothing else is ever written.
		}
		$data = $zip->getFromIndex( $i );
		if ( false === $data ) {
			continue;
		}
		$dest = $up['basedir'] . '/' . $name;
		wp_mkdir_p( dirname( $dest ) );
		if ( false !== file_put_contents( $dest, $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$written++;
		}
	}
	$zip->close();
	list( $found, $total ) = cf_photos_status();
	wp_send_json_success(
		array(
			/* translators: %d: number of photos. */
			'message' => sprintf( __( '%d photos installed', 'coastal-friends' ), $written ),
			/* translators: 1: installed photos, 2: total photos. */
			'status'  => sprintf( __( '%1$d of %2$d photos installed.', 'coastal-friends' ), $found, $total ),
		)
	);
}
add_action( 'wp_ajax_cf_import_photos', 'cf_import_photos' );
