<?php
// Basic init
// Assumed vroot if given
$virtualRoot = null;
// If null, doc root is used
$realRoot = null;

// Set params and do basic security checks
error_reporting(E_ERROR);

$virtualRoot = "/" . trim($virtualRoot? $virtualRoot : "", "/");
$realRoot = "/" . trim($realRoot? $realRoot : $_SERVER["DOCUMENT_ROOT"], "/");

$reqUri = $_SERVER["REQUEST_URI"];
$workingUrl = parse_url($reqUri);
$workingQuery = [];

parse_str($workingUrl["query"] ?? "", $workingQuery);

$reqPath = "/" . trim($workingUrl["path"] ?? "", "/");
$realPath = $realRoot . $reqPath;

// Strip vroot
if (substr($reqPath, 0, strlen($virtualRoot)) == $virtualRoot) {
    $realPath = $realRoot . substr($reqPath, strlen($virtualRoot));
}

// Final canonical path and 400 checks
$realPath = realpath($realPath);
$sort = $workingQuery["s"] ?? null;
$sortDir = $workingQuery["d"] ?? null;
$pageTitle = "ls $reqPath/";

if ($realPath === false) {
    http_response_code(404);
    exit;
} else if (strpos($realPath, $realRoot) !== 0 || !is_readable($realPath)) {
    http_response_code(403);
    exit;
} else if (!($dir = opendir($realPath))) {
    http_response_code(400);
    //readfile($realPath);
    exit;
}

$linkPath = "$realPath/links.csv";

// ls working dir
$ls = [];

while (true) {
    $entry = readdir($dir);

    if ($entry === false) {
        break;
    }

    $ls[$entry] = (object)[
        "name" => $entry,
        "canonical" => "$realPath/$entry",
        "href" => "$reqPath/$entry"
    ];

    $stat = stat($ls[$entry]->canonical);
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

// Main / stuff I need to encapsulate
function getLinkEntries($filename) {
    $stream = fopen($filename, "r");
    $entries = [];

    if (!$stream) {
        return $entries;
    }

    while (($line = fgetcsv($stream)) !== false) {
        $line = array_filter(array_map("trim", $line));

        if (count($line) < 2 || $line[0][0] == '#') {
            continue;
        }

        $slug = $line[0];

        if (!$slug || $slug[0] == '#') {
            continue;
        }

        $entries[$slug] = (object)[
            "slug" => $slug,
            "name" => $line[2] ?? $slug,
            "href" => $line[1],
            "description" => $line[3] ?? null,
        ];
    }

    fclose($stream);

    return $entries;
}

// Utils
function s(...$args) { return htmlentities(...$args); }
function prettyDate($ts) {
    if (is_null($ts)) {
        return "";
    } else {
        return date("Y-m-d H:i:s T", $ts);
    }
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
function getRowHtml($entry) {
    switch ($entry->name) {
    case ".":
        return null;
        break;
    }

    $isLocal = isset($entry->mtime) && isset($entry->size);
?>
<div class="entry">
    <a class="name" <?=!$isLocal? 'target="_blank" ' : ''?>href="<?=s($entry->href)?>"><?=s($entry->name)?></a>
    <?php if (!empty($entry->description)) { ?>
    <span class="description"><?=s($entry->description)?></span>
    <?php } ?>
    <?php if ($isLocal) { ?>
    <span class="mtime"><?=s(prettyDate($entry->mtime))?></span>
    <span class="size"><?=s(prettySize($entry->size))?></span>
    <?php } ?>
</div>
<?php
}
?>

<?php foreach ($aggregatedLs as $group => $entries) { ?>
    <h1 class="group-header"><?=s($group)?></h1>
    <?php foreach ($entries as $entry) { getRowHtml($entry); } ?>
<?php } ?>

<?php if (($links = getLinkEntries($linkPath))) { ?>
    <h1 class="group-header">Links</h1>
    <?php foreach ($links as $entry) { getRowHtml($entry); } ?>
<?php } ?>
        </div>
    </body>
</html>
