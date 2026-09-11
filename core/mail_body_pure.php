<?php
# --------------------------------------------------------------------------
# Body parsing of an incoming email: quote and signature handling.
#
# These functions are pure - no Mantis bootstrap, no database, no plugin
# configuration. ERP_mailbox_api::parse_email_body() is a thin wrapper around
# them, so the unit tests in tests/ exercise the same code that production
# runs instead of a copy of it.
# --------------------------------------------------------------------------

require_once __DIR__ . '/EmailReplyParser/Parser/FragmentDTO.php';
require_once __DIR__ . '/EmailReplyParser/Parser/EmailParser.php';
require_once __DIR__ . '/EmailReplyParser/Fragment.php';
require_once __DIR__ . '/EmailReplyParser/Email.php';

if ( !function_exists( 'erp_select_fragments' ) )
{
	# --------------------
	# Select the fragments of interest to us
	#
	# Quoted fragments are only dropped when they belong to the trailing reply
	# block. Quotes in between the authors own text (inline replies) are kept,
	# otherwise the note loses its context
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

	# --------------------
	# Process the body of an email to separate signatures and replies
	function erp_parse_email_body( $p_description, $p_remove_replies, $p_strip_signature )
	{
		$t_description = $p_description;

		if ( !$p_remove_replies && !$p_strip_signature )
		{
			return( $t_description );
		}

		// Lines starting with -- are seen as signatures. EmailReplyParser doesn't use "-----Original Message-----" anyway
		$t_description = preg_replace( '/(?:\\\\{1}---){1,2}-{0,2}\h?[ \S]+\h?(?:\\\\{1}---){1,2}-{0,2}/', '', $t_description );

		$t_parser = new EmailReplyParser\Parser\EmailParser;
		$t_fragments = $t_parser->parse( $t_description )->getFragments();

		$t_selected = erp_select_fragments( $t_fragments, $p_remove_replies, $p_strip_signature );

		// Fragments are joined with a blank line: without it markdown treats the
		// line following a "> " quote as part of that quote (lazy continuation)
		// and the authors own reply gets rendered inside the quote block
		return( rtrim( (string)implode( "\n\n", $t_selected ) ) );
	}

	# --------------------
	# Fragment selection as it behaved before the inline-quote fix: every quoted
	# fragment was dropped regardless of where it sat in the message.
	#
	# Kept so the regression tests can assert that the old behaviour really did
	# lose inline quotes, and so the difference stays visible in the test output.
	function erp_select_fragments_legacy( array $p_fragments, $p_remove_replies, $p_strip_signature )
	{
		return( array_filter( $p_fragments, function ( $p_fragment ) use ( $p_remove_replies, $p_strip_signature ) {
			return !$p_fragment->isEmpty()
				&& !( $p_remove_replies && $p_fragment->isQuoted() )
				&& !( $p_strip_signature && $p_fragment->isSignature() );
		} ) );
	}

	function erp_parse_email_body_legacy( $p_description, $p_remove_replies, $p_strip_signature )
	{
		$t_description = preg_replace( '/(?:\\\\{1}---){1,2}-{0,2}\h?[ \S]+\h?(?:\\\\{1}---){1,2}-{0,2}/', '', $p_description );

		$t_parser = new EmailReplyParser\Parser\EmailParser;
		$t_fragments = $t_parser->parse( $t_description )->getFragments();

		$t_selected = erp_select_fragments_legacy( $t_fragments, $p_remove_replies, $p_strip_signature );

		return( rtrim( (string)implode( "\n\n", $t_selected ) ) );
	}
}
