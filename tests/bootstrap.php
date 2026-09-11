<?php
/**
 * Bootstrap for the EmailReporting test suites.
 *
 * Three ways of starting the suites have to work:
 *
 *   1. plugins/EmailReporting/phpunit.xml on a machine with no Mantis install.
 *      Only the unit suite can run; the Mantis helpers the plugin calls at
 *      include time are stubbed, and the suites that need a database skip
 *      themselves.
 *
 *   2. the same file with ERP_TESTS_MANTIS=1 in the environment, which boots
 *      Mantis from here so the integration and mailserver suites can run.
 *      This is how the suites are started inside the container and in CI.
 *
 *   3. the repository root phpunit.xml, whose own bootstrap has already booted
 *      Mantis. Nothing is stubbed and core is not loaded twice.
 *
 * Every test file requires this bootstrap explicitly, so it does not matter
 * which of the three was used.
 */

if ( !defined( 'ERP_PLUGIN_PATH' ) )
{
	define( 'ERP_PLUGIN_PATH', dirname( __DIR__ ) );
	define( 'ERP_FIXTURE_PATH', __DIR__ . '/fixtures' );

	date_default_timezone_set( 'UTC' );

	# config_get() is the marker for "Mantis core is already in this process",
	# which is the case when the repository root phpunit.xml started the run.
	$g_erp_tests_have_mantis = function_exists( 'config_get' );

	if ( !$g_erp_tests_have_mantis && getenv( 'ERP_TESTS_MANTIS' ) )
	{
		# require_mantis_core() resolves config_defaults_inc.php and core.php
		# relative to the working directory, so phpunit has to be started from
		# the Mantis root - which is where its configuration files live anyway.
		require_once dirname( ERP_PLUGIN_PATH, 2 ) . '/tests/TestConfig.php';
		require_mantis_core();

		$g_erp_tests_have_mantis = TRUE;
	}

	define( 'ERP_TESTS_HAVE_MANTIS', $g_erp_tests_have_mantis );
	unset( $g_erp_tests_have_mantis );

	if ( !ERP_TESTS_HAVE_MANTIS )
	{
		require_once __DIR__ . '/stubs.php';
	}
}

if ( !function_exists( 'erp_test_require_plugin_api' ) )
{
	/**
	 * Include a file from the EmailReporting plugin.
	 *
	 * Those files include their own dependencies through plugin_require_api()
	 * without naming the plugin, so under a real Mantis the plugin has to be
	 * the current one while they load.
	 *
	 * @param string $p_file path relative to the plugin directory
	 */
	function erp_test_require_plugin_api( $p_file )
	{
		if ( function_exists( 'plugin_push_current' ) )
		{
			plugin_push_current( 'EmailReporting' );
			require_once ERP_PLUGIN_PATH . '/' . $p_file;
			plugin_pop_current();

			return;
		}

		require_once ERP_PLUGIN_PATH . '/' . $p_file;
	}
}
