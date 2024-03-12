<?php


class ScriptCommand
{
    public $location;
    public $command;
    public $onErrorClosure;
    public $errorHandler;

    public function __construct($command, $location = null, $errorHandler = null)
    {
        $this->command      = $command;
        $this->location     = $location;
        $this->errorHandler = $errorHandler;
    }

    function hasError($ssh, $output)
    {
        $debug = true;

        $exitStatus = $ssh->getExitStatus();

        if ($debug)
        {
            echo "Exit status: $exitStatus\n";
            error_log("Exit status: $exitStatus\n");
        }

        if ($exitStatus)
        {
            $stdErr = $ssh->getStdError();
            return "Error executing command: $this->command. Exit status: $exitStatus. Output: $output. Std-Err: $stdErr \n";
        }

        if ($this->errorHandler)
        {
            $errorHandler = $this->errorHandler;
            return $errorHandler($this, $output);
        }
        else
        {
            if (strpos($output, 'error') !== false || strpos($output, 'fatal:') !== false) 
            {
                return "Error executing command: $this->command. Output: $output\n";
            }
        }
        return null;
    }

    public function executeOrDieOnSSH($ssh, $closure = null)
    {
        $debug = true;

        $startedQuiet = $ssh->isQuietModeEnabled();

        if ($startedQuiet)
        {
            if ($debug)
            {
                echo "Quiet mode is enabled. Disabling...\n";
                error_log("Quiet mode is enabled. Disabling...");
            }
            $ssh->disableQuietMode();
        }
        else
        {
            if ($debug)
            {
                echo "Quiet mode is disabled.\n";
                error_log("Quiet mode is disabled.");
            }
        }

        $finalCommand = $this->command;

        if ($this->location)
        {
            $finalCommand = "cd ".$this->location." && ".$this->command;
        }

        if ($debug)
        {
            echo "Executing command: $finalCommand\n";
            error_log("Executing command: $finalCommand");
        }

        $returnValue = $ssh->exec($finalCommand);

        if ($debug)
        {
            echo "Output: $returnValue\n";
            error_log("Output: $returnValue");
        }

        $errorMessage = $this->hasError($ssh, $returnValue);

        if ($startedQuiet)
        {
            $ssh->enableQuietMode();
        }
    
        if ($errorMessage)
        {
            if ($closure && is_callable($closure))
            {
                $closure();
            }
            die($errorMessage); 
        }
        else 
        {
            echo $this->command."\n";
        }
    }


}
