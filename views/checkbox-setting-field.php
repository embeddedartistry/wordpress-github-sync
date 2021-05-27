<?php
/**
 * Test Option Field.
 * @package Wordpress_GitHub_Sync
 */

?>
<?php $value = get_option( $args['name'], $args['default'] ); ?>
<input type="checkbox" name="<?php echo esc_attr( $args['name'] ); ?>" id="<?php echo esc_attr( $args['name'] ); ?>" value="yes" <?php echo 'yes' === $value ? 'checked' : '';  ?> />
<p class="description"><?php echo $args['help_text']; ?></p>
