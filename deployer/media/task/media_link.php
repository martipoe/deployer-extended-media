<?php

namespace Deployer;

use SourceBroker\DeployerExtendedMedia\Utility\ConsoleUtility;
use SourceBroker\DeployerExtendedMedia\Utility\FileUtility;
use SourceBroker\DeployerInstance\Configuration;
use Deployer\Exception\GracefulShutdownException;

/*
 * @see https://github.com/sourcebroker/deployer-extended-media#media-link
 */
task('media:link', function () {
    $sourceName = get('argument_host');
    $targetName = (new ConsoleUtility)->getOption('target');
    if (null === $targetName) {
        throw new GracefulShutdownException(
            "You must set the target instance in option '--options=target:[target]', the media will be copied to, as second parameter. [Error code: 1488149866477]"
        );
    }
    $doNotAskAgainForLive = false;
    if ($targetName === get('instance_live_name', 'live')) {
        if (!get('media_allow_link_live', true)) {
            throw new GracefulShutdownException(
                'FORBIDDEN: For security its forbidden to link media to top instance: "' . $targetName . '"!'
            );
        }
        if (!get('media_allow_link_live_force', false)) {
            $doNotAskAgainForLive = true;
            writeln("<error>\n\n");
            writeln(sprintf(
                "You going to link media from instance: \"%s\" to top instance: \"%s\". ",
                $sourceName,
                $targetName
            ));
            writeln("This can be destructive.\n\n");
            writeln("</error>");
            if (!askConfirmation('Do you really want to continue?', false)) {
                throw new GracefulShutdownException('Process aborted.');
            }
            if (!askConfirmation('Are you sure?', false)) {
                throw new GracefulShutdownException('Process aborted.');
            }
        }
    }

    if ($targetName === get('instance_local_name', 'local')) {
        throw new GracefulShutdownException(
            "FORBIDDEN: For synchro local media use: \ndep media:pull " . $sourceName
        );
    }

    if (!$doNotAskAgainForLive && !askConfirmation(sprintf(
            "Do you really want to link media from instance %s to instance %s",
            $sourceName,
            $targetName
        ), true)) {
        throw new GracefulShutdownException('Process aborted.');
    }

    $targetServer = Configuration::getHost($targetName);
    $sourceServer = Configuration::getHost($sourceName);

    $fileUtility = new FileUtility();
    $targetDir = $fileUtility->resolveHomeDirectory($targetServer->get('deploy_path')) . '/' .
        (test('[ -e ' . $targetServer->get('deploy_path') . '/release ]') ? 'release' : 'current');
    $sourceDir = $fileUtility->resolveHomeDirectory($sourceServer->get('deploy_path')) . '/' .
        (test('[ -e ' . $sourceServer->get('deploy_path') . '/release ]') ? 'release' : 'current');

    if ($targetServer->getHostname() !== $sourceServer->getHostname()
        || $targetServer->getPort() !== $sourceServer->getPort()) {
        throw new GracefulShutdownException(
            "FORBIDDEN: Creating links only allowed on same machine. [Error code: 1488234862247]"
        );
    }

    // linking on the same remote server
    // 1. cd to source server document root
    // 2. find all files fulfilling filter conditions (-L param makes find to search in linked directories - for example shared/)
    //    for each found file:
    //     2.1. check if file already exists on target instance - if it exists omit this file
    //     2.2. get directory name (on source instance) of file and create directories recursively (on destination instance)
    //     2.3. create link (with `ln -s`) in target instance targeting source file
    $script = <<<BASH
rsync {{media_rsync_flags}} --info=all0,name1 --update --dry-run {{media_rsync_options}}{{media_rsync_includes}}{{media_rsync_excludes}}{{media_rsync_filter}} '$sourceDir/' '$targetDir/' |
while read path; do
    if [ -d "{$sourceDir}/\$path" ]
    then
        echo "Creating directory \$path"
        mkdir -p "{$targetDir}/\$path"
    else
        if [ -e "{$targetDir}/\$path" ] && [ ! -d "{$targetDir}/\$path" ]
        then
            echo "Delete current file \$path"
            rm "{$targetDir}/\$path"
        fi

        if [ ! -e "{$targetDir}/\$path" ]
        then
            echo "Linking file \$path"
            ln -s "{$sourceDir}/\$path" "{$targetDir}/\$path"
        fi
    fi
done
BASH;

    run($script);
})->desc('Synchronize files between instances using symlinks for each file (working only when instances on the same host)');
