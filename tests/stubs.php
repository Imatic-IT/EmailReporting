<?php
/**
 * The little that the EmailReporting code needs from Mantis at include time.
 *
 * Loaded by tests/bootstrap.php only when Mantis is not available, so the unit
 * suite can run on a checkout with no install and no database behind it. Never
 * loaded alongside the real core - see the bootstrap for how that is decided.
 */

function plugin_require_api( $p_file )
{
	require_once ERP_PLUGIN_PATH . '/' . $p_file;
}

function is_blank( $p_var )
{
	return( strlen( trim( (string)$p_var ) ) === 0 );
}

define( 'ON', true );
define( 'OFF', false );
