<?php
/**
 * Standalone GitDeploy Script
 * This script is largly based on https://github.com/markomarkovic/simple-php-git-deploy/tree/1.3.1 version 1.3.1 but modified for my usecase
 *
 * @copyright  Copyright (C) 2024 Tobias Zulauf All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License Version 2 or Later
 */

/**
 * Deployment configuration
 *
 * Rename this file to `deploy-config.php` and edit the
 * configuration options here.
 *
 * @version 1.0.0
 */

/**
 * Protect the script from unauthorized access by using a secret access token.
 * If it's not present in the access URL as a GET variable named `token`
 * e.g. deploy.php?token=Bett...s the script is not going to deploy.
 *
 * @var string
 */
define('SECRET_ACCESS_TOKEN', '');

/**
 * The address of the remote Git repository that contains the code that's being
 * deployed.
 * If the repository is private, you'll need to use the SSH address.
 *
 * @var string
 */
define('REMOTE_REPOSITORY', 'https://github.com/[username]/[repo].git');

/**
 * The branch that's being deployed.
 * Must be present in the remote repository.
 *
 * @var string
 */
define('BRANCH', 'master');

/**
 * The location that the code is going to be deployed to.
 * Don't forget the trailing slash!
 *
 * @var string Full path including the trailing slash
 */
define('TARGET_DIR', '/tmp/');

/**
 * Whether to delete the files that are not in the repository but are on the
 * local (server) machine.
 *
 * !!! WARNING !!! This can lead to a serious loss of data if you're not
 * careful. All files that are not in the repository are going to be deleted,
 * except the ones defined in EXCLUDE section.
 * BE CAREFUL!
 *
 * @var boolean
 */
define('DELETE_FILES', false);

/**
 * The directories and files that are to be excluded when updating the code.
 * Normally, these are the directories containing files that are not part of
 * code base, for example user uploads or server-specific configuration files.
 * Use rsync exclude pattern syntax for each element.
 *
 * @var serialized array of strings
 */
define('EXCLUDE', serialize([
	'.git',
]));

/**
 * Temporary directory we'll use to stage the code before the update. If it
 * already exists, script assumes that it contains an already cloned copy of the
 * repository with the correct remote origin and only fetches changes instead of
 * cloning the entire thing.
 *
 * @var string Full path including the trailing slash
 */
define('TMP_DIR', '/tmp/gitdeploy-' . md5(REMOTE_REPOSITORY) . '/');

/**
 * Whether to remove the TMP_DIR after the deployment.
 * It's useful NOT to clean up in order to only fetch changes on the next
 * deployment.
 */
define('CLEAN_UP', true);

/**
 * Output the version of the deployed code.
 *
 * @var string Full path to the file name
 */
define('VERSION_FILE', TMP_DIR . 'VERSION');

/**
 * Time limit for each command.
 *
 * @var int Time in seconds
 */
define('TIME_LIMIT', 30);

/**
 * OPTIONAL
 * Backup the TARGET_DIR into BACKUP_DIR before deployment.
 *
 * @var string Full backup directory path e.g. `/tmp/`
 */
define('BACKUP_DIR', false);

/**
 * OPTIONAL
 * Whether to invoke composer after the repository is cloned or changes are
 * fetched. Composer needs to be available on the server machine, installed
 * globaly (as `composer`). See http://getcomposer.org/doc/00-intro.md#globally
 *
 * @var boolean Whether to use composer or not
 * @link http://getcomposer.org/
 */
define('USE_COMPOSER', false);

/**
 * OPTIONAL
 * The options that the composer is going to use.
 *
 * @var string Composer options
 * @link http://getcomposer.org/doc/03-cli.md#install
 */
define('COMPOSER_OPTIONS', '--no-dev');

/**
 * OPTIONAL
 * The COMPOSER_HOME environment variable is needed only if the script is
 * executed by a system user that has no HOME defined, e.g. `www-data`.
 *
 * @var string Path to the COMPOSER_HOME e.g. `/tmp/composer`
 * @link https://getcomposer.org/doc/03-cli.md#composer-home
 */
define('COMPOSER_HOME', false);

/**
 * OPTIONAL
 * Email address to be notified on deployment failure.
 *
 * @var string A single email address, or comma separated list of email addresses
 *      e.g. 'someone@example.com' or 'someone@example.com, someone-else@example.com, ...'
 */
define('EMAIL_RECEIVER', false);

/**
 * OPTIONAL
 * Email address that is placed as the sender of the error email
 *
 * @var string A single email address e.g. 'someone@example.com'
 */
define('EMAIL_SENDER', false);

/**
 * OPTIONAL
 * The Telegram Bot Token to send the message from
 *
 * @var string The bot token as requested by the https://telegram.me/BotFather / https://gist.github.com/nafiesl/4ad622f344cd1dc3bb1ecbe468ff9f8a
 */
define('TELEGRAM_BOT_TOKEN', false);

/**
 * OPTIONAL
 * The Telegram Bot CHATID to send the message to
 *
 * @var string The chatID from the chat with the telegram bot: https://gist.github.com/nafiesl/4ad622f344cd1dc3bb1ecbe468ff9f8a
 */
define('TELEGRAM_BOT_CHATID', false);
