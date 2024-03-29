#!/usr/bin/env php
<?php

function stringtobytes($amount) {
	// convert "256M" etc to bytes
	switch($suffix = strtolower(substr($amount, -1))) {
		case 'g':
			$amount = (int)$amount * 1024;
		case 'm':
			$amount = (int)$amount * 1024;
		case 'k':
			$amount = (int)$amount * 1024;
			break;
		case !is_numeric($suffix):
			fprintf(STDERR, "WARNING: ignoring invalid suffix '%s' in 'memory_limit' value '%s'\n", $suffix, $amount);
		default:
			$amount = (int)$amount;
	}
	return $amount;
}

function bytestostring($amount) {
	$suffixes = array('K', 'M', 'G', 'T', 'P', 'E');
	$suffix = '';
	while($suffixes && $amount % 1024 == 0) {
		$amount /= 1024;
		$suffix = array_shift($suffixes);
	}
	return sprintf("%d%s", $amount, $suffix);
}

$opts = getopt("b:t:v", array(), $rest_index);
$argv = array_slice($argv, $rest_index);
$argc = count($argv);
if($argc != 2) {
	fprintf(STDERR,
		"Usage:\n".
		"  %s [options] <RAM_AVAIL> <NUM_CORES>\n\n",
		basename(__FILE__)
	);
	fputs(STDERR,
		"Determines the number of PHP-FPM worker processes for given RAM and CPU cores\n\n".
		"The initial calculation works as follows:\n".
		'ceil(log_2(RAM_AVAIL / CALC_BASE)) * NUM_CORES * 2 * (CALC_BASE / memory_limit)'."\n\n".
		'This result is then capped to at most (RAM_AVAIL / memory_limit) processes'."\n\n".
		"The purpose of this formula is to ensure that:\n".
		"1) the number of processes does not grow too rapidly as RAM increases;\n".
		"2) the number of CPU cores is taken into account;\n".
		"3) adjusting PHP memory_limit has a linear influence on the number of processes\n".
		'4) the number of processes never exceeds available RAM for given memory_limit'."\n"
	);
	fputs(STDERR,
		"Options:\n".
		"  -b <CALC_BASE>     The PHP memory_limit on which the calculation of the\n".
		"                     scaling factors should be based. Defaults to '128M'\n".
		"  -t <DOCUMENT_ROOT> Dir to read '.user.ini' with memory_limit settings from\n".
		"  -v                 Verbose mode\n\n".
		"php_value or php_admin_value lines containing memory_limit INI directives from\n".
		"a PHP-FPM configuration file or dump (php-fpm -tt) can be fed via STDIN.\n\n"
	);
	exit(2);
}

$ram = stringtobytes($argv[0]); // first arg is the available memory
fprintf(STDERR, "Available RAM is %s Bytes\n", bytestostring($ram));

$cores = $argv[1];
fprintf(STDERR, "Number of CPU cores is %d\n", (int)$cores);

$calc_base = $opts['b'] ?? "128M";
if(isset($opts['v'])) {
	fprintf(STDERR, "Determining scaling factor based on a memory_limit of %s\n", $calc_base);
}
$calc_base = stringtobytes($calc_base);
$factor = ceil(log($ram/$calc_base, 2));
if(isset($opts['v'])) {
	fprintf(STDERR, "Scaling factor is %d\n", $factor);
}

// parse potential php_value and php_admin_value data from STDIN
// the expected format is lines like the following:
// php_value[memory_limit] = 128M
// php_admin_value[memory_limit] = 128M
$limits = (stream_isatty(STDIN) ? [] : parse_ini_string(stream_get_contents(STDIN)));
if($limits === false) {
	fputs(STDERR, "ERROR: Malformed FPM php_value/php_admin_value directives on STDIN.\n");
	exit(1);
}

if(isset($opts['v'])) {
	if(isset($limits['php_value'])) {
		fputs(STDERR, "memory_limit changed by php_value in PHP-FPM configuration\n");
	}
}

if(
	isset($opts['t']) &&
	is_readable($userini_path = $opts['t'].'/.user.ini')
) {
	// we only read the topmost .user.ini inside document root
	$userini = parse_ini_file($userini_path);
	if($userini === false) {
		fprintf(STDERR, "ERROR: Malformed %s.\n", $userini_path);
		exit(1);
	}
	if(isset($userini['memory_limit'])) {
		if(isset($opts['v'])) {
			fprintf(STDERR, "memory_limit changed by %s\n", $userini_path);
		}
		// if .user.ini has a limit set, it will overwrite an FPM config php_value, but not a php_admin_value
		$limits['php_value']['memory_limit'] = $userini['memory_limit'];
	}
}

if(isset($limits['php_admin_value']['memory_limit'])) {
	// these take precedence and cannot be overridden later
	if(isset($opts['v'])) {
		fputs(STDERR, "memory_limit overridden by php_admin_value in PHP-FPM configuration\n");
	}
	ini_set('memory_limit', $limits['php_admin_value']['memory_limit']);
} elseif(isset($limits['php_value']['memory_limit'])) {
	ini_set('memory_limit', $limits['php_value']['memory_limit']);
}

$limit = ini_get('memory_limit');
fprintf(STDERR, "PHP memory_limit is %s Bytes\n", $limit); // we output the original value here, since it's user supplied
$limit = stringtobytes($limit);

$result = $factor * $cores * 2 * ($calc_base / $limit);

if(isset($opts['v'])) {
	fprintf(STDERR, "Calculated number of workers is %d\n", $result);
}

$max_workers = floor($ram/$limit);
if($max_workers < $result) {
	$result = $max_workers;
	if(isset($opts['v'])) {
		fprintf(STDERR, "Limiting number of workers to %d\n", $result);
	}
}

echo $result;
