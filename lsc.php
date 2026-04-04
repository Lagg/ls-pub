#!/usr/bin/env php
<?php namespace LsCmd;

require_once("lib/main.php");

class Command {
    public function out(...$args) {
        echo implode(", ", array_map(function($arg) {
            if (is_scalar($arg) && ctype_print(strtr($arg, "\r\n", "  "))) {
                return $arg;
            } else {
                return json_encode(\LsPub\varDump($arg));
            }
        }, $args)) . "\n";
    }

    public function run($cmd) {
        $this->out("Base command class for $cmd->command");
    }

    public static function getArgv() {
        return $_SERVER["argv"] ?? [];
    }

    public static function resolve($args) {
        $resolved = (object)[
            "dispatcher" => $args[0] ?? null,
            "command" => $args[1] ?? null,
            "args" => array_slice($args, 2)
        ];

        $resolved->commandPath = "tools/$resolved->command.php";

        $resolved->commandClass = str_replace("-", "", ucwords($resolved->command, "-"));

        if (!$resolved->command || !file_exists($resolved->commandPath)) {
            return null;
        } else {
            return $resolved;
        }
    }
}

$resolved = Command::resolve(Command::getArgv());

if (!$resolved) {
    echo "No such command\n";
} else {
    require($resolved->commandPath);

    $cmdClass = "LsCmd\\$resolved->commandClass";
    $cmd = new $cmdClass();
    $cmd->run($resolved);
}
?>
