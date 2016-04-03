<?php

use Tier\InjectionParams;


// These classes will only be created  by the injector once
$shares = [
    'Amp\Reactor',
    'John\Report',
    \John\DownloadedFiles::class,
    \GithubService\GithubArtaxService\GithubService::class,
    new \ArtaxServiceBuilder\ResponseCache\FileCachePath(__DIR__."/../var/fileCache"),
    new \John\AnalysisPath(__DIR__."/../var/analysis"),
    new \John\DownloadPath(__DIR__."/../var/download"),
    // Token is stored outside of project dir. Storing tokens inside
    // a project is a great way to accidentally add them to VCS
    new \John\TokenPath(__DIR__."/../../github_oauth_token.txt"),
    
];
    

// Alias interfaces (or classes) to the actual types that should be used 
// where they are required. 
$aliases = [
];


// Delegate the creation of types to callables.
$delegates = [
    \GithubService\GithubArtaxService\GithubService::class => 'createGithubService',
    
];

// If necessary, define some params that can be injected purely by name.
$params = [ ];

$defines = [
];

$prepares = [

];

$injectionParams = new InjectionParams(
    $shares,
    $aliases,
    $delegates,
    $params,
    $prepares,
    $defines
);

return $injectionParams;
