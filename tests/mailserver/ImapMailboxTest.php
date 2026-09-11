<?php
/**
 * EmailReporting against a real IMAP server (GreenMail).
 *
 * Covers what the integration suite cannot: the connection, the login, folder
 * handling, and what happens to a message once it has been turned into a note.
 * The message is delivered over SMTP first, so nothing here shortcuts the
 * plugin's own fetching code.
 *
 * Run from the repository root, with GreenMail up:
 *   ERP_TESTS_MANTIS=1 vendor/bin/phpunit -c plugins/EmailReporting/phpunit.xml --testsuite mailserver
 */

require_once dirname( __DIR__ ) . '/ERPMailserverCase.php';

class ImapMailboxTest extends ERPMailserverCase
{
	protected function setUp(): void
	{
		parent::setUp();

		$this->setPluginConfig( array(
			'mail_add_bugnotes'			=> ON,
			'mail_add_bug_reports'		=> ON,
			'mail_remove_replies'		=> ON,
			'mail_strip_signature'		=> ON,
			'mail_save_from'			=> OFF,
			'mail_use_reporter'			=> ON,
			'mail_respect_permissions'	=> OFF,
			'mail_subject_id_regex'		=> 'strict',
			'mail_delete'				=> ON,
		) );
	}

	# ─── the whole path, over the wire ───────────────────────────────────────

	public function test_email_fetched_over_imap_becomes_a_note(): void
	{
		$t_bug_id = $this->createIssue();

		$this->deliver( $this->buildEmail( $t_bug_id,
			"Reagujem priamo v texte:\n\n"
			. "> Kedy bude hotový deploy?\n"
			. "V piatok popoludní.\n\n"
			. "On Mon, 1 Sep 2026 at 09:25, Jan Pekar <jan@imatic.cz> wrote:\n"
			. "> CELA POVODNA SPRAVA\n"
		) );

		$this->assertSame( 1, $this->mailboxMessageCount(), 'the message was not delivered to the mailbox' );

		$this->processMailbox();

		$t_note = $this->lastNoteText( $t_bug_id );

		// The inline quote has to survive the trip through IMAP as well.
		$this->assertStringContainsString( '> Kedy bude hotový deploy?', $t_note );
		$this->assertStringContainsString( 'V piatok popoludní.', $t_note );
		$this->assertStringNotContainsString( 'CELA POVODNA SPRAVA', $t_note );
	}

	public function test_several_emails_in_one_run(): void
	{
		$t_first_id = $this->createIssue();
		$t_second_id = $this->createIssue();

		$this->deliver( $this->buildEmail( $t_first_id, "Prvá odpoveď.\n" ) );
		$this->deliver( $this->buildEmail( $t_second_id, "Druhá odpoveď.\n" ) );

		$this->processMailbox();

		$this->assertStringContainsString( 'Prvá odpoveď.', $this->lastNoteText( $t_first_id ) );
		$this->assertStringContainsString( 'Druhá odpoveď.', $this->lastNoteText( $t_second_id ) );
	}

	public function test_utf8_email_from_a_fixture_survives_imap(): void
	{
		$t_bug_id = $this->createIssue();

		$t_raw = str_replace(
			'[Test Project 0001234]',
			'[' . project_get_field( self::$projectId, 'name' ) . ' ' . sprintf( '%07d', $t_bug_id ) . ']',
			$this->fixture( 'inline_quote' )
		);

		$this->deliver( $t_raw );
		$this->processMailbox();

		$t_note = $this->lastNoteText( $t_bug_id );

		$this->assertStringContainsString( '> Kedy bude hotový deploy?', $t_note );
		$this->assertStringContainsString( 'Tie už bežia na stagingu.', $t_note );
	}

	# ─── what happens to the message afterwards ──────────────────────────────

	public function test_processed_message_is_deleted_when_mail_delete_is_on(): void
	{
		$t_bug_id = $this->createIssue();

		$this->deliver( $this->buildEmail( $t_bug_id, "Odpoveď na zmazanie.\n" ) );
		$this->processMailbox();

		$this->assertSame( 0, $this->mailboxMessageCount(), 'the processed message should have been expunged' );
	}

	public function test_processed_message_is_kept_when_mail_delete_is_off(): void
	{
		$this->setPluginConfig( array( 'mail_delete' => OFF ) );

		$t_bug_id = $this->createIssue();

		$this->deliver( $this->buildEmail( $t_bug_id, "Odpoveď, ktorá má zostať v mailboxe.\n" ) );
		$this->processMailbox();

		$this->assertSame( 1, $this->mailboxMessageCount(), 'the message should still be in the mailbox' );
		$this->assertSame( 1, $this->noteCount( $t_bug_id ), 'and it should still have produced a note' );
	}

	# ─── failure modes ───────────────────────────────────────────────────────

	public function test_empty_mailbox_is_not_an_error(): void
	{
		// process_imap_folder() has to survive a folder with no messages: the
		// alternative used to be an error per empty folder, and enough of those
		// make some servers drop the connection.
		$t_result = $this->processMailbox();

		$this->assertNotInstanceOf( 'PEAR_Error', $t_result );
	}

	public function test_wrong_password_is_reported_as_a_failure(): void
	{
		$t_result = $this->processMailbox( array( 'erp_password' => 'definitely-not-the-password' ) );

		$this->assertInstanceOf( 'PEAR_Error', $t_result, 'a rejected login must surface as an error' );
	}

	public function test_unknown_basefolder_is_reported(): void
	{
		$this->processMailbox( array( 'imap_basefolder' => 'THIS_FOLDER_DOES_NOT_EXIST' ) );

		$this->assertStringContainsString( 'basefolder not found', $this->processOutput );
	}

	public function test_folder_structure_creates_a_folder_per_project(): void
	{
		$this->processMailbox( array( 'imap_createfolderstructure' => ON ) );

		$this->assertTrue(
			$this->mailboxFolderExists( 'INBOX.' . project_get_field( self::$projectId, 'name' ) ),
			'the mailbox should have gained a folder for the test project'
		);
	}
}
