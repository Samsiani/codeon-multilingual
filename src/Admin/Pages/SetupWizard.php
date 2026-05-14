<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin\Pages;

use Samsiani\CodeonMultilingual\Admin\AdminMenu;
use Samsiani\CodeonMultilingual\Core\LanguageCatalog;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\Settings;

/**
 * 4-step setup guide that runs on first activation (WPML-style).
 *
 * Steps
 *   1. Welcome      — what the wizard does, "Skip" link, "Let's go" CTA.
 *   2. Default lang — pick the site's primary language from the bundled catalog.
 *   3. Secondary    — pick additional active languages (multi-select with search).
 *   4. Done         — summary + deep links to Languages page and the front-end.
 *
 * Persistence
 *   - cml_settings['setup_complete'] = true on finish; the redirector reads this
 *     to decide whether to send the admin to the wizard.
 *   - cml_settings['setup_skipped']  = true when the user clicks "Skip".
 *   - cml_settings['setup_step']     = current step int (so a refresh resumes).
 *
 * Form submissions go through admin-post.php with a nonce per step; the handler
 * writes via Languages::create / update_active / set_default so the admin
 * Languages page and the wizard always share the same write path.
 */
final class SetupWizard {

	public const PAGE_SLUG = 'cml-setup';

	private const ACTION_STEP   = 'cml_setup_step';
	private const ACTION_SKIP   = 'cml_setup_skip';
	private const ACTION_FINISH = 'cml_setup_finish';

	private const NONCE_STEP   = 'cml_setup_step';
	private const NONCE_SKIP   = 'cml_setup_skip';
	private const NONCE_FINISH = 'cml_setup_finish';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_post_' . self::ACTION_STEP, array( self::class, 'handle_step_submit' ) );
		add_action( 'admin_post_' . self::ACTION_SKIP, array( self::class, 'handle_skip' ) );
		add_action( 'admin_post_' . self::ACTION_FINISH, array( self::class, 'handle_finish' ) );
	}

	public static function url( int $step = 1 ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'step' => $step,
			),
			admin_url( 'admin.php' )
		);
	}

	public static function is_complete(): bool {
		return (bool) Settings::get( 'setup_complete', false );
	}

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}

		$step = isset( $_GET['step'] ) ? max( 1, min( 4, (int) $_GET['step'] ) ) : 1;
		self::render_chrome( $step, function () use ( $step ): void {
			match ( $step ) {
				1       => self::render_welcome(),
				2       => self::render_default_language(),
				3       => self::render_secondary_languages(),
				4       => self::render_done(),
				default => self::render_welcome(),
			};
		} );
	}

	// ---- Step renderers --------------------------------------------------

	private static function render_chrome( int $step, callable $body ): void {
		$labels = array(
			1 => __( 'Welcome', 'codeon-multilingual' ),
			2 => __( 'Default language', 'codeon-multilingual' ),
			3 => __( 'Additional languages', 'codeon-multilingual' ),
			4 => __( 'All set', 'codeon-multilingual' ),
		);
		?>
		<div class="wrap cml-setup">
			<style>
				.cml-setup { max-width: 760px; }
				.cml-setup h1 { margin-bottom: 4px; }
				.cml-setup-steps { display: flex; gap: 0; margin: 18px 0 26px; border-bottom: 1px solid #dcdcde; padding-bottom: 0; }
				.cml-setup-steps li { list-style: none; padding: 10px 16px 12px; margin: 0; border-bottom: 3px solid transparent; color: #50575e; }
				.cml-setup-steps li.is-current { color: #2271b1; border-bottom-color: #2271b1; font-weight: 600; }
				.cml-setup-steps li.is-done { color: #46b450; }
				.cml-setup-card { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 24px 28px; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
				.cml-setup-card h2 { margin-top: 0; }
				.cml-setup-actions { margin-top: 22px; display: flex; gap: 10px; align-items: center; }
				.cml-setup-actions .cml-skip { margin-left: auto; color: #646970; text-decoration: none; }
				.cml-setup-actions .cml-skip:hover { color: #d63638; }
				.cml-lang-search { width: 100%; padding: 8px 10px; font-size: 14px; margin-bottom: 12px; }
				.cml-lang-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 6px; max-height: 380px; overflow-y: auto; border: 1px solid #dcdcde; padding: 10px; border-radius: 4px; background: #fafbfc; }
				.cml-lang-item { display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 3px; cursor: pointer; }
				.cml-lang-item:hover { background: #f0f6fc; }
				.cml-lang-item input { margin: 0; }
				.cml-lang-flag { font-size: 18px; line-height: 1; }
				.cml-lang-meta { display: flex; flex-direction: column; }
				.cml-lang-name { font-weight: 500; color: #1d2327; }
				.cml-lang-native { font-size: 12px; color: #646970; }
				.cml-major-divider { grid-column: 1 / -1; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #646970; padding: 8px 4px 2px; border-bottom: 1px solid #dcdcde; margin-bottom: 4px; }
				.cml-done-summary { background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; margin: 14px 0; }
			</style>
			<h1><?php esc_html_e( 'Setup CodeOn Multilingual', 'codeon-multilingual' ); ?></h1>
			<p class="description"><?php esc_html_e( "Three quick steps and your site is multilingual.", 'codeon-multilingual' ); ?></p>

			<ul class="cml-setup-steps">
				<?php foreach ( $labels as $n => $label ) : ?>
					<li class="<?php echo $n === $step ? 'is-current' : ( $n < $step ? 'is-done' : '' ); ?>">
						<?php echo (int) $n; ?>. <?php echo esc_html( $label ); ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<div class="cml-setup-card">
				<?php $body(); ?>
			</div>
		</div>
		<?php
	}

	private static function render_welcome(): void {
		$current_locale = get_locale();
		$detected       = LanguageCatalog::by_locale( $current_locale );
		?>
		<h2><?php esc_html_e( 'Welcome', 'codeon-multilingual' ); ?></h2>
		<p>
			<?php esc_html_e( 'This guide configures the languages your site will serve. You can change everything later from the Languages screen.', 'codeon-multilingual' ); ?>
		</p>
		<p>
			<?php
			printf(
				/* translators: 1: detected language name, 2: locale code */
				esc_html__( 'Your WordPress site language is %1$s (%2$s). We\'ll suggest it as the default in the next step.', 'codeon-multilingual' ),
				'<strong>' . esc_html( $detected ? $detected['name'] : $current_locale ) . '</strong>',
				'<code>' . esc_html( $current_locale ) . '</code>'
			);
			?>
		</p>
		<p>
			<?php esc_html_e( 'After the wizard you\'ll be able to translate posts, taxonomies, products, and theme strings.', 'codeon-multilingual' ); ?>
		</p>

		<div class="cml-setup-actions">
			<a href="<?php echo esc_url( self::url( 2 ) ); ?>" class="button button-primary button-large">
				<?php esc_html_e( "Let's go →", 'codeon-multilingual' ); ?>
			</a>
			<a href="<?php echo esc_url( self::skip_url() ); ?>" class="cml-skip" onclick="return confirm('<?php echo esc_js( __( 'Skip the setup wizard? You can re-run it later from the Languages page.', 'codeon-multilingual' ) ); ?>');">
				<?php esc_html_e( 'Skip wizard', 'codeon-multilingual' ); ?>
			</a>
		</div>
		<?php
	}

	private static function render_default_language(): void {
		$current_locale = get_locale();
		$detected       = LanguageCatalog::by_locale( $current_locale );
		$existing       = Languages::get_default();
		$preselected    = $existing instanceof \stdClass || is_object( $existing )
			? (string) $existing->code
			: ( $detected ? $detected['code'] : 'en' );
		?>
		<h2><?php esc_html_e( 'Pick your site\'s default language', 'codeon-multilingual' ); ?></h2>
		<p>
			<?php esc_html_e( 'This is the language your content was originally written in. Existing posts will be tagged with it automatically.', 'codeon-multilingual' ); ?>
		</p>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<?php wp_nonce_field( self::NONCE_STEP . '_2' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_STEP ); ?>">
			<input type="hidden" name="step" value="2">

			<input type="text" id="cml-search-default" class="cml-lang-search" placeholder="<?php esc_attr_e( 'Search by name, native name, or code…', 'codeon-multilingual' ); ?>" autofocus>

			<div class="cml-lang-grid" id="cml-grid-default">
				<?php self::render_catalog_options( 'default_code', $preselected, 'radio' ); ?>
			</div>

			<div class="cml-setup-actions">
				<button type="submit" class="button button-primary button-large">
					<?php esc_html_e( 'Continue →', 'codeon-multilingual' ); ?>
				</button>
				<a href="<?php echo esc_url( self::url( 1 ) ); ?>" class="button"><?php esc_html_e( '← Back', 'codeon-multilingual' ); ?></a>
				<a href="<?php echo esc_url( self::skip_url() ); ?>" class="cml-skip"><?php esc_html_e( 'Skip wizard', 'codeon-multilingual' ); ?></a>
			</div>
		</form>
		<?php
		self::render_search_script( 'cml-search-default', 'cml-grid-default' );
	}

	private static function render_secondary_languages(): void {
		$default = Languages::default_code();
		// Pre-check any currently active non-default languages (e.g. re-running the wizard).
		$preselected = array();
		foreach ( Languages::active() as $code => $_ ) {
			if ( $code !== $default ) {
				$preselected[] = (string) $code;
			}
		}
		?>
		<h2><?php esc_html_e( 'Add additional languages', 'codeon-multilingual' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %s: code of the default language */
				esc_html__( 'Default is %s. Tick every other language you want to translate this site into. You can add more at any time.', 'codeon-multilingual' ),
				'<code>' . esc_html( $default ) . '</code>'
			);
			?>
		</p>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<?php wp_nonce_field( self::NONCE_STEP . '_3' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_STEP ); ?>">
			<input type="hidden" name="step" value="3">

			<input type="text" id="cml-search-secondary" class="cml-lang-search" placeholder="<?php esc_attr_e( 'Search by name, native name, or code…', 'codeon-multilingual' ); ?>" autofocus>

			<div class="cml-lang-grid" id="cml-grid-secondary">
				<?php self::render_catalog_options( 'secondary_codes[]', $preselected, 'checkbox', $default ); ?>
			</div>

			<div class="cml-setup-actions">
				<button type="submit" class="button button-primary button-large">
					<?php esc_html_e( 'Continue →', 'codeon-multilingual' ); ?>
				</button>
				<a href="<?php echo esc_url( self::url( 2 ) ); ?>" class="button"><?php esc_html_e( '← Back', 'codeon-multilingual' ); ?></a>
				<a href="<?php echo esc_url( self::skip_url() ); ?>" class="cml-skip"><?php esc_html_e( 'Skip wizard', 'codeon-multilingual' ); ?></a>
			</div>
		</form>
		<?php
		self::render_search_script( 'cml-search-secondary', 'cml-grid-secondary' );
	}

	private static function render_done(): void {
		$default     = Languages::get_default();
		$active      = Languages::active();
		$active_list = array_map(
			static fn( $l ) => (string) $l->code . ' (' . (string) $l->native . ')',
			array_filter(
				$active,
				static fn( $l ) => $default ? $l->code !== $default->code : true
			)
		);
		?>
		<h2><?php esc_html_e( '🎉 All set!', 'codeon-multilingual' ); ?></h2>
		<p>
			<?php esc_html_e( "Your site is now multilingual. Here's the configuration:", 'codeon-multilingual' ); ?>
		</p>

		<div class="cml-done-summary">
			<strong><?php esc_html_e( 'Default language:', 'codeon-multilingual' ); ?></strong>
			<?php echo esc_html( $default ? $default->name . ' (' . $default->native . ')' : '—' ); ?>
			<br>
			<strong><?php esc_html_e( 'Additional languages:', 'codeon-multilingual' ); ?></strong>
			<?php echo array() === $active_list ? esc_html__( 'none yet', 'codeon-multilingual' ) : esc_html( implode( ', ', $active_list ) ); ?>
		</div>

		<h3><?php esc_html_e( 'What to do next', 'codeon-multilingual' ); ?></h3>
		<ol>
			<li>
				<?php
				printf(
					/* translators: 1: opening anchor tag, 2: closing anchor tag */
					esc_html__( 'Open any post and use the %1$sTranslations meta box%2$s to create translated versions.', 'codeon-multilingual' ),
					'<a href="' . esc_url( admin_url( 'edit.php' ) ) . '">',
					'</a>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: 1: opening anchor tag, 2: closing anchor tag */
					esc_html__( 'Visit %1$sLanguages → Settings%2$s to enable the floating frontend switcher.', 'codeon-multilingual' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=cml-settings' ) ) . '">',
					'</a>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: 1: opening anchor tag, 2: closing anchor tag */
					esc_html__( 'If you\'re migrating from WPML, run the %1$sMigration tool%2$s.', 'codeon-multilingual' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=cml-migration' ) ) . '">',
					'</a>'
				);
				?>
			</li>
		</ol>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="cml-setup-actions">
			<?php wp_nonce_field( self::NONCE_FINISH ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_FINISH ); ?>">
			<button type="submit" class="button button-primary button-large">
				<?php esc_html_e( 'Finish & go to Languages', 'codeon-multilingual' ); ?>
			</button>
		</form>
		<?php
	}

	// ---- Form handlers ---------------------------------------------------

	public static function handle_step_submit(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		$step = isset( $_POST['step'] ) ? (int) $_POST['step'] : 1;
		check_admin_referer( self::NONCE_STEP . '_' . $step );

		if ( 2 === $step ) {
			$code = isset( $_POST['default_code'] ) ? sanitize_key( wp_unslash( (string) $_POST['default_code'] ) ) : '';
			self::install_or_set_default( $code );
			Settings::save( array( 'setup_step' => 3 ) );
			wp_safe_redirect( self::url( 3 ) );
			exit;
		}

		if ( 3 === $step ) {
			$raw      = isset( $_POST['secondary_codes'] ) && is_array( $_POST['secondary_codes'] ) ? wp_unslash( $_POST['secondary_codes'] ) : array();
			$codes    = array_filter( array_map( 'sanitize_key', $raw ) );
			$default  = Languages::default_code();
			$position = 10;
			foreach ( $codes as $code ) {
				if ( $code === $default ) {
					continue;
				}
				self::install_or_activate( $code, $position );
				$position += 10;
			}
			// Deactivate any previously-active language not in the new list
			// (so re-running the wizard yields the user's expected result).
			foreach ( Languages::active() as $existing_code => $_ ) {
				if ( $existing_code === $default ) {
					continue;
				}
				if ( ! in_array( $existing_code, $codes, true ) ) {
					Languages::update_active( (string) $existing_code, false );
				}
			}
			Settings::save( array( 'setup_step' => 4 ) );
			wp_safe_redirect( self::url( 4 ) );
			exit;
		}

		wp_safe_redirect( self::url( 1 ) );
		exit;
	}

	public static function handle_skip(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		check_admin_referer( self::NONCE_SKIP );

		Settings::save(
			array(
				'setup_complete' => true,
				'setup_skipped'  => true,
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=' . AdminMenu::PARENT_SLUG ) );
		exit;
	}

	public static function handle_finish(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		check_admin_referer( self::NONCE_FINISH );

		Settings::save(
			array(
				'setup_complete' => true,
				'setup_step'     => 0,
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=' . AdminMenu::PARENT_SLUG ) );
		exit;
	}

	// ---- Internals -------------------------------------------------------

	/**
	 * Insert or update the given catalog entry as the site's default language.
	 * Existing rows are activated and promoted; new rows are inserted from catalog data.
	 */
	private static function install_or_set_default( string $code ): void {
		if ( '' === $code ) {
			return;
		}
		$entry = LanguageCatalog::get( $code );
		if ( null === $entry ) {
			return;
		}
		if ( Languages::exists( $code ) ) {
			Languages::update_active( $code, true );
			Languages::set_default( $code );
			return;
		}
		Languages::create(
			array(
				'code'       => $entry['code'],
				'locale'     => $entry['locale'],
				'name'       => $entry['name'],
				'native'     => $entry['native'],
				'flag'       => $entry['flag'],
				'rtl'        => $entry['rtl'],
				'active'     => 1,
				'is_default' => 1,
				'position'   => 0,
			)
		);
	}

	/**
	 * Insert or activate a non-default language by code.
	 */
	private static function install_or_activate( string $code, int $position ): void {
		$entry = LanguageCatalog::get( $code );
		if ( null === $entry ) {
			return;
		}
		if ( Languages::exists( $code ) ) {
			Languages::update_active( $code, true );
			return;
		}
		Languages::create(
			array(
				'code'       => $entry['code'],
				'locale'     => $entry['locale'],
				'name'       => $entry['name'],
				'native'     => $entry['native'],
				'flag'       => $entry['flag'],
				'rtl'        => $entry['rtl'],
				'active'     => 1,
				'is_default' => 0,
				'position'   => $position,
			)
		);
	}

	private static function skip_url(): string {
		return wp_nonce_url(
			add_query_arg(
				array( 'action' => self::ACTION_SKIP ),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_SKIP
		);
	}

	/**
	 * @param string|array<int,string> $preselected
	 */
	private static function render_catalog_options( string $field_name, $preselected, string $input_type, string $exclude_code = '' ): void {
		$preselected_set = is_array( $preselected ) ? array_flip( $preselected ) : array( $preselected => 0 );

		// Major languages first under a section header, then the rest.
		$major = LanguageCatalog::major();
		$all   = LanguageCatalog::all();
		$rest  = array_values(
			array_filter(
				$all,
				static fn( array $e ): bool => 0 === $e['major']
			)
		);

		$render_group = static function ( array $entries ) use ( $field_name, $preselected_set, $input_type, $exclude_code ): void {
			foreach ( $entries as $entry ) {
				if ( $entry['code'] === $exclude_code ) {
					continue;
				}
				$is_checked = isset( $preselected_set[ $entry['code'] ] );
				printf(
					'<label class="cml-lang-item" data-haystack="%1$s">
						<input type="%2$s" name="%3$s" value="%4$s"%5$s>
						<span class="cml-lang-flag">%6$s</span>
						<span class="cml-lang-meta">
							<span class="cml-lang-name">%7$s</span>
							<span class="cml-lang-native">%8$s · %9$s</span>
						</span>
					</label>',
					esc_attr( strtolower( $entry['code'] . '|' . $entry['name'] . '|' . $entry['native'] ) ),
					esc_attr( $input_type ),
					esc_attr( $field_name ),
					esc_attr( $entry['code'] ),
					$is_checked ? ' checked' : '',
					esc_html( LanguageCatalog::flag_emoji( $entry['flag'] ) ),
					esc_html( $entry['name'] ),
					esc_html( $entry['native'] ),
					esc_html( $entry['code'] )
				);
			}
		};

		if ( ! empty( $major ) ) {
			echo '<div class="cml-major-divider">' . esc_html__( 'Most-used', 'codeon-multilingual' ) . '</div>';
			$render_group( $major );
		}
		echo '<div class="cml-major-divider">' . esc_html__( 'All languages', 'codeon-multilingual' ) . '</div>';
		$render_group( $rest );
	}

	private static function render_search_script( string $input_id, string $grid_id ): void {
		?>
		<script>
			(function() {
				const input = document.getElementById('<?php echo esc_js( $input_id ); ?>');
				const grid  = document.getElementById('<?php echo esc_js( $grid_id ); ?>');
				if (!input || !grid) return;
				input.addEventListener('input', function() {
					const q = input.value.trim().toLowerCase();
					grid.querySelectorAll('.cml-lang-item').forEach(function(el) {
						const hay = el.dataset.haystack || '';
						el.style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
					});
				});
			})();
		</script>
		<?php
	}
}
