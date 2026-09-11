<?php
/**
 * Quote and signature handling in the body of an incoming email.
 *
 * Covers the bug reported by jan.pekar (~0519949): a reply that answers the
 * original message point by point, with "> " quoted fragments in between the
 * authors own sentences, used to lose every quoted fragment and the resulting
 * note made no sense. Only the trailing reply block may be dropped.
 *
 * Pure tests: no Mantis, no database, nothing is created.
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

erp_test_require_plugin_api( 'core/mail_body_pure.php' );

use PHPUnit\Framework\TestCase;

class FragmentSelectionTest extends TestCase
{
	// Both options are ON in the configuration this bug was reported against,
	// and that combination is what production runs.
	private function parse( $p_body, $p_remove_replies = true, $p_strip_signature = true )
	{
		return erp_parse_email_body( $p_body, $p_remove_replies, $p_strip_signature );
	}

	private function parseLegacy( $p_body )
	{
		return erp_parse_email_body_legacy( $p_body, true, true );
	}

	// ─── the trailing reply block is still dropped ───────────────────────────

	public function test_trailing_quote_after_attribution_line_is_dropped(): void
	{
		$t_note = $this->parse(
			"Suhlasim, mozeme to nasadit v piatok.\n\n"
			. "On Mon, 1 Sep 2026 at 09:25, Jan Pekar <jan@imatic.cz> wrote:\n"
			. "> POVODNA SPRAVA RIADOK 1\n"
			. "> POVODNA SPRAVA RIADOK 2\n"
		);

		$this->assertStringContainsString( 'nasadit v piatok', $t_note );
		$this->assertStringNotContainsString( 'POVODNA SPRAVA', $t_note );
	}

	public function test_forward_without_own_text_keeps_nothing(): void
	{
		$t_note = $this->parse(
			"On Mon, 1 Sep 2026, Jan Pekar <jan@imatic.cz> wrote:\n"
			. "> IBA PREPOSLANA SPRAVA\n"
		);

		$this->assertStringNotContainsString( 'IBA PREPOSLANA SPRAVA', $t_note );
	}

	// ─── inline quotes are kept (the reported bug) ───────────────────────────

	public function test_inline_quotes_between_own_sentences_are_kept(): void
	{
		$t_body = "Reagujem priamo v texte:\n\n"
			. "> Kedy bude hotovy deploy?\n"
			. "V piatok popoludni.\n\n"
			. "> A co migracie DB?\n"
			. "Tie uz bezia na stagingu.\n\n"
			. "Diky\n";

		$t_note = $this->parse( $t_body );

		foreach ( array( 'Kedy bude hotovy deploy?', 'V piatok popoludni.', 'A co migracie DB?', 'Tie uz bezia na stagingu.', 'Diky' ) as $t_expected )
		{
			$this->assertStringContainsString( $t_expected, $t_note );
		}
	}

	public function test_inline_quotes_were_lost_before_the_fix(): void
	{
		// Guards the regression itself: if this ever stops failing under the old
		// selection, the fixture no longer reproduces what jan.pekar reported.
		$t_body = "Reagujem priamo v texte:\n\n"
			. "> Kedy bude hotovy deploy?\n"
			. "V piatok popoludni.\n";

		$this->assertStringNotContainsString( 'Kedy bude hotovy deploy?', $this->parseLegacy( $t_body ) );
		$this->assertStringContainsString( 'Kedy bude hotovy deploy?', $this->parse( $t_body ) );
	}

	public function test_inline_quotes_kept_while_trailing_block_is_dropped(): void
	{
		$t_note = $this->parse(
			"Reagujem v texte:\n\n"
			. "> Kedy bude deploy?\nV piatok.\n\n"
			. "> A co DB?\nTie bezia.\n\n"
			. "On Mon, 1 Sep 2026 at 09:25, Jan Pekar <jan@imatic.cz> wrote:\n"
			. "> CELA POVODNA SPRAVA\n> POKRACOVANIE\n"
		);

		$this->assertStringContainsString( 'Kedy bude deploy?', $t_note );
		$this->assertStringContainsString( 'A co DB?', $t_note );
		$this->assertStringNotContainsString( 'CELA POVODNA SPRAVA', $t_note );
		$this->assertStringNotContainsString( 'POKRACOVANIE', $t_note );
	}

	public function test_inline_quote_kept_and_signature_stripped(): void
	{
		$t_note = $this->parse( "> Otazka?\nOdpoved.\n\n--\nMatej Brodziansky\nImatic\n" );

		$this->assertStringContainsString( 'Otazka?', $t_note );
		$this->assertStringContainsString( 'Odpoved.', $t_note );
		$this->assertStringNotContainsString( 'Imatic', $t_note );
	}

	public function test_nested_quote_levels_are_kept_inline(): void
	{
		$t_note = $this->parse( ">> Povodna otazka\n> Prvá odpoveď\nMoja odpoveď.\n" );

		$this->assertStringContainsString( 'Povodna otazka', $t_note );
		$this->assertStringContainsString( 'Prvá odpoveď', $t_note );
		$this->assertStringContainsString( 'Moja odpoveď.', $t_note );
	}

	// ─── markdown rendering ──────────────────────────────────────────────────

	public function test_own_reply_is_separated_from_the_quote_by_a_blank_line(): void
	{
		// Without the blank line markdown lazy continuation pulls the following
		// line into the blockquote and the answer renders inside the grey box.
		$t_note = $this->parse( "> Kedy bude deploy?\nV piatok.\n" );

		$this->assertMatchesRegularExpression( '/^> Kedy bude deploy\?\n\nV piatok\.$/', $t_note );
	}

	// ─── configuration is honoured ───────────────────────────────────────────

	public function test_both_options_off_returns_the_body_untouched(): void
	{
		$t_body = "Odpoved.\n\n> POVODNA SPRAVA\n\n--\nPodpis\n";

		$this->assertSame( $t_body, $this->parse( $t_body, false, false ) );
	}

	public function test_signature_is_kept_when_stripping_is_off(): void
	{
		$t_note = $this->parse( "Odpoved.\n\n--\nMatej Brodziansky\n", true, false );

		$this->assertStringContainsString( 'Matej Brodziansky', $t_note );
	}

	public function test_trailing_quote_is_kept_when_reply_removal_is_off(): void
	{
		$t_note = $this->parse(
			"Suhlasim.\n\nOn Mon, 1 Sep 2026, Jan <j@imatic.cz> wrote:\n> POVODNA SPRAVA\n",
			false,
			true
		);

		$this->assertStringContainsString( 'POVODNA SPRAVA', $t_note );
	}

	// ─── documented limitation ───────────────────────────────────────────────

	public function test_disclaimer_below_the_quote_keeps_the_quote(): void
	{
		// A corporate disclaimer appended below the quoted block counts as own
		// text, so the quote above it is no longer the trailing block and stays.
		// Conservative on purpose: the note keeps too much rather than too little.
		$t_note = $this->parse(
			"Suhlasim.\n\nOn Mon, 1 Sep 2026, Jan <j@imatic.cz> wrote:\n> POVODNA SPRAVA\n\n"
			. "Tento e-mail byl zkontrolovan antivirem.\n"
		);

		$this->assertStringContainsString( 'Suhlasim.', $t_note );
		$this->assertStringContainsString( 'POVODNA SPRAVA', $t_note );
	}

	public function test_empty_body_stays_empty(): void
	{
		$this->assertSame( '', $this->parse( "\n\n \n" ) );
	}
}
