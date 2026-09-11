<?php
/**
 * An incoming reply becomes a note on the issue named in its subject.
 *
 * Needs an installed Mantis and its database. Everything created (project,
 * issues, notes, attachments) is removed again in the teardown.
 *
 * Run from the repository root:
 *   vendor/bin/phpunit -c plugins/EmailReporting/phpunit.xml --testsuite integration
 */

require_once dirname( __DIR__ ) . '/ERPIntegrationCase.php';

class EmailToNoteTest extends ERPIntegrationCase
{
	protected function setUp(): void
	{
		parent::setUp();

		// The configuration the bug was reported against.
		$this->setPluginConfig( array(
			'mail_add_bugnotes'			=> ON,
			'mail_remove_replies'		=> ON,
			'mail_strip_signature'		=> ON,
			'mail_save_from'			=> OFF,
			'mail_save_subject_in_note'	=> OFF,
			'mail_use_reporter'			=> ON,
			'mail_respect_permissions'	=> OFF,
			'mail_subject_id_regex'		=> 'strict',
		) );
	}

	# ─── the reported bug ────────────────────────────────────────────────────

	public function test_inline_quotes_reach_the_note(): void
	{
		$t_bug_id = $this->createIssue();

		$this->processEmail( $this->buildEmail( $t_bug_id,
			"Reagujem priamo v texte:\n\n"
			. "> Kedy bude hotový deploy?\n"
			. "V piatok popoludní.\n\n"
			. "> A čo migrácie DB?\n"
			. "Tie už bežia na stagingu.\n\n"
			. "Ďakujem\n"
		) );

		$t_note = $this->lastNoteText( $t_bug_id );

		$this->assertStringContainsString( '> Kedy bude hotový deploy?', $t_note );
		$this->assertStringContainsString( 'V piatok popoludní.', $t_note );
		$this->assertStringContainsString( '> A čo migrácie DB?', $t_note );
		$this->assertStringContainsString( 'Tie už bežia na stagingu.', $t_note );
		$this->assertStringContainsString( 'Ďakujem', $t_note );
	}

	public function test_own_reply_is_not_swallowed_by_the_quote_block(): void
	{
		// The blank line between fragments is what keeps markdown from
		// rendering the answer inside the grey quote box.
		$t_bug_id = $this->createIssue();

		$this->processEmail( $this->buildEmail( $t_bug_id, "> Kedy bude deploy?\nV piatok.\n" ) );

		$this->assertStringContainsString( "> Kedy bude deploy?\n\nV piatok.", $this->lastNoteText( $t_bug_id ) );
	}

	# ─── the trailing reply block is still removed ───────────────────────────

	public function test_trailing_quote_is_removed_from_the_note(): void
	{
		$t_bug_id = $this->createIssue();

		$this->processEmail( $this->buildEmail( $t_bug_id,
			"Súhlasím, môžeme to nasadiť v piatok.\n\n"
			. "On Mon, 1 Sep 2026 at 09:25, Jan Pekar <jan@imatic.cz> wrote:\n"
			. "> POVODNA SPRAVA RIADOK 1\n"
			. "> POVODNA SPRAVA RIADOK 2\n"
		) );

		$t_note = $this->lastNoteText( $t_bug_id );

		$this->assertStringContainsString( 'nasadiť v piatok', $t_note );
		$this->assertStringNotContainsString( 'POVODNA SPRAVA', $t_note );
	}

	public function test_inline_quotes_kept_while_trailing_block_is_removed(): void
	{
		$t_bug_id = $this->createIssue();

		$this->processEmail( $this->buildEmail( $t_bug_id,
			"Reagujem v texte:\n\n"
			. "> Kedy bude deploy?\nV piatok.\n\n"
			. "> A čo DB?\nTie bežia.\n\n"
			. "On Mon, 1 Sep 2026 at 09:25, Jan Pekar <jan@imatic.cz> wrote:\n"
			. "> CELA POVODNA SPRAVA\n> POKRACOVANIE\n"
		) );

		$t_note = $this->lastNoteText( $t_bug_id );

		$this->assertStringContainsString( '> Kedy bude deploy?', $t_note );
		$this->assertStringContainsString( '> A čo DB?', $t_note );
		$this->assertStringNotContainsString( 'CELA POVODNA SPRAVA', $t_note );
	}

	public function test_signature_is_stripped_from_the_note(): void
	{
		$t_bug_id = $this->createIssue();

		$this->processEmail( $this->buildEmail( $t_bug_id,
			"> Otázka?\nOdpoveď.\n\n--\nMatej Brodziansky\nImatic s.r.o.\n"
		) );

		$t_note = $this->lastNoteText( $t_bug_id );

		$this->assertStringContainsString( '> Otázka?', $t_note );
		$this->assertStringNotContainsString( 'Imatic s.r.o.', $t_note );
	}

	# ─── configuration is honoured end to end ────────────────────────────────

	public function test_reply_removal_off_keeps_the_whole_quoted_history(): void
	{
		$this->setPluginConfig( array( 'mail_remove_replies' => OFF ) );

		$t_bug_id = $this->createIssue();

		$this->processEmail( $this->buildEmail( $t_bug_id,
			"Súhlasím.\n\nOn Mon, 1 Sep 2026, Jan <jan@imatic.cz> wrote:\n> POVODNA SPRAVA\n"
		) );

		$this->assertStringContainsString( 'POVODNA SPRAVA', $this->lastNoteText( $t_bug_id ) );
	}

	public function test_sender_is_written_into_the_note_when_enabled(): void
	{
		$this->setPluginConfig( array( 'mail_save_from' => ON ) );

		$t_bug_id = $this->createIssue();

		$this->processEmail( $this->buildEmail( $t_bug_id, "Odpoveď bez citácie.\n" ) );

		$this->assertStringContainsString( self::SENDER, $this->lastNoteText( $t_bug_id ) );
	}

	public function test_notes_are_not_added_when_the_feature_is_off(): void
	{
		$this->setPluginConfig( array( 'mail_add_bugnotes' => OFF, 'mail_add_bug_reports' => OFF ) );

		$t_bug_id = $this->createIssue();

		$this->processEmail( $this->buildEmail( $t_bug_id, "Odpoveď, ktorá nemá vzniknúť.\n" ) );

		$this->assertSame( 0, $this->noteCount( $t_bug_id ) );
	}

	# ─── the note lands on the right issue ───────────────────────────────────

	public function test_note_goes_to_the_issue_named_in_the_subject(): void
	{
		$t_other_id = $this->createIssue( 'ERP test issue - must stay untouched' );
		$t_bug_id = $this->createIssue();

		$this->processEmail( $this->buildEmail( $t_bug_id, "Odpoveď na správny issue.\n" ) );

		$this->assertSame( 1, $this->noteCount( $t_bug_id ) );
		$this->assertSame( 0, $this->noteCount( $t_other_id ) );
	}

	# ─── whole emails from the fixtures ──────────────────────────────────────

	public function test_charset_and_transfer_encoding_survive_to_the_note(): void
	{
		$t_bug_id = $this->createIssue();

		// The fixture is windows-1250 + quoted-printable, the combination that
		// used to lose letters on PHP builds without native windows-125x support.
		$t_raw = $this->fixture( 'charset_windows1250' );
		$t_raw = $this->retargetSubject( $t_raw, $t_bug_id );

		$this->processEmail( $t_raw );

		$t_note = $this->lastNoteText( $t_bug_id );

		$this->assertStringContainsString( 'Kedy bude hotový deploy?', $t_note );
		$this->assertStringContainsString( 'V piatok popoludní.', $t_note );
	}

	public function test_attachment_is_stored_and_the_body_keeps_its_quote(): void
	{
		$t_bug_id = $this->createIssue();

		$t_raw = $this->retargetSubject( $this->fixture( 'multipart_attachment' ), $t_bug_id );

		$this->processEmail( $t_raw );

		$this->assertSame( 1, $this->attachmentCount( $t_bug_id ) );

		$t_note = $this->lastNoteText( $t_bug_id );
		$this->assertStringContainsString( '> A co ta tabulka?', $t_note );
		$this->assertStringContainsString( 'Je v prilohe.', $t_note );
	}

	/**
	 * Point a fixture at the issue this test just created.
	 *
	 * The fixtures carry a fixed "[Test Project 0001234]" subject so they can be
	 * read on their own; matching needs the real project name and issue id.
	 */
	private function retargetSubject( $p_raw_email, $p_bug_id )
	{
		$t_subject = '[' . project_get_field( self::$projectId, 'name' ) . ' '
			. sprintf( '%07d', $p_bug_id ) . ']';

		return preg_replace( '/\[Test Project 0001234\]/', $t_subject, $p_raw_email, 1 );
	}
}
