<?php
/**
 * Base class for the tests that go through a real mail server.
 *
 * The integration suite hands the raw email straight to the processing code, so
 * it never touches Net_IMAP. This suite closes that gap: the message is
 * delivered over SMTP to a GreenMail instance and EmailReporting then fetches
 * it over IMAP exactly as it does in production, which is the only way to cover
 * the connection, the login, folder selection and the deletion of processed
 * messages.
 *
 * GreenMail is started as a service container in CI. Locally:
 *
 *   docker run -d --name greenmail --network <mantis network> \
 *     -e GREENMAIL_OPTS='-Dgreenmail.setup.test.all -Dgreenmail.hostname=0.0.0.0 -Dgreenmail.users=mantis:mantis@localhost -Dgreenmail.verbose' \
 *     greenmail/standalone:2.1.5
 *
 * When no GreenMail is reachable the whole suite skips itself rather than
 * failing, so a plain `phpunit` on a laptop stays green.
 */

require_once __DIR__ . '/ERPIntegrationCase.php';

abstract class ERPMailserverCase extends ERPIntegrationCase
{
	/** GreenMail creates this account from the GREENMAIL_OPTS above. */
	const MAILBOX_USER = 'mantis';
	const MAILBOX_PASSWORD = 'mantis';
	const MAILBOX_ADDRESS = 'mantis@localhost';

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		if ( !self::mailserverReachable() )
		{
			$t_message = sprintf(
				'no mail server on %s:%d - see the class comment for how to start GreenMail',
				self::mailserverHost(),
				self::imapPort()
			);

			# CI sets ERP_GREENMAIL_REQUIRED, because there the mail server is
			# supposed to be running: a silent skip would quietly stop covering
			# the IMAP path instead of reporting that the service is broken.
			if ( getenv( 'ERP_GREENMAIL_REQUIRED' ) )
			{
				self::fail( $t_message );
			}

			self::markTestSkipped( $t_message );
		}
	}

	protected function setUp(): void
	{
		parent::setUp();

		$this->emptyMailbox();
	}

	# ─── where the mail server is ────────────────────────────────────────────

	protected static function mailserverHost()
	{
		# In CI GreenMail is a service container published on the runner itself;
		# locally it is a container on the same network as Mantis.
		return getenv( 'ERP_GREENMAIL_HOST' ) ?: '127.0.0.1';
	}

	protected static function imapPort()
	{
		return (int) ( getenv( 'ERP_GREENMAIL_IMAP_PORT' ) ?: 3143 );
	}

	protected static function smtpPort()
	{
		return (int) ( getenv( 'ERP_GREENMAIL_SMTP_PORT' ) ?: 3025 );
	}

	private static function mailserverReachable()
	{
		$t_socket = @fsockopen( self::mailserverHost(), self::imapPort(), $t_errno, $t_error, 3 );

		if ( $t_socket === FALSE )
		{
			return FALSE;
		}

		fclose( $t_socket );

		return TRUE;
	}

	# ─── delivering a message ────────────────────────────────────────────────

	/**
	 * Deliver a raw message to the test mailbox over SMTP.
	 *
	 * Deliberately a hand written client: it keeps the suite free of a mail
	 * library and makes it obvious that the message really travels over the
	 * wire rather than being handed to the plugin directly.
	 *
	 * @param string $p_raw_email
	 * @param string $p_recipient
	 */
	protected function deliver( $p_raw_email, $p_recipient = self::MAILBOX_ADDRESS )
	{
		$t_socket = @fsockopen( self::mailserverHost(), self::smtpPort(), $t_errno, $t_error, 5 );
		$this->assertNotFalse( $t_socket, 'SMTP connect failed: ' . $t_error );

		stream_set_timeout( $t_socket, 5 );

		$t_expect = function ( $p_code ) use ( $t_socket ) {
			$t_line = '';

			# A multiline reply repeats the code with a hyphen: "250-STARTTLS".
			do
			{
				$t_line = fgets( $t_socket, 1024 );
				$this->assertNotFalse( $t_line, 'SMTP connection closed while waiting for ' . $p_code );
			}
			while ( strlen( $t_line ) > 3 && $t_line[3] === '-' );

			$this->assertStringStartsWith( (string)$p_code, $t_line, 'unexpected SMTP reply' );
		};

		$t_send = function ( $p_command, $p_code ) use ( $t_socket, $t_expect ) {
			fwrite( $t_socket, $p_command . "\r\n" );
			$t_expect( $p_code );
		};

		$t_expect( 220 );
		$t_send( 'HELO erp-tests', 250 );
		$t_send( 'MAIL FROM:<' . self::SENDER . '>', 250 );
		$t_send( 'RCPT TO:<' . $p_recipient . '>', 250 );
		$t_send( 'DATA', 354 );

		# Dot stuffing, and the message must use CRLF line endings on the wire.
		$t_message = preg_replace( '/\r\n|\n|\r/', "\r\n", $p_raw_email );
		$t_message = preg_replace( '/^\./m', '..', $t_message );

		fwrite( $t_socket, rtrim( $t_message, "\r\n" ) . "\r\n.\r\n" );
		$t_expect( 250 );

		$t_send( 'QUIT', 221 );
		fclose( $t_socket );
	}

	# ─── fetching, the way production does ──────────────────────────────────

	/**
	 * The mailbox configuration pointing at GreenMail.
	 *
	 * @param array $p_overrides
	 * @return array
	 */
	protected function mailboxConfig( array $p_overrides = array() )
	{
		return $p_overrides + array(
			'enabled'						=> ON,
			'description'					=> 'GreenMail test mailbox',
			'mailbox_type'					=> 'IMAP',
			'hostname'						=> self::mailserverHost(),
			'port'							=> self::imapPort(),
			'encryption'					=> 'None',
			'ssl_cert_verify'				=> OFF,
			'auth_method'					=> 'USER',
			'erp_username'					=> self::MAILBOX_USER,
			# Mailbox passwords are stored base64 encoded; the plugin decodes
			# them again right before the login.
			'erp_password'					=> base64_encode( self::MAILBOX_PASSWORD ),
			'project_id'					=> self::$projectId,
			'global_category_id'			=> self::$categoryId,
			'imap_basefolder'				=> 'INBOX',
			'imap_createfolderstructure'	=> OFF,
			'plugin_content'				=> array(),
		) + ERP_get_default_mailbox();
	}

	/**
	 * Run the mailbox the way the cron job does: connect, fetch, process.
	 *
	 * @param array $p_overrides mailbox settings to change for this run
	 * @return mixed what process_mailbox() returned
	 */
	protected function processMailbox( array $p_overrides = array() )
	{
		plugin_push_current( 'EmailReporting' );

		$t_erp = new ERP_mailbox_api( FALSE );

		ob_start();

		try
		{
			$t_result = $t_erp->process_mailbox( $this->mailboxConfig( $p_overrides ) );
		}
		finally
		{
			$this->processOutput = ob_get_clean();
			plugin_pop_current();
		}

		return $t_result;
	}

	# ─── looking at the mailbox itself ───────────────────────────────────────

	/**
	 * How many messages are left in the mailbox.
	 *
	 * Uses its own IMAP connection so it reports the state the plugin left
	 * behind, not the state of the plugin's own session.
	 *
	 * @param string $p_folder
	 * @return int
	 */
	protected function mailboxMessageCount( $p_folder = 'INBOX' )
	{
		return $this->withImap( function ( $p_imap ) use ( $p_folder ) {
			$t_result = $p_imap->examineMailbox( $p_folder );

			return is_array( $t_result ) ? (int) $t_result['EXISTS'] : 0;
		} );
	}

	protected function mailboxFolderExists( $p_folder )
	{
		return $this->withImap( function ( $p_imap ) use ( $p_folder ) {
			return $p_imap->mailboxExist( $p_folder ) === TRUE;
		} );
	}

	/** Remove everything from INBOX so each test starts from a known state. */
	protected function emptyMailbox()
	{
		$this->withImap( function ( $p_imap ) {
			$p_imap->deleteMessages();
		}, TRUE );
	}

	/**
	 * Run a callback against a logged in IMAP connection.
	 *
	 * Net_IMAP pulls Auth/SASL in through plugin_require_api() the moment it is
	 * constructed, so EmailReporting has to stay the current plugin for as long
	 * as the connection is in use.
	 *
	 * @param callable $p_callback     receives the Net_IMAP instance
	 * @param bool     $p_expunge_on_exit whether deletions are committed on disconnect
	 * @return mixed whatever the callback returned
	 */
	private function withImap( callable $p_callback, $p_expunge_on_exit = FALSE )
	{
		plugin_push_current( 'EmailReporting' );

		try
		{
			plugin_require_api( 'core_pear/Net/IMAP.php' );

			$t_imap = new Net_IMAP();
			$t_imap->setTimeout( 5 );
			$t_imap->connect( self::mailserverHost(), self::imapPort(), FALSE );

			$this->assertTrue( $t_imap->_connected, 'IMAP connect failed' );

			$t_login = $t_imap->login( self::MAILBOX_USER, self::MAILBOX_PASSWORD );
			$this->assertFalse( PEAR::isError( $t_login ), 'IMAP login failed' );

			try
			{
				return $p_callback( $t_imap );
			}
			finally
			{
				$t_imap->disconnect( (bool)$p_expunge_on_exit );
			}
		}
		finally
		{
			plugin_pop_current();
		}
	}
}
