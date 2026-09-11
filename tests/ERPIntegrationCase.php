<?php
/**
 * Base class for the EmailReporting tests that need a real Mantis.
 *
 * A raw .eml is handed straight to ERP_mailbox_api::process_single_email()
 * behind a mock mail server, so the whole production path runs - parsing,
 * charset handling, quote removal, reporter resolution, note creation - without
 * an IMAP or POP3 connection. The mailserver suite subclasses this and swaps
 * the mock for GreenMail.
 *
 * Everything created is torn down again: its own project, its own issues, and
 * the plugin configuration is restored to the values it had before the test.
 */

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Minimal POP3 server returning one hardcoded message.
 *
 * ERP_mailbox_api only calls getMsg() on the POP3 path, which is the shortest
 * way into process_single_email().
 */
class ERP_MockPOP3Server
{
	private $_raw_email;

	public function __construct( $p_raw_email )
	{
		$this->_raw_email = $p_raw_email;
	}

	public function getMsg( $p_msg_id )
	{
		return( $this->_raw_email );
	}

	public function getMessages( $p_msg_id, $p_include_body = TRUE )
	{
		return( array( $p_msg_id => $this->_raw_email ) );
	}
}

abstract class ERPIntegrationCase extends TestCase
{
	/** Sender must be a real, non-disposable domain or validate_email_address() rejects it. */
	const SENDER = 'erp.tests@imatic.cz';

	/** Reserved TLD per RFC2606, so the account notification cannot reach anyone. */
	const TEST_USER_EMAIL_DOMAIN = 'erp-tests.test';

	/** @var int the throwaway account the suite runs as */
	protected static $userId;

	/** @var int */
	protected static $projectId;

	/** @var int */
	protected static $categoryId;

	/** @var array plugin configuration as it was before the tests changed it */
	private static $configBackup = array();

	/** @var int[] issues created by the running test */
	private $createdBugs = array();

	/** @var string what the plugin printed while processing the last email */
	protected $processOutput = '';

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		if ( !ERP_TESTS_HAVE_MANTIS )
		{
			self::markTestSkipped(
				'needs an installed Mantis: start phpunit from the Mantis root with ERP_TESTS_MANTIS=1'
			);
		}

		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$GLOBALS['g_bypass_headers'] = 1;

		# Nothing may leave the machine: the suite adds notes, and Mantis would
		# notify the reporter and everyone monitoring the issue.
		config_set_global( 'enable_email_notification', OFF );

		# Address validation does a DNS lookup when check_mx_record is ON, which
		# would make the suite depend on the network and on the reserved .test
		# domain resolving. It is a global, so this is in-memory only.
		config_set_global( 'check_mx_record', OFF );

		self::login();

		self::registerPluginDefinitions();

		plugin_push_current( 'EmailReporting' );
		plugin_require_api( 'core/mail_api.php' );
		plugin_require_api( 'core/config_api.php' );
		plugin_pop_current();

		# The plugin reads its configuration in the constructor, so tests set it
		# before building ERP_mailbox_api rather than after.
		self::backupConfig( array(
			'mail_add_bugnotes',
			'mail_add_bug_reports',
			'mail_remove_replies',
			'mail_strip_signature',
			'mail_parse_html',
			'mail_save_from',
			'mail_save_subject_in_note',
			'mail_use_reporter',
			'mail_respect_permissions',
			'mail_auto_signup',
			'mail_subject_id_regex',
		) );

		self::$projectId = self::createProject();
		self::$categoryId = self::createCategory( self::$projectId );
	}

	public static function tearDownAfterClass(): void
	{
		self::restoreConfig();

		if ( self::$projectId )
		{
			project_delete( self::$projectId );
			self::$projectId = null;
		}

		if ( self::$userId )
		{
			user_delete( self::$userId );
			self::$userId = null;
		}

		parent::tearDownAfterClass();
	}

	protected function tearDown(): void
	{
		foreach ( $this->createdBugs as $t_bug_id )
		{
			if ( bug_exists( $t_bug_id ) )
			{
				bug_delete( $t_bug_id );
			}
		}

		$this->createdBugs = array();

		parent::tearDown();
	}

	/**
	 * Declare what the plugin would declare if it were installed.
	 *
	 * On an installation that has never installed EmailReporting - a freshly
	 * built CI Mantis - nothing has run MantisPlugin::__init(), and the
	 * production code path walks into two walls:
	 *
	 *   - ERP_mailbox_api reads two dozen options in its constructor without
	 *     passing a default, so the first one raises
	 *     ERROR_CONFIG_OPT_NOT_FOUND
	 *   - parse_content() signals EVENT_ERP_PARSER_OPTIONS, which raises
	 *     ERROR_EVENT_UNDECLARED
	 *
	 * Both come from the plugin class itself, so taking them from there gives
	 * exactly what a stock installation runs with. The hooks are deliberately
	 * left out: they wire the plugin into Mantis pages, which no test opens.
	 *
	 * The configuration is in-memory only, and config_set_global() leaves an
	 * option that is already set alone, so an installation that does have the
	 * plugin installed keeps its own configuration and the tests run against
	 * that instead.
	 */
	protected static function registerPluginDefinitions()
	{
		if ( plugin_is_loaded( 'EmailReporting' ) )
		{
			return;
		}

		$t_plugin = plugin_register( 'EmailReporting', TRUE );

		plugin_push_current( 'EmailReporting' );
		plugin_config_defaults( $t_plugin->config() );
		plugin_pop_current();

		event_declare_many( $t_plugin->events() );

		# So that a plugin error reports what went wrong rather than a missing
		# language string, which is what a failing test would otherwise show
		$t_lang = lang_get_current();

		foreach ( $t_plugin->errors() as $t_name => $t_string )
		{
			$GLOBALS['g_lang_strings'][ $t_lang ][ 'MANTIS_ERROR' ][ 'plugin_EmailReporting_' . $t_name ] = $t_string;
		}
	}

	/**
	 * Creating projects, issues and notes needs a logged in user, which a CLI
	 * run has not got.
	 *
	 * The suite creates its own account rather than expecting one to exist, so
	 * it does not depend on the credentials of whichever install it runs
	 * against. The account is removed again in the teardown.
	 */
	protected static function login()
	{
		$t_username = 'erp_tests_' . uniqid();
		$t_password = 'Erp.' . bin2hex( random_bytes( 8 ) );

		$t_cookie = user_create(
			$t_username,
			$t_password,
			$t_username . '@' . self::TEST_USER_EMAIL_DOMAIN,
			ADMINISTRATOR
		);

		self::$userId = user_get_id_by_cookie( $t_cookie );

		self::assertTrue(
			auth_attempt_script_login( $t_username, $t_password ),
			'could not log in as the account the suite just created'
		);
	}

	# ─── configuration ───────────────────────────────────────────────────────

	private static function backupConfig( array $p_options )
	{
		plugin_push_current( 'EmailReporting' );

		foreach ( $p_options as $t_option )
		{
			self::$configBackup[ $t_option ] = plugin_config_get( $t_option );
		}

		plugin_pop_current();
	}

	private static function restoreConfig()
	{
		plugin_push_current( 'EmailReporting' );

		foreach ( self::$configBackup as $t_option => $t_value )
		{
			plugin_config_set( $t_option, $t_value );
		}

		plugin_pop_current();
		self::$configBackup = array();
	}

	/**
	 * Set EmailReporting options for the test about to run.
	 *
	 * @param array $p_options option name => value
	 */
	protected function setPluginConfig( array $p_options )
	{
		plugin_push_current( 'EmailReporting' );

		foreach ( $p_options as $t_option => $t_value )
		{
			plugin_config_set( $t_option, $t_value );
		}

		plugin_pop_current();

		# plugin_config_get() caches, and ERP_mailbox_api reads through it
		config_flush_cache();
	}

	# ─── fixtures ────────────────────────────────────────────────────────────

	private static function createProject()
	{
		$t_name = 'ERP tests ' . uniqid();

		return project_create( $t_name, 'Created by the EmailReporting test suite', VS_PUBLIC );
	}

	private static function createCategory( $p_project_id )
	{
		return category_add( $p_project_id, 'ERP tests' );
	}

	/**
	 * Create the issue an emailed note is expected to land on.
	 *
	 * @return int bug id
	 */
	protected function createIssue( $p_summary = 'ERP test issue' )
	{
		$t_bug = new BugData;
		$t_bug->project_id = self::$projectId;
		$t_bug->category_id = self::$categoryId;
		$t_bug->reporter_id = auth_get_current_user_id();
		$t_bug->summary = $p_summary;
		$t_bug->description = 'Created by the EmailReporting test suite';

		$t_bug_id = $t_bug->create();
		$this->createdBugs[] = $t_bug_id;

		return $t_bug_id;
	}

	# ─── the email itself ────────────────────────────────────────────────────

	/**
	 * Build a raw email addressed at an existing issue.
	 *
	 * The subject has to satisfy the strict issue id regex, which wants
	 * "[<project> <id>]", otherwise the mail becomes a new issue instead of a
	 * note on this one.
	 *
	 * @param int    $p_bug_id
	 * @param string $p_body
	 * @param string $p_content_type
	 * @return string
	 */
	protected function buildEmail( $p_bug_id, $p_body, $p_content_type = 'text/plain; charset="utf-8"' )
	{
		$t_subject = '[' . project_get_field( self::$projectId, 'name' ) . ' '
			. sprintf( '%07d', $p_bug_id ) . ']: odpoved z mailu';

		return "Return-Path: <" . self::SENDER . ">\n"
			. "From: ERP Tests <" . self::SENDER . ">\n"
			. "To: mantis@imatic.cz\n"
			. "Subject: " . $t_subject . "\n"
			. "Date: " . date( 'r' ) . "\n"
			. "Message-ID: <" . uniqid( 'erp-test-' ) . "@imatic.cz>\n"
			. "MIME-Version: 1.0\n"
			. "Content-Type: " . $p_content_type . "\n"
			. "Content-Transfer-Encoding: 8bit\n"
			. "\n"
			. $p_body;
	}

	protected function fixture( $p_name )
	{
		$t_file = ERP_FIXTURE_PATH . '/' . $p_name . '.eml';
		$this->assertFileExists( $t_file );

		return file_get_contents( $t_file );
	}

	/**
	 * Run a raw email through the production processing path.
	 *
	 * @param string $p_raw_email
	 * @return bool what process_single_email() returned
	 */
	protected function processEmail( $p_raw_email )
	{
		plugin_push_current( 'EmailReporting' );

		$t_erp = new ERP_mailbox_api( FALSE );

		$t_mailbox = array(
			'enabled'						=> ON,
			'description'					=> 'EmailReporting test mailbox',
			'mailbox_type'					=> 'POP3',
			'hostname'						=> 'localhost',
			'port'							=> 110,
			'encryption'					=> 'None',
			'ssl_cert_verify'				=> OFF,
			'auth_method'					=> 'USER',
			'erp_username'					=> self::SENDER,
			'erp_password'					=> '',
			'project_id'					=> self::$projectId,
			'global_category_id'			=> self::$categoryId,
			'imap_basefolder'				=> '',
			'imap_createfolderstructure'	=> OFF,
			'plugin_content'				=> array(),
		) + ERP_get_default_mailbox();

		$t_reflection = new ReflectionClass( $t_erp );

		$t_set = static function ( $p_property, $p_value ) use ( $t_reflection, $t_erp ) {
			$t_prop = $t_reflection->getProperty( $p_property );
			$t_prop->setAccessible( TRUE );
			$t_prop->setValue( $t_erp, $p_value );
		};

		$t_set( '_mailbox', $t_mailbox );
		$t_set( '_mailserver', new ERP_MockPOP3Server( $p_raw_email ) );
		$t_set( '_mailbox_starttime', ERP_get_timestamp() );

		$t_method = $t_reflection->getMethod( 'process_single_email' );
		$t_method->setAccessible( TRUE );

		# The plugin reports what it did on stdout, which would bury the phpunit
		# output. Kept in $processOutput so a failing test can still show it.
		ob_start();

		try
		{
			$t_result = $t_method->invoke( $t_erp, 1, FALSE );
		}
		finally
		{
			$this->processOutput = ob_get_clean();
			plugin_pop_current();
		}

		return $t_result;
	}

	# ─── assertions on the result ────────────────────────────────────────────

	/**
	 * The text of the most recently added note on an issue.
	 *
	 * @param int $p_bug_id
	 * @return string
	 */
	protected function lastNoteText( $p_bug_id )
	{
		$t_notes = bugnote_get_all_bugnotes( $p_bug_id );
		$this->assertNotEmpty( $t_notes, 'no note was added to issue ' . $p_bug_id );

		$t_last = end( $t_notes );

		return $t_last->note;
	}

	protected function noteCount( $p_bug_id )
	{
		return count( bugnote_get_all_bugnotes( $p_bug_id ) );
	}

	/**
	 * Number of files attached to an issue.
	 *
	 * @param int $p_bug_id
	 * @return int
	 */
	protected function attachmentCount( $p_bug_id )
	{
		return count( file_get_visible_attachments( $p_bug_id ) );
	}
}
