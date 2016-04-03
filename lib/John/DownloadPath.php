<?php

namespace John;

class DownloadPath
{
    private $path;

    public function __construct($path)
    {
        if ($path === null) {
            throw new \LogicException(
                "Path cannot be null for DownloadPath."
            );
        }
        $this->path = $path;
    }

    public function getPath()
    {
        return $this->path;
    }
}
