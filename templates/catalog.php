<?php
/**
 * Course catalog template.
 *
 * Overridable at `yourtheme/sglms/catalog.php`. Rendered by [sglms_catalog].
 * Reads search/category/difficulty from the query string (read-only display,
 * no nonce needed) and lists matching published courses.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only catalog filters.
$sglms_search = isset( $_GET['sglms_s'] ) ? sanitize_text_field( wp_unslash( $_GET['sglms_s'] ) ) : '';
$sglms_cat    = isset( $_GET['sglms_cat'] ) ? sanitize_title( wp_unslash( $_GET['sglms_cat'] ) ) : '';
$sglms_diff   = isset( $_GET['sglms_diff'] ) ? sanitize_title( wp_unslash( $_GET['sglms_diff'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$sglms_tax_query = array();
if ( $sglms_cat ) {
	$sglms_tax_query[] = array(
		'taxonomy' => 'sglms_course_cat',
		'field'    => 'slug',
		'terms'    => $sglms_cat,
	);
}
if ( $sglms_diff ) {
	$sglms_tax_query[] = array(
		'taxonomy' => 'sglms_difficulty',
		'field'    => 'slug',
		'terms'    => $sglms_diff,
	);
}

$sglms_query = new WP_Query(
	array(
		'post_type'      => 'sglms_course',
		'post_status'    => 'publish',
		'posts_per_page' => 24,
		's'              => $sglms_search,
		'tax_query'      => $sglms_tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		'orderby'        => 'title',
		'order'          => 'ASC',
	)
);

$sglms_cats  = get_terms(
	array(
		'taxonomy'   => 'sglms_course_cat',
		'hide_empty' => false,
	)
);
$sglms_diffs = get_terms(
	array(
		'taxonomy'   => 'sglms_difficulty',
		'hide_empty' => false,
	)
);
?>
<div class="sglms sglms-catalog">

	<form class="sglms-catalog__filters" method="get">
		<input type="search" name="sglms_s" value="<?php echo esc_attr( $sglms_search ); ?>" placeholder="<?php esc_attr_e( 'Search courses…', 'sglms' ); ?>" class="sglms-input" />

		<select name="sglms_cat" class="sglms-input">
			<option value=""><?php esc_html_e( 'All categories', 'sglms' ); ?></option>
			<?php foreach ( (array) $sglms_cats as $sglms_term ) : ?>
				<?php if ( $sglms_term instanceof WP_Term ) : ?>
					<option value="<?php echo esc_attr( $sglms_term->slug ); ?>" <?php selected( $sglms_cat, $sglms_term->slug ); ?>><?php echo esc_html( $sglms_term->name ); ?></option>
				<?php endif; ?>
			<?php endforeach; ?>
		</select>

		<select name="sglms_diff" class="sglms-input">
			<option value=""><?php esc_html_e( 'Any difficulty', 'sglms' ); ?></option>
			<?php foreach ( (array) $sglms_diffs as $sglms_term ) : ?>
				<?php if ( $sglms_term instanceof WP_Term ) : ?>
					<option value="<?php echo esc_attr( $sglms_term->slug ); ?>" <?php selected( $sglms_diff, $sglms_term->slug ); ?>><?php echo esc_html( $sglms_term->name ); ?></option>
				<?php endif; ?>
			<?php endforeach; ?>
		</select>

		<button type="submit" class="sglms-btn sglms-btn--primary"><?php esc_html_e( 'Filter', 'sglms' ); ?></button>
	</form>

	<?php if ( $sglms_query->have_posts() ) : ?>
		<div class="sglms-catalog__grid">
			<?php
			while ( $sglms_query->have_posts() ) :
				$sglms_query->the_post();
				$sglms_cid       = get_the_ID();
				$sglms_lessons   = count( Sglms_Frontend::ordered_lessons( $sglms_cid ) );
				$sglms_duration  = get_post_meta( $sglms_cid, '_sglms_est_duration', true );
				$sglms_diff_term = wp_get_post_terms( $sglms_cid, 'sglms_difficulty' );
				$sglms_price     = get_post_meta( $sglms_cid, '_sglms_price_mode', true );
				?>
				<article class="sglms-card">
					<a class="sglms-card__media" href="<?php the_permalink(); ?>">
						<?php if ( has_post_thumbnail() ) : ?>
							<?php the_post_thumbnail( 'medium' ); ?>
						<?php else : ?>
							<span class="sglms-card__placeholder" aria-hidden="true"></span>
						<?php endif; ?>
					</a>
					<div class="sglms-card__body">
						<?php if ( ! empty( $sglms_diff_term ) && ! is_wp_error( $sglms_diff_term ) ) : ?>
							<span class="sglms-chip"><?php echo esc_html( $sglms_diff_term[0]->name ); ?></span>
						<?php endif; ?>
						<h3 class="sglms-card__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
						<p class="sglms-card__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 22 ) ); ?></p>
						<div class="sglms-card__meta">
							<span><?php echo esc_html( sprintf( /* translators: %d: lesson count. */ _n( '%d lesson', '%d lessons', $sglms_lessons, 'sglms' ), $sglms_lessons ) ); ?></span>
							<?php if ( $sglms_duration ) : ?>
								<span><?php echo esc_html( $sglms_duration ); ?></span>
							<?php endif; ?>
							<?php if ( 'free' === ( $sglms_price ? $sglms_price : 'free' ) ) : ?>
								<span class="sglms-chip sglms-chip--free"><?php esc_html_e( 'Free', 'sglms' ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				</article>
			<?php endwhile; ?>
		</div>
	<?php else : ?>
		<p class="sglms-empty"><?php esc_html_e( 'No courses match your search.', 'sglms' ); ?></p>
	<?php endif; ?>
	<?php wp_reset_postdata(); ?>
</div>
