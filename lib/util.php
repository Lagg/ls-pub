<?php namespace LsPub;

class Url {
    const CONNECT_TIMEOUT = 2;
    const TIMEOUT = 4;

    public $url;
    public $urlInfo;
    public $urlHeaders;

    public array $curlOpts;

    public function __construct(string $url) {
        $name = Config::get("project.name");
        $httpHeaders = ["User-Agent: $name Lore Scraper"];

        $this->url = $url;

        $this->curlOpts = [
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HEADERFUNCTION => [$this, "writeUrlHeaders"]
        ];
    }

    public function get($opts=null) {
        return $this->execHandle($opts);
    }

    public function head($opts=null) {
        $headers = [];
        $opts = (array)($opts ?? null);
        $opts[CURLOPT_HEADER] = true;
        $opts[CURLOPT_NOBODY] = true;

        return self::parseHttpHeaders($this->execHandle($opts));
    }

    public static function parseHttpHeaders($data) {
        foreach (explode("\n", $data) as $header) {
            $pair = explode(':', $header, 2);

            if (count($pair) != 2) {
                continue;
            }

            $k = strtolower($pair[0]);
            $v = $pair[1];

            if ($k == "content-length") {
                $v = (int)$v;
            }

            $headers[$k] = $v;
        }

        return $headers;

    }

    private function execHandle($opts=null) {
        $opts = $this->getOpts($opts);
        $opts[CURLOPT_URL] = $this->url;

        $ctx = curl_init();

        curl_setopt_array($ctx, $opts);

        $this->urlHeaders = "";

        $data = curl_exec($ctx);

        $this->urlInfo = curl_getinfo($ctx);

        if (PHP_MAJOR_VERSION < 8) {
            curl_close($ctx);
        }

        return $data;
    }

    private function getOpts($opts) {
        $mOpts = $this->curlOpts + [];

        foreach ($opts ?? [] as $k => $v) {
            $mOpts[$k] = $v;
        }

        return $mOpts;
    }

    private function writeUrlHeaders($ctx, $data) {
        $this->urlHeaders .= $data;

        return strlen($data);
    }
}

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

function psplit($path, $root='/', $tok='/') {
    return array_filter(explode($tok, pnorm($path, $root)));
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
