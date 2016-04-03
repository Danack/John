<?php


namespace John;

class DownloadedFiles implements \IteratorAggregate
{
    public $list = [];
    
    private function __construct()
    {
        //private to prevent accidental construction
    }
    
    public static function fromArray(array $fileList)
    {
        $instance = new self();
        $instance->list = $fileList;

        return $instance;
    }

    /**
     * @return \ArrayIterator|\string[]
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->list);
    }
}
