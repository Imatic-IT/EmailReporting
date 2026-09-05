<?php
/**
 * Unit test for the quote/signature fragment selection used by
 * ERP_mailbox_api::parse_email_body().
 *
 * Runs standalone: no Mantis bootstrap, no database, nothing is created.
 * It mirrors the selection logic of mail_api.php so the behaviour can be
 * compared against the previous implementation.
 *
 * Usage:
 *   php tests/test_fragments.php
 *   VERBOSE=1 php tests/test_fragments.php   # print old vs new output
 */

$t_parser_dir = __DIR__ . '/../core/EmailReplyParser';

require_once $t_parser_dir . '/Parser/FragmentDTO.php';
require_once $t_parser_dir . '/Parser/EmailParser.php';
require_once $t_parser_dir . '/Fragment.php';
require_once $t_parser_dir . '/Email.php';

/**
 * Current behaviour: quoted fragments are only dropped when they are part of
 * the trailing reply block. Keep this in sync with mail_api.php.
 */
function erp_select_fragments( array $p_fragments, $p_remove_replies, $p_strip_signature )
{
	$t_last_own_content = -1;

	foreach ( $p_fragments as $t_index => $t_fragment )
	{
		if ( !$t_fragment->isEmpty() && !$t_fragment->isQuoted() && !$t_fragment->isSignature() )
		{
			$t_last_own_content = $t_index;
		}
	}

	$t_selected = array();

	foreach ( $p_fragments as $t_index => $t_fragment )
	{
		if ( $t_fragment->isEmpty() )
		{
			continue;
		}

		if ( $p_strip_signature && $t_fragment->isSignature() )
		{
			continue;
		}

		if ( $p_remove_replies && $t_fragment->isQuoted() && $t_index > $t_last_own_content )
		{
			continue;
		}

		$t_selected[] = $t_fragment;
	}

	return( $t_selected );
}

/** Previous behaviour, kept for the VERBOSE comparison only. */
function erp_select_fragments_old( array $p_fragments, $p_remove_replies, $p_strip_signature )
{
	return array_filter( $p_fragments, function ( $f ) use ( $p_remove_replies, $p_strip_signature ) {
		return !$f->isEmpty()
			&& !( $p_remove_replies && $f->isQuoted() )
			&& !( $p_strip_signature && $f->isSignature() );
	} );
}

function erp_parse_body( $p_body, $p_remove_replies = true, $p_strip_signature = true, $p_old = false )
{
	$t_body = preg_replace( '/(?:\\\\{1}---){1,2}-{0,2}\h?[ \S]+\h?(?:\\\\{1}---){1,2}-{0,2}/', '', $p_body );

	$t_parser = new EmailReplyParser\Parser\EmailParser;
	$t_fragments = $t_parser->parse( $t_body )->getFragments();

	$t_selected = $p_old
		? erp_select_fragments_old( $t_fragments, $p_remove_replies, $p_strip_signature )
		: erp_select_fragments( $t_fragments, $p_remove_replies, $p_strip_signature );

	return rtrim( (string) implode( "\n\n", $t_selected ) );
}

$t_cases = array(
	'1. plain reply, trailing quote' => array(
		'body' => "Suhlasim, mozeme to nasadit v piatok.\n\n"
			. "On Mon, 1 Sep 2026 at 09:25, Jan Pekar <jan@example.com> wrote:\n"
			. "> POVODNA SPRAVA RIADOK 1\n> POVODNA SPRAVA RIADOK 2\n",
		'contains' => array( 'nasadit v piatok' ),
		'missing'  => array( 'POVODNA SPRAVA' ),
	),
	'2. inline reply (the reported bug)' => array(
		'body' => "Reagujem priamo v texte:\n\n"
			. "> Kedy bude hotovy deploy?\nV piatok popoludni.\n\n"
			. "> A co migracie DB?\nTie uz bezia na stagingu.\n\nDiky\n",
		'contains' => array( 'Kedy bude hotovy deploy?', 'V piatok popoludni.', 'A co migracie DB?', 'Tie uz bezia', 'Diky' ),
		'missing'  => array(),
	),
	'3. inline reply + trailing quote' => array(
		'body' => "Reagujem v texte:\n\n> Kedy bude deploy?\nV piatok.\n\n> A co DB?\nTie bezia.\n\n"
			. "On Mon, 1 Sep 2026 at 09:25, Jan Pekar <jan@example.com> wrote:\n"
			. "> CELA POVODNA SPRAVA\n> POKRACOVANIE\n",
		'contains' => array( 'Kedy bude deploy?', 'A co DB?' ),
		'missing'  => array( 'CELA POVODNA SPRAVA', 'POKRACOVANIE' ),
	),
	'4. inline reply + signature' => array(
		'body' => "> Otazka?\nOdpoved.\n\n--\nMatej Brodziansky\nImatic\n",
		'contains' => array( 'Otazka?', 'Odpoved.' ),
		'missing'  => array( 'Imatic' ),
	),
	'5. forward without any own text' => array(
		'body' => "On Mon, 1 Sep 2026, Jan Pekar <jan@example.com> wrote:\n> IBA PREPOSLANA SPRAVA\n",
		'contains' => array(),
		'missing'  => array( 'IBA PREPOSLANA SPRAVA' ),
	),
	'6. corporate disclaimer after the quote (known limitation)' => array(
		'body' => "Suhlasim.\n\nOn Mon, 1 Sep 2026, Jan <j@x.cz> wrote:\n> POVODNA SPRAVA\n\n"
			. "Tento e-mail byl zkontrolovan antivirem.\n",
		// The disclaimer counts as own content, so the quote above it is kept.
		'contains' => array( 'Suhlasim.', 'POVODNA SPRAVA' ),
		'missing'  => array(),
	),
);

$t_failed = 0;

foreach ( $t_cases as $t_name => $t_case )
{
	$t_new = erp_parse_body( $t_case['body'] );
	$t_ok = true;

	foreach ( $t_case['contains'] as $t_needle )
	{
		if ( strpos( $t_new, $t_needle ) === false )
		{
			$t_ok = false;
			echo "  missing expected text: '$t_needle'\n";
		}
	}

	foreach ( $t_case['missing'] as $t_needle )
	{
		if ( strpos( $t_new, $t_needle ) !== false )
		{
			$t_ok = false;
			echo "  unexpected text present: '$t_needle'\n";
		}
	}

	printf( "[%s] %s\n", $t_ok ? 'PASS' : 'FAIL', $t_name );

	if ( !$t_ok )
	{
		$t_failed++;
	}

	if ( getenv( 'VERBOSE' ) )
	{
		echo "--- before the fix ---\n" . erp_parse_body( $t_case['body'], true, true, true )
			. "\n--- after the fix ---\n" . $t_new . "\n\n";
	}
}

echo $t_failed ? "\n$t_failed test(s) failed\n" : "\nAll tests passed\n";

exit( $t_failed ? 1 : 0 );
