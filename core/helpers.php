<?php

use JetBrains\PhpStorm\NoReturn;

trait Helpers
{
    protected static function log($message, $depth, $color = false, $noTime = false, $modifyMode = false): void
    {
        // In quiet mode, only show essential test progress messages
        if (isset(self::$commandLineArguments['quiet']) && self::$commandLineArguments['quiet']) {
            $allowedPatterns = [
                'Starting testing',
                'Memory:',
                'Engine:',
                'CPU:',
                'Original query',
                'Loaded',
                'queries to test',
                'Saving data for engine',
                'Saved to',
                'ERROR:',
                'WARNING:'
            ];
            
            $isAllowed = false;
            foreach ($allowedPatterns as $pattern) {
                if (strpos($message, $pattern) !== false) {
                    $isAllowed = true;
                    break;
                }
            }
            
            if (!$isAllowed) {
                return;
            }
        }

        $depth--;
        $colors = ['black' => 30, 'red' => 31, 'green' => 32, 'yellow' => 33, 'blue' => 34, 'magenta' => 35, 'cyan' => 36, 'white' => 37, 'bright_black' => 90];
        $lines = preg_split('/\r\n|\n\r|\r|\n/', trim($message));
        foreach ($lines as $line) if (trim($line) != "") {
            $prepend = "";
            if (!$noTime) {
                if (stream_isatty(STDOUT)) $prepend .= "\033[01;" . $colors['white'] . "m" . date('r') . "\033[0m ";
                else $prepend .= date('r') . " ";
            }
            if ($depth > 0) $tabs = str_repeat("   ", $depth); else $tabs = "";
            if (!$modifyMode) $prepend .= $tabs;
            $lineColor = false;
            if (preg_match('/error/i', $line) and !$modifyMode) $lineColor = 'red';
            else if (preg_match('/warning/i', $line) and !$modifyMode) $lineColor = 'yellow';
            if ($color and !$modifyMode) $lineColor = $color;

            if (!stream_isatty(STDOUT)) $lineColor = false; // disable spec. characters in case there's no TTY at stdout

            if (isset($colors[$lineColor])) $prepend .= "\033[01;" . $colors[$lineColor] . "m";
            if ($modifyMode) echo preg_replace('/(\d\d\d\d \d\d:\d\d:\d\d \+\d\d\d\d\\033\[0m )\s*/', '$1' . $tabs, $prepend . $line);
            else echo $prepend . $line;
            if ($lineColor and isset($colors[$lineColor]) and !$modifyMode) echo "\033[0m";
            echo "\n";
        }
    }

    #[NoReturn]
    public static function die($message, $depth, $color = false, $noTime = false): void
    {
        self::log($message, $depth, $color, $noTime);
        exit(1);
    }

    public static function getopt(array $ar, string $short = '') {
        $out = [];
        foreach ($ar as $el) {
            $el = rtrim($el, ':');
            if (getenv($el)) $out[$el] = getenv($el);
        }
        $opts = getopt($short, $ar);
        foreach ($opts as $k => $v) {
            if (isset($out[$k])) self::die("ERROR: environment variable \"$k\" conflicts with the command line argument", 1);
            $out[$k] = $v;
        }
        return $out;
    }

    protected function checkEngineHealth(string $engineName)
    {
        self::log("Checking health for $engineName", 2);
        exec("docker inspect {$engineName}_engine", $o, $r);
        if ($r) {
            self::log("ERROR: exit code $r", 3);
            return $r;
        }
        $o = implode("\n", $o);
        $j = json_decode($o);
        if (@$j[0]->State->Status == 'exited') {
            self::log("ERROR: exit code " . $j[0]->State->ExitCode, 3);
            return $j[0]->State->ExitCode;
        }
        self::log("$engineName is ok", 3);
        return 0;
    }


    private function getFailedIngestionPath(): string
    {
        return 'results' . DIRECTORY_SEPARATOR . 'failed_ingestion'
            . DIRECTORY_SEPARATOR;
    }
}