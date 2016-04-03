<?php

namespace John;

use GithubService\AuthToken;
use GithubService\GithubArtaxService\GithubService;
use GithubService\SearchHelper;
use GithubService\Model\Commit;
use John\DownloadPath;


class RepoSearcher
{
    public $reposToScan = [];
    public $githubClient;
    public $authToken;

    public function __construct(
        GithubService $githubClient,
        AuthToken $authToken,
        DownloadPath $downloadPath
    ) {
        $this->githubClient = $githubClient;
        $this->authToken = $authToken;
        $this->downloadPath = $downloadPath;
    }

    /**
     * @param $numberReposToFind
     * @param $maxSizeInKB
     * @return \GithubExample\RepoToScan[]
     */
    function findTopRepos($numberReposToFind, $maxSizeInKB)
    {
        $searchHelper = new SearchHelper();
        $searchHelper->languages[] = 'php';
        $searchHelper->sizeMax = $maxSizeInKB;

        $qString = $searchHelper->createString();
        $reposToScan = [];
        $command = $this->githubClient->searchRepos(
            $this->authToken,
            $qString
        );

        $searchRepoResult = $command->execute();
        $command->setSort('stars');

        foreach ($searchRepoResult->repoList as $repo) {
            printRepo($repo);
            $reposToScan[] = new RepoToScan($repo->owner->login, $repo->name, $repo->url);
        }

        $pages = $searchRepoResult->pager->getAllKnownPages();

        foreach ($pages as $page) {
            echo "Page $page \n";
            $command = $this->githubClient->searchReposPaginate($this->authToken, $page);
            $foo = $command->execute();
            foreach ($foo->repoList as $repo) {
                
                printRepo($repo);
                $reposToScan[] = new RepoToScan($repo->owner->login, $repo->name, $repo->url);
            }
            if (count($reposToScan) > $numberReposToFind) {
                break;
            }
        }
        
        return $reposToScan;
    }

    /**
     * @param $author
     * @param $packageName
     * @return null|\GithubService\Model\Commit
     */
    function findLastCommit($author, $packageName)
    {
        $operation = $this->githubClient->listRepoCommits(
            $this->authToken,
            $author,
            $packageName
        );

        $commitList = $operation->execute();

        foreach ($commitList->commitsChild as $commit) {
            return $commit;
        }
        return null;
    }

    function downloadPackage($author, $packageName, Commit $commit)
    {
        static $count = 0;
        $blobType = 'tar.gz';

        $archiveOperation = $this->githubClient->getArchiveLink(
            $this->authToken,
            $author,
            $packageName,
            $commit->sha
        );

        $archiveFilename = sprintf(
            "%s/%s_%s_%s.%s",
            $this->downloadPath->getPath(),
            $author,
            $packageName,
            $commit->sha,
            $blobType
        );
        
        @mkdir($this->downloadPath->getPath(), 0755, true);

        if (file_exists($archiveFilename)) {
            echo "File $archiveFilename already exists, skipping\n";
            return $archiveFilename; //Already exists 
        }
        
        echo "Downloading file $count $archiveFilename\n";
        $count++;

        $filebody = $archiveOperation->execute();
        file_put_contents($archiveFilename, $filebody);

        return $archiveFilename;
    }
}