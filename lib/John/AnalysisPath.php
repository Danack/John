<?php

namespace John;

class AnalysisPath
{
    private $path;

    public function __construct($path)
    {
        if ($path === null) {
            throw new \LogicException(
                "Path cannot be null for AnalysisPath."
            );
        }
        $this->path = $path;
    }

    public function getPath()
    {
        return $this->path;
    }
}
