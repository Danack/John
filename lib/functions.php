<?php

use Amp\Artax\Client as ArtaxClient;
use ArtaxServiceBuilder\ResponseCache\FileResponseCache;
use ArtaxServiceBuilder\ResponseCache\FileCachePath;
use BetterReflection\Reflector\ClassReflector;
use BetterReflection\SourceLocator\Type\AggregateSourceLocator;
use BetterReflection\SourceLocator\Type\SingleFileSourceLocator;
use GithubService\AuthToken;
use John\Report;
use PhpParser\ParserFactory;
use GithubService\GithubArtaxService\GithubService;
use GithubService\AuthToken\Oauth2Token;
use GithubService\Model\SearchRepoItem;
use John\AnalysisPath;
use John\TokenPath;
use John\DownloadedFiles;
use John\RepoSearcher;
use John\DownloadPath;

function removeDirectory($path)
{
    if (is_dir($path) == false) {
        //Directory doesn't exist.
        return;
    }
    $files = new DirectoryIterator($path);
    foreach ($files as $file) {        
        if (!$file->isDot()) {
            if (is_dir($file->getPathname())) {
                removeDirectory($file->getPathname());
            }
            else {
                unlink($file->getPathname());
            }
        }
    }

    rmdir($path);
    return;
}


function parseNodes($nodes, $namespace = null)
{
    $classes = [];
    foreach ($nodes as $node) {
        if ($node instanceof \PhpParser\Node\Stmt\Namespace_) {
            $namespace = implode('\\', $node->name->parts);
            $namespaceClasses = parseNodes($node->stmts, $namespace);
            $classes = array_merge($classes, $namespaceClasses);
        }
        if ($node instanceof \PhpParser\Node\Stmt\Class_) {
            if ($namespace === null) {
                $classes[] = $node->name;
            }
            else {
                $classes[] = $namespace.'\\'.$node->name;
            }
        }
    }
    
    return $classes;
}


function listDownloadedFiles(DownloadPath $downloadPath)
{
    $regexString = '#.*\.tar.gz#';
    $dirIterator = new DirectoryIterator($downloadPath->getPath());
    $downloadedFilesList = new \RegexIterator($dirIterator, $regexString);
    $downloadedFiles = [];
    
    foreach ($downloadedFilesList as $file) {
        $downloadedFiles[] = $file->getPathName();
    }

    return DownloadedFiles::fromArray($downloadedFiles);
}
    

function analyzeCodeInPath($srcPath, Report $report, $downloadedFile)
{
    $objects = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($srcPath),
        \RecursiveIteratorIterator::SELF_FIRST
    );
    
    $extensions = [
        'php',
        'php5',
        'phtml',
        'inc',
        'module',
        'install'
    ];
    
    $regexString = '#.*\.('.implode('|', $extensions).')#';
    $sourceFileIterator = new \RegexIterator($objects, $regexString);
    
    $sourceFiles = [];
    $sourceLocators = [];
    foreach ($sourceFileIterator as $key => $sourceFile) {
        $sourceFiles[] = $sourceFile;
        $sourceLocators[] = new SingleFileSourceLocator($sourceFile);
    }

    $sourceLocator = new AggregateSourceLocator($sourceLocators);
    $reflector = new ClassReflector($sourceLocator);

    foreach ($sourceFiles as $sourceFile) {
        $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);

        try {
            $code = @file_get_contents($sourceFile);
            if ($code === false) {
                echo "Failed to get contents of $sourceFile \n";
                continue;
            }

            $nodes = $parser->parse($code);
            if ($code === null) {
                echo "Failed to parse code for file $sourceFile";
                continue;
            }

            $classes = parseNodes($nodes);

            foreach ($classes as $class) {
                echo "Attempting to analyze class [$class] for $downloadedFile\n";
                
                try {
                    $reflClass = $reflector->reflect($class);
                    if ($reflClass == null) {
                        echo "Failed to reflect class [$reflClass]";
                        break 2;
                    }

                    $properties = $reflClass->getProperties();
                    $methods = $reflClass->getMethods();
                    $clashingProperties = [];

                    foreach ($properties as $property) {
                        foreach ($methods as $method) {
                            if (strcasecmp($property->getName(), $method->getName()) === 0) {
                                $clashingProperties[] = $property->getName();
                            }
                        }
                    }

                    if (count($clashingProperties) == 0) {
                        //echo "Class [$class] has no clashing properties\n";
                        $report->addResultPass($srcPath, $class);
                    }
                    else {
                        $report->addResultFailure($srcPath, $class, $clashingProperties);
//                        
//                        
//                        echo "Class [$class] has the following clashing properties\n";
//                        foreach ($clashingProperties as $clashingProperty) {
//                            echo "    $clashingProperty\n";
//                        }
                    }
                }
                catch (\Throwable $t) {
                    
                    $message = "Code analysis failed.";
                    $message .= get_class($t)." : ".$t->getMessage();
                    $report->addResultError($srcPath, $class, $message);
                    //echo $t->getTraceAsString();
                }
            }
        }
        catch (BadMethodCallException $bmce) {
            echo "BadMethodCallException: ".$bmce->getMessage()."\n";
        }
        catch (PhpParser\Error $e) {
            echo "Failed to parse code\n";
        }
    }
}


function printRepo(SearchRepoItem $repo)
{
    printf(
        "Package: %s/%s stars: %d\n",
        $repo->owner->login,
        $repo->name,
        $repo->stargazersCount
    );
}


function downloadFiles(RepoSearcher $repoSearcher)
{
    $downloadedFiles = [];
    $repoList = $repoSearcher->findTopRepos(50, 20000);

    foreach ($repoList as $repo) {
        $lastCommit = $repoSearcher->findLastCommit($repo->owner, $repo->name);
        if ($lastCommit === null) {
            printf(
                "Package %s/%s has no commit, cannot scan\n",
                $repo->owner,
                $repo->name
            );
            continue;
        }

        $downloadedFilename = $repoSearcher->downloadPackage($repo->owner, $repo->name, $lastCommit);
        $downloadedFiles[] = $downloadedFilename;
    }
    
    return DownloadedFiles::fromArray($downloadedFiles);
}




function analyzeFiles(
    AnalysisPath $analysisPath,
    DownloadedFiles $downloadedFiles,
    Report $report
) {
    // PHP Phar gets confused opening phar's with the same name, in the same 
    // process, even after the previous one is closed...
    static $count = 0;
    
    $path = $analysisPath->getPath();
    @mkdir($path, 0755, true);

    foreach ($downloadedFiles as $downloadedFile) {
        try {
            echo "Analyzing $downloadedFile:\n";
            $count++;

            $tmpFilename = $path."/temp$count.tar.gz";
            @unlink($tmpFilename);
            copy($downloadedFile, $tmpFilename);
            $pharData = new PharData($tmpFilename);
            @unlink($path."./temp$count.tar");
            $pharData->decompress(); // creates files.tar

            $tarFileName = $path."/temp$count.tar";
            $decompPhar = new PharData($tarFileName);
            
            $extractPath = $path."/subdir";

            removeDirectory($extractPath);
            @mkdir($extractPath);

            $decompPhar->extractTo($extractPath);

            analyzeCodeInPath($extractPath, $report, $downloadedFile);

            @unlink($tmpFilename);
            @unlink($tarFileName);

            removeDirectory($extractPath);
        }
        catch (\PharException $pe) {
            echo "Failed to extract files: ".$pe->getMessage()."\n";
        }
    }
}


function createAuthToken(GithubService $githubService, \John\TokenPath $tokenPath)
{
    $tokenFileLocation = $tokenPath->getPath();

    echo "Enter username:\n";
    $username = trim(fgets(STDIN));

    echo "Enter password (warning - not masked for this example):\n";
    $password = trim(fgets(STDIN));

    /**
     * @param $instruction
     * @return string
     */
    $enterPasswordCallback = function ($instruction) {
        echo $instruction."\n";
        $oneTimePassword = trim(fgets(STDIN));

        return $oneTimePassword;
    };

    //This must be unique per user to create a new Oauth key
    $note = "John code analysis: ".time();
    
    // List of the scopes required. An empty list is used to get a token
    // with no access, just to avoid the 50 reqs/hour limit for unsigned
    // api calls. 
    //$scopes = [GithubService::SCOPE_PUBLIC_REPO];
    $scopes = [];

    try {
        //Attempt to either create or retrieve an Oauth token
        $authResult = $githubService->createOrRetrieveAuth(
            $username,
            $password,
            $enterPasswordCallback,
            $scopes,
            $note,
            $maxAttempts = 3
        );

        $written = @file_put_contents($tokenFileLocation, $authResult->token);
        if (!$written) {
            throw new \Exception("Failed to write token to file [$tokenFileLocation]");
        }

        echo "Token stored in ".$tokenFileLocation."\n";

        return new Oauth2Token($authResult->token);
        
    }
    catch (ArtaxServiceBuilder\BadResponseException $badResponseException) {
        
        $message = "Something went wrong trying to retrieve an oauth token:\n";
        $message .= $badResponseException->getMessage();
        
        throw new \Exception(
            $message,
            $badResponseException->getCode(),
            $badResponseException
        );
    }
}



function createGithubService(FileCachePath $fileCachePath)
{
    $githubClient = new GithubService(
        new ArtaxClient(),
        \Amp\reactor(),
        new FileResponseCache($fileCachePath),
        'Danack/GithubArtaxService'
    );
    
    return $githubClient;
}


function loadTokenFromFile(TokenPath $tokenPath)
{
    echo "Attempting to load token from ".$tokenPath->getPath()."\n";

    $existingToken = @file_get_contents($tokenPath->getPath());
    if ($existingToken == false) {
        return false;
    }
    $existingToken = trim($existingToken);

    if (!$existingToken) {
        //Token file is either empty or doesn't xi
        return false;
    }

    echo "Token loaded from file.\n";

    return new Oauth2Token($existingToken);
}


function showResults(John\Report $report)
{
//    
//    foreach ($report->passes as $pass) {
//        
//
//    }
    
        
    echo "Summary\n";
    echo "-------\n";
    echo "Passes: ".count($report->passes)."\n";
    echo "Errors: ".count($report->errors)."\n";
    echo "Failures: ".count($report->failures)."\n";
}