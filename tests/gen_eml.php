<?php
/**
 * Generates .eml reply emails for an existing Mantis issue.
 *
 * The subject is built as "[<label> 0001443]: ..." so EmailReporting attaches
 * the mail as a note to that issue (mail_subject_id_regex = strict).
 *
 * Usage:
 *   php tests/gen_eml.php --issue=1443 --out=/tmp/eml [--label=Mantis] \
 *       [--from=user@example.com] [--to=mantis@example.com]
 *
 * The sender must be a non-disposable address, otherwise EmailReporting
 * rejects the mail ("From email address rejected by email_is_valid").
 */

$t_opts = getopt( '', array( 'issue:', 'out:', 'label:', 'from:', 'to:' ) );

if ( empty( $t_opts['issue'] ) || empty( $t_opts['out'] ) )
{
	die( "Usage: php gen_eml.php --issue=<id> --out=<dir> [--label=Mantis] [--from=] [--to=]\n" );
}

$t_issue = (int) $t_opts['issue'];
$t_out   = rtrim( $t_opts['out'], '/' );
$t_label = $t_opts['label'] ?? 'Mantis';
$t_from  = $t_opts['from']  ?? 'tester@imatic.cz';
$t_to    = $t_opts['to']    ?? 'mantis@mantis.example.com';

@mkdir( $t_out, 0777, true );

$t_subject = sprintf( '[%s %07d]: odpoved z mailu', $t_label, $t_issue );
$t_date    = date( 'r' );

# Each case: content type, body, and what the resulting note must / must not contain
$t_cases = array(
	'q1_trailing_quote' => array(
		'text/plain; charset=UTF-8',
		"Suhlasim, mozeme to nasadit v piatok.\n\n"
			. "On Mon, 1 Sep 2026 at 09:25, Jan Pekar <jan@example.com> wrote:\n"
			. "> POVODNA SPRAVA RIADOK 1\n> POVODNA SPRAVA RIADOK 2\n",
	),
	'q2_inline_quote' => array(
		'text/plain; charset=UTF-8',
		"Reagujem priamo v texte:\n\n"
			. "> Kedy bude hotovy deploy?\nV piatok popoludni.\n\n"
			. "> A co migracie DB?\nTie uz bezia na stagingu.\n\nDiky\n",
	),
	'q3_inline_plus_trailing' => array(
		'text/plain; charset=UTF-8',
		"Reagujem v texte:\n\n> Kedy bude deploy?\nV piatok.\n\n> A co DB?\nTie bezia.\n\n"
			. "On Mon, 1 Sep 2026 at 09:25, Jan Pekar <jan@example.com> wrote:\n"
			. "> CELA POVODNA SPRAVA\n> POKRACOVANIE\n",
	),
	'q4_html_blockquote' => array(
		'text/html; charset=UTF-8',
		"<html><body><p>Reagujem v texte:</p>\n"
			. "<blockquote>Kedy bude hotovy deploy?</blockquote>\n"
			. "<p>V piatok popoludni.</p>\n"
			. "<blockquote>A co migracie DB?</blockquote>\n"
			. "<p>Tie uz bezia.</p>\n</body></html>\n",
	),
);

foreach ( $t_cases as $t_name => $t_case )
{
	list( $t_ctype, $t_body ) = $t_case;

	$t_headers = "Return-Path: <$t_from>\n"
		. "Delivered-To: $t_to\n"
		. "Date: $t_date\n"
		. "From: Tester <$t_from>\n"
		. "To: Mantis <$t_to>\n"
		. "Subject: $t_subject\n"
		. "Message-ID: <$t_name-$t_issue-" . time() . "@example.com>\n"
		. "MIME-Version: 1.0\n"
		. "Content-Type: $t_ctype\n"
		. "Content-Transfer-Encoding: 8bit\n\n";

	file_put_contents( "$t_out/$t_name.eml", $t_headers . $t_body );

	echo "$t_out/$t_name.eml\n";
}
