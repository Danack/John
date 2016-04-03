<?php


namespace John;

class Report
{
    public $passes = [];
    
    public $failures = [];
    
    public $errors = [];

    public function addResultPass($srcPath, $class)
    {
        echo "Class [$class] has no clashing properties\n";
        $this->passes[] = [$srcPath, $class];
    }

    public function addResultFailure($srcPath, $class, array $problems)
    {
        echo "Failure class $class message\n";
        foreach ($problems as $problem) {
            echo $problem;
        }

        $this->failures[] = [$srcPath, $class, $problems];
    }

    public function addResultError($srcPath, $class, $message)
    {
        echo "Error src $srcPath class $class message $message\n";
        $this->errors[] = [$srcPath, $class, $message];
    }
}
