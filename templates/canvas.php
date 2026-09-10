<?php
/**
 * Minimal frontend canvas for Design Core managed pages.
 *
 * The active theme is intentionally bypassed so reference-derived pages are not
 * wrapped by theme headers, footers, content widths, block spacing or post titles.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class( 'dch-canvas' ); ?>>
<?php wp_body_open(); ?>
<main id="design-core-hub-canvas" class="dch-canvas-content">
<?php
while ( have_posts() ) {
    the_post();
    the_content();
}
?>
</main>
<?php wp_footer(); ?>
</body>
</html>
