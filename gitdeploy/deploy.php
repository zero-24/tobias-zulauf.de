<?php
/**
 * Standalone GitDeploy Script
 * This script is largly based on https://github.com/markomarkovic/simple-php-git-deploy/tree/1.3.1 version 1.3.1 but modified for my usecase
 *
 * @copyright  Copyright (C) 2024 Tobias Zulauf All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License Version 2 or Later
 */

/**
 * You have to configure the script using `deploy-config.php` file.
 *
 * Rename `deploy-config.example.php` to `deploy-config.php` and edit the
 * configuration options there.
 */
$configFile = basename(__FILE__, '.php') . '-config.php';

// Check whether the config file exists and load it.
if (file_exists($configFile))
{
	define('CONFIG_FILE', $configFile);

	require_once CONFIG_FILE;
}

// When the config file has not been found -> Exit
if (!defined('CONFIG_FILE'))
{
	echo 'Configuration file not found! Please create the "' . $configFile . '" file based on the deploy-config.example.php';
}

if (!isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']))
{
	//echo "HTTP header 'X-Hub-Signature' is missing";
	header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden', true, 403);
	header('Content-type: application/json');
	echo json_encode(['status' => false]);
}

if (!extension_loaded('hash'))
{
	//echo "Missing 'hash' extension to check the secret code validity.";
	header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden', true, 403);
	header('Content-type: application/json');
	echo json_encode(['status' => false]);
}

// Check the hash algo used
list($algo, $hash) = explode('=', $_SERVER['HTTP_X_HUB_SIGNATURE_256']) + array('', '');

if (!in_array($algo, hash_algos(), true))
{
	//echo "Hash algorithm '$algo' is not supported.";
	header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden', true, 403);
	header('Content-type: application/json');
	echo json_encode(['status' => false]);
}

$rawPost = file_get_contents('php://input');

if ($hash !== hash_hmac($algo, $rawPost, SECRET_ACCESS_TOKEN))
{
	//echo 'Hook secret does not match';
	header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden', true, 403);
	header('Content-type: application/json');
	echo json_encode(['status' => false]);
}

// Check if the required programs are available
$requiredBinaries = array('git', 'rsync');

if (defined('BACKUP_DIR') && BACKUP_DIR !== false)
{
	$requiredBinaries[] = 'tar';

	if (!is_dir(BACKUP_DIR) || !is_writable(BACKUP_DIR))
	{
		//echo sprintf('BACKUP_DIR: `%s` does not exists or is not writeable.', BACKUP_DIR);
		header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
		header('Content-type: application/json');
		echo json_encode(['status' => false]);
	}
}

if (defined('USE_COMPOSER') && USE_COMPOSER === true)
{
	$requiredBinaries[] = 'composer --no-ansi';
}

foreach ($requiredBinaries as $command)
{
	$path = trim(shell_exec('which '. $command));

	if ($path === '')
	{
		//echo sprintf('<b>%s</b> not available. It needs to be installed on the server for this script to work.', $command);
		header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
		header('Content-type: application/json');
		echo json_encode(['status' => false]);
	}
}

// The commands
$commands = [];

// ========================================[ Pre-Deployment steps ]===

if (!is_dir(TMP_DIR))
{
	// Clone the repository into the TMP_DIR
	$commands[] = sprintf(
		'git clone --depth=1 --branch %s %s %s',
		BRANCH,
		REMOTE_REPOSITORY,
		TMP_DIR
	);
}
else
{
	// TMP_DIR exists and hopefully already contains the correct remote origin
	// so we'll fetch the changes and reset the contents.
	$commands[] = sprintf(
		'git --git-dir="%s.git" --work-tree="%s" fetch --tags origin %s',
		TMP_DIR,
		TMP_DIR,
		BRANCH
	);
	$commands[] = sprintf(
		'git --git-dir="%s.git" --work-tree="%s" reset --hard FETCH_HEAD',
		TMP_DIR,
		TMP_DIR
	);
}

// Describe the deployed version
if (defined('VERSION_FILE') && VERSION_FILE !== '')
{
	$commands[] = sprintf(
		'git --git-dir="%s.git" --work-tree="%s" describe --always > %s',
		TMP_DIR,
		TMP_DIR,
		VERSION_FILE
	);
}

// Backup the TARGET_DIR
// without the BACKUP_DIR for the case when it's inside the TARGET_DIR
if (defined('BACKUP_DIR') && BACKUP_DIR !== false)
{
	$commands[] = sprintf(
		"tar --exclude='%s*' -czf %s/%s-%s-%s.tar.gz %s*",
		BACKUP_DIR,
		BACKUP_DIR,
		basename(TARGET_DIR),
		md5(TARGET_DIR),
		date('YmdHis'),
		TARGET_DIR // We're backing up this directory into BACKUP_DIR
	);
}

// Invoke composer
if (defined('USE_COMPOSER') && USE_COMPOSER === true)
{
	$commands[] = sprintf(
		'composer --no-ansi --no-interaction --no-progress --working-dir=%s install %s',
		TMP_DIR,
		(defined('COMPOSER_OPTIONS')) ? COMPOSER_OPTIONS : ''
	);

	if (defined('COMPOSER_HOME') && is_dir(COMPOSER_HOME))
	{
		putenv('COMPOSER_HOME=' . COMPOSER_HOME);
	}
}

// ==================================================[ Deployment ]===

// Compile exclude parameters
$exclude = '';

foreach (unserialize(EXCLUDE) as $exc)
{
	$exclude .= ' --exclude=' . $exc;
}

// Deployment command
$commands[] = sprintf(
	'rsync -rltgoDzvO %s %s %s %s',
	TMP_DIR,
	TARGET_DIR,
	(DELETE_FILES) ? '--delete-after' : '',
	$exclude
);

// =======================================[ Post-Deployment steps ]===

// Remove the TMP_DIR (depends on CLEAN_UP)
if (CLEAN_UP)
{
	$commands['cleanup'] = sprintf(
		'rm -rf %s',
		TMP_DIR
	);
}

// =======================================[ Run the command steps ]===

// By default here is no error
$error = false;

// Set the message when there is no error message
$betreff = sprintf(
	'Git Deployment successful on %s',
	$_SERVER['HTTP_HOST'],
);
$nachricht = '';

// Loop via the commands
foreach ($commands as $command)
{
	// Reset the time limit for each command
	set_time_limit(TIME_LIMIT);

	if (file_exists(TMP_DIR) && is_dir(TMP_DIR))
	{
		// Ensure that we're in the right directory
		chdir(TMP_DIR);
	}

	$commandResult = [];

	// Execute the command
	exec($command . ' 2>&1', $commandResult, $returnCode);

	// Error handling and cleanup
	if ($returnCode !== 0)
	{
		// There is an error
		$error = true;

		if (CLEAN_UP)
		{
			shell_exec($commands['cleanup']);
		}

		$betreff = sprintf(
			'Git Deployment error on %s',
			$_SERVER['HTTP_HOST'],
		);

		$nachricht = 'Last error message:/n/n';

		foreach ($commandResult as $key => $value)
		{
			$nachricht .= $value . '/n/n';
		}

		break;
	}
}

if (EMAIL_RECEIVER && $error)
{
	$empfaenger = EMAIL_RECEIVER;

	$header = [
		'From' => EMAIL_SENDER,
		'X-Mailer' => 'PHP/' . phpversion()
	];

	mail($empfaenger, $betreff, $nachricht, $header);
}

if (TELEGRAM_BOT_TOKEN && TELEGRAM_BOT_CHATID)
{
	$nachricht = !empty($nachricht) ? PHP_EOL . PHP_EOL . $nachricht : '';

	$params = [
		'chat_id' => TELEGRAM_BOT_CHATID,
		'text' => $betreff . $nachricht,
		'disable_web_page_preview' => 'true',
		'parse_mode' => 'markdown',
	];

	$ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
	curl_setopt($ch, CURLOPT_HEADER, false);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, ($params));
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

	$result = curl_exec($ch);
	curl_close($ch);
}

// When there is an error set the status to false
if ($error)
{
	header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
	header('Content-type: application/json');
	echo json_encode(['status' => false]);
}

// Send status
header('Content-type: application/json');
echo json_encode(['status' => true]);
