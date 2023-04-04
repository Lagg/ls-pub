<?php
//error_reporting(E_ERROR);

// Set params and do basic security checks
$pi = $_SERVER["PATH_INFO"] ?? null;
$cwd = $_SERVER["DOCUMENT_ROOT"];
$nwp = realpath($pi ?? $_GET["p"] ?? $cwd);
$sort = $_GET["s"] ?? null;
$sortDir = $_GET["d"] ?? null;
$dir = null;

if ($nwp === false) {
    http_response_code(404);
    exit;
} else if (strpos($nwp, $cwd) !== 0 || !is_readable($nwp)) {
    http_response_code(403);
    exit;
} else if (!($dir = opendir($nwp))) {
    http_response_code(400);
    //readfile($nwp);
    exit;
}

$pageTitle = "ls " . basename($nwp);

// ls working dir
$ls = [];

while (true) {
    $entry = readdir($dir);

    if ($entry === false) {
        break;
    }

    $stat = stat($entry);
    $ls[$entry] = (object)[
        "name" => $entry
    ];

    foreach (["size", "atime", "ctime", "mtime"] as $k) {
        $ls[$entry]->{$k} = $stat[$k] ?? null;
    }
}

closedir($dir);

// Sort
usort($ls, function($a, $b) use ($sort, $sortDir) {
    $sort = $sort ?? "name";
    $desc = $sortDir == "desc";
    $a = $a->{$sort} ?? null;
    $b = $b->{$sort} ?? null;

    if ($a < $b) {
        return !$desc? -1 : 1;
    } else if ($a > $b) {
        return !$desc? 1 : -1;
    } else {
        return 0;
    }
});

// Aggregate
$groups = [
    "Music" => [
        ".mp3",
        ".ogg",
        ".flac"
    ],
    "Images" => [
        ".jpg",
        ".png",
        ".bmp",
    ],
    "Videos" => [
        ".mp4",
        ".ogv",
        ".avi"
    ]
];
$defaultGroup = "Stuff";
$sortKeys = array_flip(array_keys($groups));
$aggregatedLs = [];

foreach ($ls as $entry) {
    $lastDot = strrpos($entry->name, ".");
    $ext = $lastDot? substr($entry->name, $lastDot) : null;
    $entryGroup = $defaultGroup;

    foreach ($groups as $group => $groupExt) {
        if (in_array($ext, $groupExt, true)) {
            $entryGroup = $group;
            break;
        }
    }

    if (!isset($aggregatedLs[$entryGroup])) {
        $aggregatedLs[$entryGroup] = [];
    }

    $aggregatedLs[$entryGroup][] = $entry;
}

uksort($aggregatedLs, function($a, $b) use ($sortKeys) {
    $a = $sortKeys[$a] ?? 500;
    $b = $sortKeys[$b] ?? 500;

    if ($a < $b) {
        return -1;
    } else if ($a > $b) {
        return 1;
    } else {
        return 0;
    }
});

// Utils
function s(...$args) { return htmlentities(...$args); }
function prettyDate($ts) {
    return date("Y-m-d H:i:s T", $ts);
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
?>
<!DOCTYPE html>
<html>
    <head>
        <title><?=s($pageTitle)?></title>
        <link rel="stylesheet" type="text/css" href="/style.css"/>
        <style>
            #entries {
                margin: 2em auto;
            }

            .group-header {
                text-align: left;
                margin-left: 1em;
            }

            .entry {
                text-align: left;
                margin: .40em 2em;
                border: 1px solid transparent;
            }

            .entry > * {
                min-width: 20%;
                display: inline-block;
                margin: 0 1em;
            }

            .entry .name {
                font-weight: bold;
                font-size: 1.1em;
                padding: 5px;
            }

            .entry:hover {
                background-color: rgb(34, 35, 48, .93);
                border: 1px solid #007442;
                border-radius: 6px;
            }

            .entry .name:hover {
            }
        </style>
    </head>
    <body>
        <div id="entries">
<?php
function getRowHtml($pi, $entry) {
    $href = (($pi || !is_dir($entry->name))? "" : "?p=") . $entry->name;
?>
<div class="entry">
    <a class="name" href="<?=s($href)?>"><?=s($entry->name)?></a>
<!--    <td><code><pre><?=s(json_encode($entry, JSON_PRETTY_PRINT))?></pre></code></td>-->
    <span class="mtime"><?=s(prettyDate($entry->mtime))?></span>
    <span class="size"><?=s(prettySize($entry->size))?></span>
</div>
<?php
}

foreach ($aggregatedLs as $group => $entries) {
?>
<h1 class="group-header"><?=s($group)?></h1>
<?php
    foreach ($entries as $entry) {
        getRowHtml($pi, $entry);
    }
}
?>
        </div>
    </body>
</html>
