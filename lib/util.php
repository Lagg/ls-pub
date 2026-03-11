<?php namespace LsPub;

function prettyDate($ts) {
    return $ts? date("Y-m-d H:i:s T", $ts) : null;
}

function prettySize($size) {
    $b = 1;
    $kb = 1000 * $b;
    $mb = 1000 * $kb;
    $gb = 1000 * $mb;

    $labels = [
        $gb => "gb",
        $mb => "mb",
        $kb => "kb",
        $b => "b"
    ];

    foreach ($labels as $divisor => $label) {
        if ($size >= $divisor ) {
            return sprintf("%.02f %s", $size / $divisor, $label);
        }
    }
}

function pnorm($path, $root='/') {
    return $root . trim($path, '/');
}

function s(...$args) {
    return htmlentities(implode(" ", $args));
}

function varDump($var) {
    $maxDumpLen = 255;

    if (is_object($var)) {
        $var = get_object_vars($var);
    }

    if (is_array($var)) {
        return array_map(function($v) {
            return varDump($v);
        }, $var);
    }

    if (is_scalar($var) && !is_string($var)) {
        return $var;
    }

    $var = (string)$var;
    $varLen = strlen($var);

    if (!ctype_print($var)) {
        $newStr = "";

        for ($i = 0; $i < min($maxDumpLen, $varLen); $i++) {
            $c = $var[$i];
            $newStr .= ctype_print($c)? $c : sprintf("[%02X]", ord($c));
        }

        $var = $newStr;
    }

    if ($varLen > $maxDumpLen) {
        $var .= "... (< " . prettySize($varLen) . " >)";
    }

    return $var;
}

?>
