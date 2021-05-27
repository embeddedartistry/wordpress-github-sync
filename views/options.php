<?php
/**
 * Options Page View.
 * @package Wordpress_GitHub_Sync
 */

?>
<div class="wrap">
	<h2><?php esc_html_e( 'Wordpress-GitHub Sync', 'wordpress-github-sync' ); ?></h2>

	<form method="post" action="options.php">
		<?php settings_fields( Wordpress_GitHub_Sync::$text_domain ); ?>
		<?php do_settings_sections( Wordpress_GitHub_Sync::$text_domain ); ?>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Webhook callback', 'wordpress-github-sync' ); ?></th>
				<td><code><?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>?action=wghs_push_request</code></td>
			</tr>
			<tr>
                <th scope="row"><?php esc_html_e( 'Export', 'wordpress-github-sync' ); ?></th>
                <td>
                    <p><a href="<?php echo esc_url( add_query_arg( array( 'action' => 'export' ) ) ); ?>">
                        <input type="button" class="button button-secondary" value="<?php esc_html_e( 'Export to GitHub', 'wordpress-github-sync' ); ?>" />
                    </a></p>
                    <p><a href="<?php echo esc_url( add_query_arg( array( 'action' => 'force_export' ) ) ); ?>">
                        <input type="button" class="button button-secondary" value="<?php esc_html_e( 'Force export to GitHub', 'wordpress-github-sync' ); ?>" />
                    </a></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Import', 'wordpress-github-sync' ); ?></th>
                <td>
                    <p><a href="<?php echo esc_url( add_query_arg( array( 'action' => 'import' ) ) ); ?>">
                        <input type="button" class="button button-secondary" value="<?php esc_html_e( 'Import from GitHub', 'wordpress-github-sync' ); ?>" />
                    </a></p>
                     <p><a href="<?php echo esc_url( add_query_arg( array( 'action' => 'force_import' ) ) ); ?>">
                        <input type="button" class="button button-secondary" value="<?php esc_html_e( 'Force Import from GitHub', 'wordpress-github-sync' ); ?>" />
                    </a></p>
                </td>
            </tr>
		</table>
		<?php submit_button(); ?>
	</form>
</div>
