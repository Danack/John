<?php

use Auryn\Injector;
use Tier\CLIFunction;
use Tier\Executable;
use Tier\TierCLIApp;
use GithubService\AuthToken;
use John\DownloadedFiles;
use John\DownloadPath;

$autoloader = require __DIR__.'/../vendor/autoload.php';
$injectionParams = require_once __DIR__."/injectionParams.php";

ini_set('display_errors', 'on');
CLIFunction::setupErrorHandlers();
ini_set('display_errors', 'off');

$injector = new Injector();

/** @var $injectionParams \Tier\InjectionParams */
$injectionParams->addToInjector($injector);

$app = new TierCLIApp($injector);

$app->addExpectedProduct(AuthToken::class);
$app->addExpectedProduct(DownloadedFiles::class);

$app->addExecutable(10, 'loadTokenFromFile');

$executable = new Executable('createAuthToken', null, null, AuthToken::class);
$app->addExecutable(20, $executable);

if (false) {
    $app->addExecutable(30, 'downloadFiles');
}
else {
    //$app->addExecutable(30, 'listDownloadedFiles');
    
    $fn = function (DownloadPath $downloadPath) {
        $downloadedFiles = [];
        $downloadedFiles[] = $downloadPath->getPath()."/Respect_Validation_f3ad53dd14211f3774236ae90e0ebd60fe20dac7.tar.gz";

        return DownloadedFiles::fromArray($downloadedFiles);
    };
    
    $app->addExecutable(30, $fn);
    
}


$app->addExecutable(40, 'analyzeFiles');

$app->execute();

echo "fin\n";

exit(0);










