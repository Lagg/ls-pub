<?php
// Basic init
// Assumed vroot if given
$virtualRoot = null;
// If null, doc root is used
$realRoot = null;

// Set params and do basic security checks
error_reporting(E_ERROR);

// Main logic
class LsDir {
    const DEFAULT_GROUP = "Misc";
    const LINKS_GROUP = "Links";
    const LINKS_FILE = "links.csv";

    public $realRoot;
    public $virtualRoot;

    public static $entryGroups = [
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
        ],
        self::LINKS_GROUP => []
    ];

    public function __construct($realRoot, $virtualRoot=null) {
        $this->virtualRoot = "/" . trim($virtualRoot? $virtualRoot : "", "/");
        $this->realRoot = "/" . trim($realRoot? $realRoot : $_SERVER["DOCUMENT_ROOT"], "/");
    }

    public function aggregateEntries($entries) {
        $groupKeys = array_keys(self::$entryGroups);
        $aggregated = array_fill_keys($groupKeys, []);

        foreach ($entries as $entry) {
            $lastDot = strrpos($entry->name, ".");
            $ext = $lastDot? substr($entry->name, $lastDot) : null;
            $entryGroup = self::DEFAULT_GROUP;

            foreach (self::$entryGroups as $group => $groupExt) {
                if (in_array($ext, $groupExt, true)) {
                    $entryGroup = $group;
                    break;
                }
            }

            if (!isset($aggregated[$entryGroup])) {
                $aggregated[$entryGroup] = [];
            }

            $aggregated[$entryGroup][] = $entry;
        }

        return $aggregated;
    }

    public function assertRequest($req) {
        $dir = null;

        if ($req->realPath === false) {
            http_response_code(404);
            exit;
        } else if (strpos($req->realPath, $this->realRoot) !== 0 || !is_readable($req->realPath)) {
            http_response_code(403);
            exit;
        }

        $entries = $this->getEntries($req);

        if (is_null($entries)) {
            http_response_code(400);
            exit;
        }

        return $entries;
    }

    public function getEntries($req) {
        $ls = [];

        $dir = opendir($req->realPath);

        if (!$dir) {
            return null;
        }

        while (true) {
            $entry = readdir($dir);

            if ($entry === false) {
                break;
            }

            $ls[$entry] = (object)[
                "name" => $entry,
                "canonical" => "$req->realPath/$entry",
                "href" => "$req->path/$entry"
            ];

            $stat = stat($ls[$entry]->canonical);
            foreach (["size", "atime", "ctime", "mtime"] as $k) {
                $ls[$entry]->{$k} = $stat[$k] ?? null;
            }
        }

        closedir($dir);

        return $ls;
    }

    public function getLinkEntries($realPath) {
        $stream = fopen($realPath, "r");
        $entries = [];

        if (!$stream) {
            return $entries;
        }

        while (($line = fgetcsv($stream)) !== false) {
            $line = array_filter(array_map(function($field) {
                return trim((string)$field);
            }, $line));

            if (count($line) < 2 || $line[0][0] == '#') {
                continue;
            }

            $slug = $line[0];

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

    public function resolveRequest() {
        $reqUri = $_SERVER["REQUEST_URI"];
        $workingUrl = parse_url($reqUri);
        $workingQuery = [];

        parse_str($workingUrl["query"] ?? "", $workingQuery);

        $reqPath = "/" . trim($workingUrl["path"] ?? "", "/");
        $realPath = $this->realRoot . $reqPath;

        // Strip vroot
        if (substr($reqPath, 0, strlen($this->virtualRoot)) == $this->virtualRoot) {
            $realPath = $this->realRoot . substr($reqPath, strlen($this->virtualRoot));
        }

        $realPath = realpath($realPath);

        return (object)[
            "path" => $reqPath,
            "realPath" => $realPath,
            "query" => $workingQuery
        ];
    }

    public function sortEntries($entries, $opts=[]) {
        $sort = $opts["by"];
        $sortDir = $opts["dir"];

        usort($entries, function($a, $b) use ($sort, $sortDir) {
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

        return $entries;
    }
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

// Init
$lister = new LsDir($realRoot, $virtualRoot);

$req = $lister->resolveRequest();
$entries = $lister->assertRequest($req);

$linkFile = $entries[LsDir::LINKS_FILE] ?? null;

$entries = $lister->sortEntries($entries, [
    "by" => $req->query["sort"] ?? null,
    "dir" => $req->query["dir"] ?? null
]);
$entries = $lister->aggregateEntries($entries);
$entries[LsDir::LINKS_GROUP] = $linkFile? $lister->getLinkEntries($linkFile->canonical) : null;

$pageTitle = "ls $req->path/";
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

<?php foreach ($entries as $group => $data) { ?>
    <?php if (!$data) { continue; } ?>
    <h1 class="group-header"><?=s($group)?></h1>
    <?php foreach ($data as $entry) { getRowHtml($entry); } ?>
<?php } ?>
        </div>
    </body>
</html>
