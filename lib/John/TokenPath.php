<?php

namespace John;

class TokenPath
{
    private $path;

    public function __construct($path)
    {
        if ($path === null) {
            throw new \LogicException(
                "Path cannot be null for TokenPath."
            );
        }
        $this->path = $path;
    }

    public function getPath()
    {
        return $this->path;
    }
}
