<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Content;

use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use WP_Term;

/**
 * Taxonomy term translation engine.
 *
 * Mirror of PostTranslator for terms — duplicates a term row + meta into a new
 * term in the target language, sharing a group_id so the front-end can resolve
 * the same logical category by language.
 *
 * Uses wp_insert_term() so termmeta + cache invalidation + taxonomy hierarchy
 * stay coherent. The cml_term_language row is REPLACED post-insert so the
 * default-language tag from created_<tax> doesn't outrun the actual target.
 */
final class TermTranslator {

	private const ACTION_ADD = 'cml_add_term_translation';
	private const NONCE      = 'cml_add_term_translation';
	private const LOCK_TTL   = 30;

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'init', array( self::class, 'register_taxonomy_hooks' ), 99 );
		add_filter( 'wp_unique_term_slug', array( self::class, 'filter_unique_slug' ), 10, 3 );
		add_action( 'admin_post_' . self::ACTION_ADD, array( self::class, 'handle_add_translation' ) );
	}

	public static function register_taxonomy_hooks(): void {
		$taxonomies = self::translatable_taxonomies();
		foreach ( $taxonomies as $tax ) {
			add_action( "{$tax}_edit_form_fields", array( self::class, 'render_edit_form_panel' ), 99, 2 );
			add_action( "created_{$tax}", array( self::class, 'tag_new_term' ), 10, 2 );
		}
	}

	/**
	 * @return array<int, string>
	 */
	public static function translatable_taxonomies(): array {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		$taxonomies = array_values( $taxonomies );

		/** @var array<int, string> $taxonomies */
		$taxonomies = (array) apply_filters( 'cml_translatable_taxonomies', $taxonomies );
		return $taxonomies;
	}

	public static function render_edit_form_panel( WP_Term $term, string $taxonomy ): void {
		$current_lang = TranslationGroups::get_term_language( (int) $term->term_id ) ?? Languages::default_code();
		$group_id     = TranslationGroups::get_term_group_id( (int) $term->term_id ) ?? (int) $term->term_id;
		$siblings     = TranslationGroups::get_term_siblings( $group_id );
		$languages    = Languages::active();
		$current      = Languages::get( $current_lang );
		?>
		<tr class="form-field cml-term-translations">
			<th scope="row"><label><?php esc_html_e( 'Translations', 'codeon-multilingual' ); ?></label></th>
			<td>
				<p style="margin-top:0">
					<strong><?php esc_html_e( 'Language:', 'codeon-multilingual' ); ?></strong>
					<code><?php echo esc_html( $current_lang ); ?></code>
					<?php if ( $current ) : ?>
						<span style="opacity:0.6">(<?php echo esc_html( $current->native ); ?>)</span>
					<?php endif; ?>
				</p>
				<?php if ( count( $languages ) <= 1 ) : ?>
					<p class="description"><?php esc_html_e( 'Only one language is configured. Add more languages to enable term translations.', 'codeon-multilingual' ); ?></p>
				<?php else : ?>
					<ul style="margin:8px 0">
						<?php foreach ( $languages as $code => $lang ) : ?>
							<?php
							if ( $code === $current_lang ) {
								continue;
							}
							$sibling_id    = (int) ( array_search( $code, $siblings, true ) ?: 0 );
							$has_sibling   = $sibling_id > 0 && $sibling_id !== (int) $term->term_id;
							$is_source_row = $has_sibling && $sibling_id === $group_id;
							?>
							<li style="margin-bottom:6px">
								<?php if ( $has_sibling ) : ?>
									<span class="dashicons dashicons-yes-alt" style="color:#46b450"></span>
									<a href="<?php echo esc_url( (string) get_edit_term_link( $sibling_id, $taxonomy ) ); ?>">
										<?php
										if ( $is_source_row ) {
											/* translators: %s: language native name */
											printf( esc_html__( 'Edit %s (original)', 'codeon-multilingual' ), esc_html( $lang->native ) );
										} else {
											/* translators: %s: language native name */
											printf( esc_html__( 'Edit %s translation', 'codeon-multilingual' ), esc_html( $lang->native ) );
										}
										?>
									</a>
								<?php else : ?>
									<span class="dashicons dashicons-plus-alt" style="color:#2271b1"></span>
									<a href="<?php echo esc_url( self::add_translation_url( $group_id, $taxonomy, $code ) ); ?>">
										<?php
										/* translators: %s: language native name */
										printf( esc_html__( 'Add %s translation', 'codeon-multilingual' ), esc_html( $lang->native ) );
										?>
									</a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	public static function add_translation_url( int $source_term_id, string $taxonomy, string $target_lang ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::ACTION_ADD,
					'source'   => $source_term_id,
					'taxonomy' => $taxonomy,
					'target'   => $target_lang,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE . '_' . $source_term_id . '_' . $target_lang
		);
	}

	public static function handle_add_translation(): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}

		$source   = isset( $_GET['source'] ) ? (int) $_GET['source'] : 0;
		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( (string) $_GET['taxonomy'] ) ) : '';
		$target   = isset( $_GET['target'] ) ? sanitize_key( wp_unslash( (string) $_GET['target'] ) ) : '';

		check_admin_referer( self::NONCE . '_' . $source . '_' . $target );

		if ( $source <= 0 || '' === $taxonomy || '' === $target ) {
			wp_die( esc_html__( 'Invalid request.', 'codeon-multilingual' ) );
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			wp_die( esc_html__( 'Unknown taxonomy.', 'codeon-multilingual' ) );
		}
		if ( ! Languages::exists_and_active( $target ) ) {
			wp_die( esc_html__( 'Target language is not active.', 'codeon-multilingual' ) );
		}

		$new_id = self::duplicate( $source, $taxonomy, $target );
		if ( 0 === $new_id ) {
			wp_die( esc_html__( 'Failed to create term translation.', 'codeon-multilingual' ) );
		}

		wp_safe_redirect( (string) ( get_edit_term_link( $new_id, $taxonomy ) ?: admin_url() ) );
		exit;
	}

	/**
	 * Deep-clone a term into the target language. Idempotent.
	 */
	public static function duplicate( int $source_term_id, string $taxonomy, string $target_lang ): int {
		$source = get_term( $source_term_id, $taxonomy );
		if ( ! ( $source instanceof WP_Term ) ) {
			return 0;
		}

		$group_id = self::resolve_group_id( (int) $source->term_id );
		$siblings = TranslationGroups::get_term_siblings( $group_id );
		$existing = (int) ( array_search( $target_lang, $siblings, true ) ?: 0 );
		if ( $existing > 0 ) {
			return $existing;
		}

		$lock = 'cml_term_dup_lock_' . $group_id . '_' . $target_lang;
		if ( get_transient( $lock ) ) {
			TranslationGroups::invalidate_term( (int) $source->term_id );
			$siblings = TranslationGroups::get_term_siblings( $group_id );
			return (int) ( array_search( $target_lang, $siblings, true ) ?: 0 );
		}
		set_transient( $lock, 1, self::LOCK_TTL );

		try {
			return self::do_duplicate( $source, $taxonomy, $target_lang, $group_id );
		} finally {
			delete_transient( $lock );
		}
	}

	private static function do_duplicate( WP_Term $source, string $taxonomy, string $target_lang, int $group_id ): int {
		$target_parent = self::resolve_translated_parent( (int) $source->parent, $taxonomy, $target_lang );

		$inserted = wp_insert_term(
			$source->name,
			$taxonomy,
			array(
				'description' => $source->description,
				'slug'        => $source->slug,
				'parent'      => $target_parent,
			)
		);

		if ( is_wp_error( $inserted ) ) {
			return 0;
		}

		$new_term_id = (int) $inserted['term_id'];

		self::clone_term_meta( (int) $source->term_id, $new_term_id );

		// REPLACE INTO: created_<tax> hook already wrote a default-lang row;
		// stamp it back to the actual target.
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"REPLACE INTO {$wpdb->prefix}cml_term_language (term_id, group_id, language) VALUES (%d, %d, %s)",
				$new_term_id,
				$group_id,
				$target_lang
			)
		);

		clean_term_cache( $new_term_id, $taxonomy );
		TranslationGroups::invalidate_term( (int) $source->term_id );
		TranslationGroups::invalidate_term( $new_term_id );

		do_action( 'cml_term_translation_created', $new_term_id, (int) $source->term_id, $taxonomy, $target_lang, $group_id );

		return $new_term_id;
	}

	private static function resolve_group_id( int $source_term_id ): int {
		$group = TranslationGroups::get_term_group_id( $source_term_id );
		if ( null !== $group ) {
			return $group;
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'cml_term_language',
			array(
				'term_id'  => $source_term_id,
				'group_id' => $source_term_id,
				'language' => Languages::default_code(),
			),
			array( '%d', '%d', '%s' )
		);
		TranslationGroups::invalidate_term( $source_term_id );
		return $source_term_id;
	}

	private static function resolve_translated_parent( int $source_parent, string $taxonomy, string $target_lang ): int {
		if ( $source_parent <= 0 ) {
			return 0;
		}
		$group = TranslationGroups::get_term_group_id( $source_parent );
		if ( null === $group ) {
			return 0;
		}
		$siblings = TranslationGroups::get_term_siblings( $group );
		return (int) ( array_search( $target_lang, $siblings, true ) ?: 0 );
	}

	private static function clone_term_meta( int $source_term_id, int $new_term_id ): void {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d",
				$source_term_id
			)
		);
		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			if ( str_starts_with( (string) $row->meta_key, '_cml_' ) ) {
				continue;
			}
			update_term_meta( $new_term_id, (string) $row->meta_key, maybe_unserialize( (string) $row->meta_value ) );
		}
	}

	/**
	 * Scope slug uniqueness by language. Same shape as PostTranslator's filter.
	 *
	 * @param string|null $slug
	 * @param object|null $term
	 * @param string|null $original_slug
	 */
	public static function filter_unique_slug( $slug, $term, $original_slug ): string {
		$slug          = (string) $slug;
		$original_slug = (string) $original_slug;

		if ( $slug === $original_slug ) {
			return $slug;
		}
		if ( ! ( $term instanceof \stdClass ) && ! ( $term instanceof WP_Term ) ) {
			return $slug;
		}

		$term_id = (int) $term->term_id;
		if ( $term_id <= 0 ) {
			return $slug;
		}

		$lang = TranslationGroups::get_term_language( $term_id );
		if ( null === $lang ) {
			return $slug;
		}

		$taxonomy = property_exists( $term, 'taxonomy' ) ? (string) $term->taxonomy : '';

		global $wpdb;
		$where_taxonomy = '';
		$prepare_args   = array( $original_slug, $term_id );
		if ( '' !== $taxonomy ) {
			$where_taxonomy = ' AND tt.taxonomy = %s';
			$prepare_args[] = $taxonomy;
		}
		$prepare_args[] = $lang;

		$conflict = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->terms} t
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				 INNER JOIN {$wpdb->prefix}cml_term_language tl ON tl.term_id = t.term_id
				 WHERE t.slug = %s
				   AND t.term_id != %d
				   {$where_taxonomy}
				   AND tl.language = %s",
				...$prepare_args
			)
		);

		return 0 === $conflict ? $original_slug : $slug;
	}

	public static function tag_new_term( int $term_id, int $tt_id ): void {
		if ( null !== TranslationGroups::get_term_language( $term_id ) ) {
			return;
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'cml_term_language',
			array(
				'term_id'  => $term_id,
				'group_id' => $term_id,
				'language' => Languages::default_code(),
			),
			array( '%d', '%d', '%s' )
		);
		TranslationGroups::invalidate_term( $term_id );
	}
}
