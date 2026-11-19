<?php
/**
 * Plugin Name: Steel Art Museum – jádro
 * Description: Načítá vlastní moduly webu Steel Art Museum (sam-core/*.php).
 * Version: 0.1.0
 * Author: Steel Art Museum
 */

defined( 'ABSPATH' ) || exit;

define( 'SAM_CORE_VERSION', '0.1.0' );
define( 'SAM_CORE_DIR', __DIR__ . '/sam-core/' );

// Moduly se načítají v abecedním pořadí; každý soubor je samostatný a bez vedlejších účinků při načtení.
foreach ( glob( SAM_CORE_DIR . '*.php' ) ?: array() as $sam_core_module ) {
	require_once $sam_core_module;
}
unset( $sam_core_module );
