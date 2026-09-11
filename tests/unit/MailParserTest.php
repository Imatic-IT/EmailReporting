<?php
/**
 * Decoding of a raw email by ERP_Mail_Parser, and the note body that comes out
 * of it once quote handling has run.
 *
 * The fixtures in tests/fixtures are complete .eml files, so a change in MIME
 * decoding, charset handling or HTML conversion shows up here rather than on a
 * customers issue. Pure tests: no Mantis, no database, no network.
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

erp_test_require_plugin_api( 'core/mail_body_pure.php' );

use PHPUnit\Framework\TestCase;

class MailParserTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		// ERP_Mail_Parser pulls its dependencies through plugin_require_api(),
		// which tests/bootstrap.php stubs.
		erp_test_require_plugin_api( 'core/Mail/Parser.php' );
	}

	/**
	 * @param bool $p_parse_html      convert a text/html body to markdown
	 * @param bool $p_process_markdown blockquotes become "> " (needs a formatting plugin in production)
	 */
	private function parseFixture( $p_name, $p_parse_html = false, $p_process_markdown = false )
	{
		$t_file = ERP_FIXTURE_PATH . '/' . $p_name . '.eml';
		$this->assertFileExists( $t_file );

		$t_parser = new ERP_Mail_Parser( array(
			'parse_html'		=> $p_parse_html,
			'process_markdown'	=> $p_process_markdown,
			'add_attachments'	=> true,
			'debug'			=> false,
			'show_mem_usage'	=> false,
		) );

		$t_parser->setInputFile( $t_file );
		$t_parser->parse();

		return $t_parser;
	}

	// The body as it would be stored in the note: decoded, then quote handled.
	private function noteBody( $p_name, $p_parse_html = false, $p_process_markdown = false )
	{
		$t_parser = $this->parseFixture( $p_name, $p_parse_html, $p_process_markdown );

		return erp_parse_email_body( $t_parser->body(), true, true );
	}

	// ─── headers ─────────────────────────────────────────────────────────────

	public function test_from_and_subject_are_decoded(): void
	{
		$t_parser = $this->parseFixture( 'plain_trailing_quote' );

		$this->assertSame( 'Matej Brodziansky <matej.brodziansky@imatic.cz>', $t_parser->from() );
		$this->assertSame( '[Test Project 0001234]: odpoved z mailu', $t_parser->subject() );
	}

	public function test_encoded_word_subject_is_decoded(): void
	{
		$t_parser = $this->parseFixture( 'charset_iso88592' );

		$this->assertStringContainsString( 'príliš žluťoučký kůň', $t_parser->subject() );
	}

	// ─── transfer encodings ──────────────────────────────────────────────────

	public function test_quoted_printable_body_is_decoded(): void
	{
		$t_note = $this->noteBody( 'plain_trailing_quote' );

		$this->assertStringContainsString( 'Suhlasim, mozeme to nasadit v piatok.', $t_note );
		$this->assertStringNotContainsString( '=', $t_note );
	}

	public function test_eight_bit_utf8_body_keeps_diacritics(): void
	{
		$t_note = $this->noteBody( 'inline_quote' );

		$this->assertStringContainsString( 'V piatok popoludní.', $t_note );
		$this->assertStringContainsString( 'Ďakujem', $t_note );
	}

	// ─── charsets ────────────────────────────────────────────────────────────

	public function test_iso_8859_2_body_is_converted_to_utf8(): void
	{
		$t_note = $this->noteBody( 'charset_iso88592' );

		$this->assertStringContainsString( 'Kedy bude hotový deploy?', $t_note );
		$this->assertStringContainsString( 'V piatok popoludní.', $t_note );
	}

	public function test_windows_1250_body_is_converted_to_utf8(): void
	{
		// Regression guard for the iconv fallback: on PHP builds without native
		// windows-125x support mbstring used to turn these letters into
		// invisible control characters.
		$t_note = $this->noteBody( 'charset_windows1250' );

		$this->assertStringContainsString( 'Kedy bude hotový deploy?', $t_note );
		$this->assertStringContainsString( 'V piatok popoludní.', $t_note );
	}

	// ─── quote handling on real emails ───────────────────────────────────────

	public function test_trailing_quote_is_dropped_from_a_real_email(): void
	{
		$t_note = $this->noteBody( 'plain_trailing_quote' );

		$this->assertStringContainsString( 'nasadit v piatok', $t_note );
		$this->assertStringNotContainsString( 'POVODNA SPRAVA', $t_note );
	}

	public function test_inline_quotes_survive_a_real_email(): void
	{
		$t_note = $this->noteBody( 'inline_quote' );

		$this->assertStringContainsString( '> Kedy bude hotový deploy?', $t_note );
		$this->assertStringContainsString( 'V piatok popoludní.', $t_note );
		$this->assertStringContainsString( '> A čo migrácie DB?', $t_note );
		$this->assertStringContainsString( 'Tie už bežia na stagingu.', $t_note );
	}

	// ─── multipart ───────────────────────────────────────────────────────────

	public function test_multipart_alternative_prefers_the_plain_text_part(): void
	{
		$t_note = $this->noteBody( 'multipart_alternative' );

		$this->assertStringContainsString( '> Kedy bude deploy?', $t_note );
		$this->assertStringContainsString( 'V piatok.', $t_note );
		$this->assertStringNotContainsString( '<blockquote>', $t_note );
	}

	public function test_attachment_is_extracted_and_body_keeps_its_inline_quote(): void
	{
		$t_parser = $this->parseFixture( 'multipart_attachment' );
		$t_parts = $t_parser->parts();

		$this->assertCount( 1, $t_parts );
		$this->assertSame( 'polozky.csv', $t_parts[0]['name'] );
		$this->assertStringContainsString( 'prva polozka', $t_parts[0]['body'] );

		$t_note = erp_parse_email_body( $t_parser->body(), true, true );
		$this->assertStringContainsString( '> A co ta tabulka?', $t_note );
		$this->assertStringContainsString( 'Je v prilohe.', $t_note );
	}

	// ─── html ────────────────────────────────────────────────────────────────

	public function test_html_body_is_kept_as_html_when_conversion_is_off(): void
	{
		// How this install behaves today: mail_parse_html only converts to
		// markdown when a core formatting plugin reports process_markdown ON.
		$t_body = $this->parseFixture( 'html_blockquote', true, false )->body();

		$this->assertStringNotContainsString( '> Kedy', $t_body );
	}

	public function test_html_blockquote_becomes_a_markdown_quote_when_conversion_is_on(): void
	{
		$t_note = $this->noteBody( 'html_blockquote', true, true );

		// The inline quotes must survive the html to markdown conversion too,
		// otherwise the same bug comes back through html emails.
		$this->assertStringContainsString( '> Kedy bude hotový deploy?', $t_note );
		$this->assertStringContainsString( 'V piatok popoludní.', $t_note );
		$this->assertStringContainsString( '> A čo migrácie DB?', $t_note );
		$this->assertStringContainsString( 'Tie už bežia na stagingu.', $t_note );
	}
}
