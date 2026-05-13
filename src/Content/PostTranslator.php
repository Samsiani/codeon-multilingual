<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Content;

use Samsiani\CodeonMultilingual\Admin\AdminMenu;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use WP_Post;

/**
 * "Duplicate as translation" engine.
 *
 * Responsibilities:
 *   - Deep-clone a post (row + meta + term relations) into a new wp_posts entry
 *     tagged with the target language and the source's translation group.
 *   - Scope wp_unique_post_slug by language so /en/about and /ka/about coexist.
 *   - Tag newly-created posts with the default language on save_post.
 *   - Render a meta box on every translatable post edit screen.
 *   - Provide admin-post handler for the meta-box "Add translation" link.
 *
 * The duplicate writes via raw $wpdb so save_post and a flood of side-effect hooks
 * don't fire on the brand-new draft. Result: O(3) inserts + 1 batch insert + 1
 * term-relations replay per translation, regardless of meta volume.
 */
final class PostTranslator {

	private const META_BOX_ID            = 'cml-translations';
	private const ACTION_ADD_TRANSLATION = 'cml_add_translation';
	private const NONCE_ACTION           = 'cml_add_translation';
	private const LOCK_TTL               = 30;

	/** Meta keys we never copy from source to translation. */
	private const META_BLACKLIST = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_desired_post_slug',
	);

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'add_meta_boxes', array( self::class, 'register_meta_box' ) );
		add_action( 'save_post', array( self::class, 'tag_new_post' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION_ADD_TRANSLATION, array( self::class, 'handle_add_translation' ) );

		add_filter( 'wp_unique_post_slug', array( self::class, 'filter_unique_slug' ), 10, 6 );
	}

	/**
	 * @return array<int, string>
	 */
	public static function translatable_post_types(): array {
		$types   = get_post_types( array( 'public' => true ), 'names' );
		$types[] = 'attachment';
		$types   = array_values( array_unique( $types ) );

		/** @var array<int, string> $types */
		$types = (array) apply_filters( 'cml_translatable_post_types', $types );
		return $types;
	}

	public static function register_meta_box(): void {
		foreach ( self::translatable_post_types() as $post_type ) {
			add_meta_box(
				self::META_BOX_ID,
				__( 'Translations', 'codeon-multilingual' ),
				array( self::class, 'render_meta_box' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	public static function render_meta_box( WP_Post $post ): void {
		$current_lang = TranslationGroups::get_language( $post->ID ) ?? Languages::default_code();
		$group_id     = TranslationGroups::get_group_id( $post->ID ) ?? $post->ID;
		$siblings     = TranslationGroups::get_siblings( $group_id );
		$languages    = Languages::active();
		$current      = Languages::get( $current_lang );
		?>
		<div class="cml-translations-box">
			<p>
				<strong><?php esc_html_e( 'Language:', 'codeon-multilingual' ); ?></strong>
				<code><?php echo esc_html( $current_lang ); ?></code>
				<?php if ( $current ) : ?>
					<span style="opacity:0.6">(<?php echo esc_html( $current->native ); ?>)</span>
				<?php endif; ?>
			</p>

			<?php if ( count( $languages ) <= 1 ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: admin URL for the Languages page */
						wp_kses(
							__( 'Only one language is configured. <a href="%s">Add more languages</a> to enable translations.', 'codeon-multilingual' ),
							array( 'a' => array( 'href' => array() ) )
						),
						esc_url( admin_url( 'admin.php?page=' . AdminMenu::PARENT_SLUG ) )
					);
					?>
				</p>
			<?php else : ?>
				<ul class="cml-translation-list" style="margin-top:8px">
					<?php foreach ( $languages as $code => $lang ) : ?>
						<?php
						if ( $code === $current_lang ) {
							continue; }
						?>
						<?php
						$sibling_id  = (int) ( array_search( $code, $siblings, true ) ?: 0 );
						$has_sibling = $sibling_id > 0 && $sibling_id !== $post->ID;
						?>
						<li style="margin-bottom:6px">
							<?php if ( $has_sibling ) : ?>
								<span class="dashicons dashicons-yes-alt" style="color:#46b450"></span>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $sibling_id ) ); ?>">
									<?php
									/* translators: %s: language native name */
									printf( esc_html__( 'Edit %s translation', 'codeon-multilingual' ), esc_html( $lang->native ) );
									?>
								</a>
							<?php else : ?>
								<span class="dashicons dashicons-plus-alt" style="color:#2271b1"></span>
								<a href="<?php echo esc_url( self::add_translation_url( $post->ID, $code ) ); ?>">
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
		</div>
		<?php
	}

	public static function add_translation_url( int $source_id, string $target_lang ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION_ADD_TRANSLATION,
					'source' => $source_id,
					'target' => $target_lang,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_ACTION . '_' . $source_id . '_' . $target_lang
		);
	}

	public static function handle_add_translation(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}

		$source = isset( $_GET['source'] ) ? (int) $_GET['source'] : 0;
		$target = isset( $_GET['target'] ) ? sanitize_key( wp_unslash( (string) $_GET['target'] ) ) : '';

		check_admin_referer( self::NONCE_ACTION . '_' . $source . '_' . $target );

		if ( $source <= 0 || '' === $target ) {
			wp_die( esc_html__( 'Invalid request.', 'codeon-multilingual' ) );
		}
		if ( ! current_user_can( 'edit_post', $source ) ) {
			wp_die( esc_html__( 'You cannot translate this post.', 'codeon-multilingual' ) );
		}
		if ( ! Languages::exists_and_active( $target ) ) {
			wp_die( esc_html__( 'Target language is not active.', 'codeon-multilingual' ) );
		}

		$new_id = self::duplicate( $source, $target );
		if ( 0 === $new_id ) {
			wp_die( esc_html__( 'Failed to create translation.', 'codeon-multilingual' ) );
		}

		$edit_url = get_edit_post_link( $new_id, 'raw' );
		wp_safe_redirect( (string) ( $edit_url ?: admin_url() ) );
		exit;
	}

	/**
	 * Deep-clone $source_id into a new post in $target_lang. Idempotent: returns
	 * the existing translation's ID if one already exists for the group/lang pair.
	 */
	public static function duplicate( int $source_id, string $target_lang ): int {
		$source = get_post( $source_id );
		if ( ! $source instanceof WP_Post ) {
			return 0;
		}

		$group_id = self::resolve_group_id( $source );
		$siblings = TranslationGroups::get_siblings( $group_id );
		$existing = (int) ( array_search( $target_lang, $siblings, true ) ?: 0 );
		if ( $existing > 0 ) {
			return $existing;
		}

		$lock = 'cml_dup_lock_' . $group_id . '_' . $target_lang;
		if ( get_transient( $lock ) ) {
			TranslationGroups::invalidate( $source_id );
			$siblings = TranslationGroups::get_siblings( $group_id );
			return (int) ( array_search( $target_lang, $siblings, true ) ?: 0 );
		}
		set_transient( $lock, 1, self::LOCK_TTL );

		try {
			$new_id = self::do_duplicate( $source, $target_lang, $group_id );
		} finally {
			delete_transient( $lock );
		}

		return $new_id;
	}

	private static function do_duplicate( WP_Post $source, string $target_lang, int $group_id ): int {
		global $wpdb;

		$target_parent = self::resolve_translated_parent( (int) $source->post_parent, $target_lang );

		$slug = self::generate_unique_slug(
			$source->post_name,
			$source->post_type,
			$target_parent,
			$target_lang
		);

		$now     = current_time( 'mysql' );
		$now_gmt = current_time( 'mysql', true );

		// Attachments must stay as 'inherit' or media library breaks; everything
		// else starts as draft so the translator can review before publishing.
		$target_status = 'attachment' === $source->post_type ? 'inherit' : 'draft';

		$insert = $wpdb->insert(
			$wpdb->posts,
			array(
				'post_author'           => get_current_user_id() ?: $source->post_author,
				'post_date'             => $now,
				'post_date_gmt'         => $now_gmt,
				'post_content'          => $source->post_content,
				'post_title'            => $source->post_title,
				'post_excerpt'          => $source->post_excerpt,
				'post_status'           => $target_status,
				'comment_status'        => $source->comment_status,
				'ping_status'           => $source->ping_status,
				'post_password'         => $source->post_password,
				'post_name'             => $slug,
				'to_ping'               => $source->to_ping,
				'pinged'                => $source->pinged,
				'post_modified'         => $now,
				'post_modified_gmt'     => $now_gmt,
				'post_content_filtered' => $source->post_content_filtered,
				'post_parent'           => $target_parent,
				'guid'                  => '',
				'menu_order'            => (int) $source->menu_order,
				'post_type'             => $source->post_type,
				'post_mime_type'        => $source->post_mime_type,
				'comment_count'         => 0,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d' )
		);

		if ( false === $insert ) {
			return 0;
		}

		$new_id = (int) $wpdb->insert_id;

		// guid is built off ID, so update post-insert.
		$wpdb->update(
			$wpdb->posts,
			array( 'guid' => get_permalink( $new_id ) ?: home_url( '/?p=' . $new_id ) ),
			array( 'ID' => $new_id ),
			array( '%s' ),
			array( '%d' )
		);

		self::clone_postmeta( (int) $source->ID, $new_id );
		self::clone_term_relationships( (int) $source->ID, $new_id, $source->post_type );

		$wpdb->insert(
			$wpdb->prefix . 'cml_post_language',
			array(
				'post_id'  => $new_id,
				'group_id' => $group_id,
				'language' => $target_lang,
			),
			array( '%d', '%d', '%s' )
		);

		clean_post_cache( $new_id );
		TranslationGroups::invalidate( (int) $source->ID );
		TranslationGroups::invalidate( $new_id );

		do_action( 'cml_post_translation_created', $new_id, (int) $source->ID, $target_lang, $group_id );

		return $new_id;
	}

	/**
	 * Resolve (and lazily create) the translation-group id for a source post.
	 * If the source predates the backfill, tag it with the default language now.
	 */
	private static function resolve_group_id( WP_Post $source ): int {
		$group = TranslationGroups::get_group_id( (int) $source->ID );
		if ( null !== $group ) {
			return $group;
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'cml_post_language',
			array(
				'post_id'  => (int) $source->ID,
				'group_id' => (int) $source->ID,
				'language' => Languages::default_code(),
			),
			array( '%d', '%d', '%s' )
		);
		TranslationGroups::invalidate( (int) $source->ID );
		return (int) $source->ID;
	}

	private static function resolve_translated_parent( int $source_parent, string $target_lang ): int {
		if ( $source_parent <= 0 ) {
			return 0;
		}
		$group = TranslationGroups::get_group_id( $source_parent );
		if ( null === $group ) {
			return 0;
		}
		$siblings = TranslationGroups::get_siblings( $group );
		return (int) ( array_search( $target_lang, $siblings, true ) ?: 0 );
	}

	private static function clone_postmeta( int $source_id, int $new_id ): void {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
				$source_id
			)
		);
		if ( empty( $rows ) ) {
			return;
		}

		$values       = array();
		$placeholders = array();
		foreach ( $rows as $row ) {
			$key = (string) $row->meta_key;
			if ( in_array( $key, self::META_BLACKLIST, true ) ) {
				continue;
			}
			if ( str_starts_with( $key, '_cml_' ) ) {
				continue;
			}
			$values[]       = $new_id;
			$values[]       = $key;
			$values[]       = (string) $row->meta_value;
			$placeholders[] = '(%d, %s, %s)';
		}
		if ( array() === $placeholders ) {
			return;
		}

		$sql = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $placeholders );
		$wpdb->query( $wpdb->prepare( $sql, ...$values ) );
	}

	private static function clone_term_relationships( int $source_id, int $new_id, string $post_type ): void {
		$taxonomies = get_object_taxonomies( $post_type, 'names' );
		if ( empty( $taxonomies ) ) {
			return;
		}
		foreach ( $taxonomies as $tax ) {
			$term_ids = wp_get_object_terms( $source_id, $tax, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
				continue;
			}
			wp_set_object_terms( $new_id, array_map( 'intval', $term_ids ), $tax, false );
		}
	}

	/**
	 * Same slug allowed across languages, but must remain unique inside the
	 * same (post_type, post_parent, language) tuple. Day-2 backfill guarantees
	 * every legacy row has a language tag, so this is a clean scoped check.
	 */
	private static function generate_unique_slug( string $slug, string $post_type, int $parent, string $lang ): string {
		global $wpdb;

		$original  = '' !== $slug ? $slug : 'translation';
		$candidate = $original;
		$i         = 2;

		while ( true ) {
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->prefix}cml_post_language pl ON pl.post_id = p.ID
					 WHERE p.post_name = %s
					   AND p.post_type = %s
					   AND p.post_parent = %d
					   AND p.post_status NOT IN ('trash', 'auto-draft')
					   AND pl.language = %s",
					$candidate,
					$post_type,
					$parent,
					$lang
				)
			);
			if ( 0 === $exists ) {
				return $candidate;
			}
			$candidate = $original . '-' . $i;
			++$i;
			if ( $i > 100 ) {
				return $original . '-' . wp_generate_password( 4, false, false );
			}
		}
	}

	/**
	 * wp_unique_post_slug filter: keep the requested slug if it's unique within
	 * the post's language; otherwise let WP's modified slug stand.
	 */
	public static function filter_unique_slug( string $slug, int $post_id, string $post_status, string $post_type, int $post_parent, string $original_slug ): string {
		if ( $slug === $original_slug ) {
			return $slug;
		}

		$lang = TranslationGroups::get_language( $post_id );
		if ( null === $lang ) {
			return $slug;
		}

		global $wpdb;
		$conflict = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->prefix}cml_post_language pl ON pl.post_id = p.ID
				 WHERE p.post_name = %s
				   AND p.post_type = %s
				   AND p.post_parent = %d
				   AND p.ID != %d
				   AND p.post_status NOT IN ('trash', 'auto-draft')
				   AND pl.language = %s",
				$original_slug,
				$post_type,
				$post_parent,
				$post_id,
				$lang
			)
		);
		if ( 0 === $conflict ) {
			return $original_slug;
		}
		return $slug;
	}

	/**
	 * After a post is saved through normal WP flow, ensure it has a language tag.
	 * New posts created in admin without going through the translator land with
	 * the default language.
	 */
	public static function tag_new_post( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'auto-draft' === $post->post_status || 'revision' === $post->post_type ) {
			return;
		}
		if ( null !== TranslationGroups::get_language( $post_id ) ) {
			return;
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'cml_post_language',
			array(
				'post_id'  => $post_id,
				'group_id' => $post_id,
				'language' => Languages::default_code(),
			),
			array( '%d', '%d', '%s' )
		);
		TranslationGroups::invalidate( $post_id );
	}
}
