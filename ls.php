<?php
// <CONFIGURATION>

// If null, doc root is used
const ROOT_REAL = null;

// Assumed vroot if given
const ROOT_VIRTUAL = null;

// Youtube scraping, if not given disabled
const YOUTUBE_API_KEY = null;

// File where link list is stored
const LINKS_FILE = "links.txt";

// Whether debug mode / params are supported
const PROJECT_DEBUG = true;

// Name used for titles/user-agent/etc.
const PROJECT_NAME = "Ls'Pub";

// </CONFIGURATION>

// <LIB>

// Error handling
class HttpException extends Exception {
    public function __construct($msg, $code=500) {
        $this->code = (int)$code;

        parent::__construct($msg);
    }
}

class RedirectException extends HttpException {
    public function __construct($msg, $code=null) {
        parent::__construct($msg, $code);
    }
}

// Main lib

class LsDir {
    const DEFAULT_TYPE = null;
    const LINKS_TYPE = "url";

    const MAX_DESC_LEN = 140;

    public $realRoot;
    public $virtualRoot;

    public static $extTypes = [
        "audio" => ["mp3", "ogg", "flac"],
        "image" => ["jpg", "png", "bmp"],
        "video" => ["mp4", "ogv", "avi"]
    ];

    public static $extLabels = [
        "audio" => "Music",
        "image" => "Images",
        "video" => "Videos",
        self::LINKS_TYPE => "Links",
        self::DEFAULT_TYPE => "Misc"
    ];

    public static $sortableFields = ["name", "type", "description", "size", "ctime", "atime", "mtime"];

    public function __construct($realRoot, $virtualRoot=null) {
        $this->virtualRoot = "/" . trim($virtualRoot? $virtualRoot : "", "/");
        $this->realRoot = "/" . trim($realRoot? $realRoot : $_SERVER["DOCUMENT_ROOT"], "/");
    }

    public function aggregateEntries($entries) {
        $aggregated = array_fill_keys(self::$extLabels, []);

        foreach ($entries as $entry) {
            $label = self::$extLabels[$entry->type] ?? self::$extLabels[self::DEFAULT_TYPE] ?? null;

            if (!isset($aggregated[$label])) {
                $aggregated[$label] = [];
            }

            $aggregated[$label][] = $entry;
        }

        return $aggregated;
    }

    public function assertRequest($req) {
        $rootLinks = $this->getLinkEntries($this->realRoot . "/" . LINKS_FILE);

        if ($req->realPath === false) {
            $entry = $rootLinks[trim($req->basePath, '/')] ?? null;

            if ($entry) {
                throw new RedirectException($entry->href);
            } else {
                throw new HttpException("Not found", 404);
            }
        } else if (strpos($req->realPath, $this->realRoot) !== 0 || !is_readable($req->realPath)) {
            throw new HttpException("Forbidden", 403);
        }

        $entries = $this->getEntries($req);

        if (is_null($entries)) {
            throw new HttpException("Bad filename", 400);
        } else {
            $entries = array_merge($entries, $rootLinks);
            return $entries;
        }
    }

    public function getEntries($req) {
        $ls = [];

        $dir = opendir($req->realPath);

        if (!$dir) {
            return null;
        }

        while (($name = readdir($dir)) !== false) {
            // Basic info/path building
            $entry = (object)[
                "name" => $name,
                "canonical" => "$req->realPath/$name",
                "href" => "$req->path/$name"
            ];

            // Stat data
            $stat = stat($entry->canonical);
            foreach (["size", "atime", "ctime", "mtime"] as $k) {
                $entry->{$k} = $stat[$k] ?? null;
            }

            // Basic typing to know when to header-scrape
            $lastDot = strrpos($name, ".");
            $entry->ext = $lastDot? trim(strtolower(substr($name, $lastDot + 1))) : null;
            $entry->type = null;

            foreach (self::$extTypes as $type => $exts) {
                if (in_array($entry->ext, $exts)) {
                    $entry->type = $type;
                    break;
                }
            }

            // Add to ls
            $ls[$entry->name] = $entry;
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
                "type" => self::LINKS_TYPE,
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
        $basePath = $reqPath;

        // Strip vroot
        if (substr($basePath, 0, strlen($this->virtualRoot)) == $this->virtualRoot) {
            $basePath = substr($basePath, strlen($this->virtualRoot));
        }

        $realPath = realpath($this->realRoot . $basePath);

        return (object)[
            "path" => $reqPath,
            "basePath" => $basePath,
            "realPath" => $realPath,
            "query" => $workingQuery
        ];
    }

    public function scrapeEntries($entries) {
        $tube = YOUTUBE_API_KEY? new TubeScraper(YOUTUBE_API_KEY) : null;

        foreach ($entries as $entry) {
            $meta = (new MetaScraper($entry))->scrape();

            if ($meta) {
                $entry->header = $meta->header;
                $entry->meta = $meta->data;
            }
        }

        if ($tube) {
            $tubeMeta = $tube->scrapeVideoMeta($entries);

            foreach ($entries as $entry) {
                $tubeId = TubeScraper::getYouTubeId($entry->href ?? null);

                if (!$tubeId) {
                    continue;
                }

                $meta = $tubeMeta[$tubeId] ?? null;
                $snippet = $meta->snippet ?? null;

                if (!$snippet) {
                    continue;
                }

                $entry->meta = [
                    "channelId" => $snippet->channelId,
                    "channelName" => $snippet->channelTitle,
                    "tags" => $snippet->tags
                ];

                $entry->ctime = strtotime($snippet->publishedAt);
                $entry->description = self::sanitizeDescription($snippet->description);
                $entry->name = $snippet->title;
                $entry->thumb = $snippet->thumbnails->default->url;
            }
        }

        return $entries;
    }

    public function sortEntries($entries, $opts=[]) {
        $sortSet = isset($opts["by"]);
        $sort = $opts["by"] ?? self::$sortableFields[0] ?? null;
        $sortDir = $opts["dir"] ?? null;

        usort($entries, function($a, $b) use ($sort, $sortDir, $sortSet) {
            if (!$sortSet && ($a->type == self::LINKS_TYPE || $b->type == self::LINKS_TYPE)) {
                return 0;
            }

            $sort = in_array($sort, self::$sortableFields)? $sort : null;
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

    public static function sanitizeDescription(string $desc, $length=self::MAX_DESC_LEN) {
        $desc = trim($desc);
        $desc = str_ireplace("provided to youtube", "Uploaded", $desc);

        $lb = stripos($desc, "\n");

        if ($lb) {
            $desc = substr($desc, 0, $lb);
        }

        if (strlen($desc) > $length) {
            $desc = trim(substr($desc, 0, $length), '.') . "...";
        }

        return $desc;
    }
}

class MetaScraper {
    const ID3_FRAME_HDR_SIZE = 10;

    const ID3_XHDR_FLAG = 0b01000000;

    const ID3_HDR_SIZE = 10;
    const ID3_HDR_TAG = "ID3";

    const OGG_COMMENT_TAG = "\x03vorbis";

    const OGG_PAGE_MAX = 5;

    const OGG_PAGE_SIZE = 27;
    const OGG_PAGE_TAG = "OggS";

    const CHUNK_SIZE = 512;

    private $entry;
    private $stream;

    private $chunk;
    private $chunkSize;

    public function __construct($entry) {
        $this->entry = $entry;
    }

    public function closeFile() {
        fclose($this->stream);

        $this->stream = null;
        $this->chunk = null;
    }

    public function getPos() {
        return ftell($this->stream);
    }

    public function isEof() {
        return feof($this->stream);
    }

    public function isScrapable() {
        $canonPath = $this->entry->canonical ?? null;
        return !empty($canonPath) && is_file($canonPath) && is_readable($canonPath);
    }

    public function openFile() {
        $this->stream = fopen($this->entry->canonical, "r");
        $this->chunk = null;
    }

    public function readChunk($size=self::CHUNK_SIZE) {
        $this->chunk = $size > 0? fread($this->stream, $size) : "";
        $this->chunkSize = strlen($this->chunk);

        return $this->chunk;
    }

    public function rewindFile() {
        $this->chunk = null;

        return rewind($this->stream);
    }

    public function parseId3Frame() {
        $this->readChunk(self::ID3_FRAME_HDR_SIZE);

        if ($this->chunkSize != self::ID3_FRAME_HDR_SIZE) {
            return null;
        }

        $frame = (object)unpack("A4id/Nsize/vflags", $this->chunk);

        // Sanity check per spec requiring frame IDs to be [a-zA-Z0-9]
        if (!mb_check_encoding($frame->id, "ascii")) {
            return null;
        }

        // Attempt utf normalization if needed specifically *after* unpack due to null sep
        $frame->data = self::decodeId3Text(self::unpackId3Payload($this->readChunk($frame->size)));

        // Per spec saying it's always a "desc string" followed by null followed by real string
        if ($frame->id == "TXXX") {
            $frame->data = self::unpackId3TextFrame($frame->data);
        }

        return $frame;
    }

    public function parseId3Header() {
        $this->readChunk(self::ID3_HDR_SIZE);

        if ($this->chunkSize != self::ID3_HDR_SIZE) {
            return null;
        }

        // Core header
        $header = (object)unpack("A3tag/vversion/Cflags/Nsize", $this->chunk);

        if ($header->tag != self::ID3_HDR_TAG) {
            return null;
        }

        // Extended header
        if ($header->flags & self::ID3_XHDR_FLAG) {
            // Haven't seen yet/TODO
        }

        return $header;
    }

    public function parseOggPage() {
        $this->readChunk(self::OGG_PAGE_SIZE);

        if ($this->chunkSize != self::OGG_PAGE_SIZE) {
            return null;
        }

        $page = (object)unpack("A4tag/Cversion/Cflags/Pgpos/Vserial/Vpage/A4cksum/Csegments", $this->chunk);

        if ($page->tag != self::OGG_PAGE_TAG) {
            return null;
        }

        $page->segmentTable = array_map('ord', str_split($this->readChunk($page->segments)));
        $page->dataSize = array_sum($page->segmentTable);
        $page->data = $this->readChunk($page->dataSize);

        if (strlen($page->data) != $page->dataSize) {
            return null;
        } else {
            return $page;
        }
    }

    public function scrape() {
        $data = [];
        $header = null;

        if (!$this->isScrapable()) {
            return null;
        }

        $this->openFile();

        if (($header = $this->parseId3Header())) {
            while (($frame = $this->parseId3Frame())) {
                $id = is_array($frame->data)? $frame->data[0] : $frame->id;
                $val = is_array($frame->data)? $frame->data[1] ?? null : $frame->data;

                if (isset($data[$id])) {
                    if (!is_array($data[$id])) {
                        $data[$id] = [$data[$id]];
                    }

                    $data[$id][] = $val;
                } else {
                    $data[$id] = $val;
                }
            }
        }

        if (!$header) {
            $this->rewindFile();

            while(($page = $this->parseOggPage()) && $page->page < self::OGG_PAGE_MAX) {
                if ($page->page == 0) {
                    $header = $page;
                }

                if (self::isOggCommentPayload($page->data)) {
                    foreach (self::unpackOggComment($page->data)->user_comment_list as $k => $v) {
                        $data[$k] = $v;
                    }
                }
            }
        }

        $this->closeFile();

        return (object)["header" => $header, "data" => $data];
    }

    public static function decodeId3Text(string $text) {
        $bomBom = substr($text, 0, 2);
        $sourceEncoding = null;
        $targetEncoding = "utf-8";

        switch ($bomBom) {
        case "\xff\xfe":
            $sourceEncoding = "utf-16le";
            break;
        case "\xfe\xff":
            $sourceEncoding = "utf-16be";
            break;
        }

        if ($sourceEncoding) {
            $text = str_replace($bomBom, "", $text);
            $text = mb_convert_encoding($text, $targetEncoding, $sourceEncoding);
        } else if (mb_detect_encoding($text)) {
            $text = mb_convert_encoding($text, $targetEncoding);
        }

        return $text;
    }

    public static function isOggCommentPayload (string $data) {
        return strncmp($data, self::OGG_COMMENT_TAG, strlen(self::OGG_COMMENT_TAG)) == 0;
    }

    public static function unpackId3Payload(string $data) {
        $isUtf = ($data && $data[0] == "\x01");
        $offset = ($data && ($data[0] == "\x00" || $isUtf))? 1 : 0;
        $length = (!$isUtf && $data && $data[-1] == "\x00")? -1 : null;

        $data = substr($data, $offset, $length);

        if ($isUtf && substr($data, -2) == "\x00\x00") {
            $data = substr($data, 0, -2);
        }

        return $data;
    }

    public static function unpackId3TextFrame(string $data) {
        return array_map('MetaScraper::decodeId3Text', explode("\x00", $data, 2));
    }

    public static function unpackOggComment(string $data) {
        $sliceOffset = 0;

        $comment = unpack("Cmarker/A6tag/Vvendor_length", $data, $sliceOffset);
        $commentHeaderSize = 11;
        $sliceOffset += $commentHeaderSize;

        $comment["vendor_string"] = substr($data, $sliceOffset, $comment["vendor_length"]);
        $sliceOffset += $comment["vendor_length"];

        $userCommentHeader = unpack("Vuser_comment_list_length", $data, $sliceOffset);
        $userCommentHeaderSize = 4;
        $sliceOffset += $userCommentHeaderSize;

        $userCommentList = [];
        for ($i = 0; $i < $userCommentHeader["user_comment_list_length"]; $i++) {
            $userComment = (object)unpack("Vlength", $data, $sliceOffset);
            $length = $userComment->length;
            $sliceOffset += 4;

            $userComment = substr($data, $sliceOffset, $length);
            $actualLength = strlen($userComment);
            $sliceOffset += $actualLength;

            if ($actualLength != $length) {
                break;
            } else {
                $explodedComment = explode('=', $userComment, 2);
                $userCommentList[$explodedComment[0]] = $explodedComment[1] ?? null;
            }
        }
        $userCommentHeader["user_comment_list"] = $userCommentList;

        return (object)array_merge($comment, $userCommentHeader);
    }
}

class TubeScraper {
    const VIDEO_LIST_QUERY = "?part=id,snippet";
    const VIDEO_LIST_URL = "https://www.googleapis.com/youtube/v3/videos";

    private string $apiKey;

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }

    public function scrapeVideoMeta($entriesOrLinks) {
        if (!is_array($entriesOrLinks)) {
            $entriesOrLinks = [$entriesOrLinks];
        }

        $entriesOrLinks = array_filter(array_map(function($link) {
            $link = is_string($link)? $link : ($link->href ?? null);
            return self::getYouTubeId($link);
        }, $entriesOrLinks));

        $idChunk = implode(",", $entriesOrLinks);
        $urlScraper = new UrlScraper();

        $meta = json_decode($urlScraper->get(self::VIDEO_LIST_URL . self::VIDEO_LIST_QUERY . "&id=$idChunk&key=$this->apiKey"));
        $items = [];

        foreach ($meta->items ?? [] as $item) {
            $items[$item->id] = $item;
        }

        return $items;
    }

    public static function getYouTubeId(string $href) {
        $url = parse_url($href);
        $host = $url["host"] ?? "";
        $query = [];
        $isBigLink = stripos($host, "youtube.com") !== false;
        $isSmallLink = stripos($host, "youtu.be") !== false;

        parse_str($url["query"] ?? "", $query);

        if ($isBigLink) {
            return $query["v"] ?? null;
        } else if ($isSmallLink) {
            return trim($url["path"] ?? "", "/");
        } else {
            return null;
        }
    }
}

class UrlScraper {
    const USER_AGENT = PROJECT_NAME . " URL Scraper";

    private array $httpHeaders;

    public function __construct() {
        $this->httpHeaders = [
            "User-Agent: " . self::USER_AGENT
        ];
    }

    public function get(string $url) {
        $ctx = curl_init();

        curl_setopt($ctx, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ctx, CURLOPT_URL, $url);
        curl_setopt($ctx, CURLOPT_HTTPHEADER, $this->httpHeaders);

        $data = curl_exec($ctx);

        curl_close($ctx);

        return $data;
    }

    public static function getRequestHeaders() {
        $headers = [];

        foreach ($_SERVER as $k => $v) {
            if (strpos($k, "HTTP_") !== 0) {
                continue;
            }

            $headers[$k] = $v;
        }

        return $headers;
    }
}

// </LIB>

// <UTILS>

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

function s(...$args) {
    return htmlentities(implode(" ", $args));
}

function varDump($var) {
    $maxDumpLen = 255;

    if (is_object($var)) {
        $var = get_object_vars($var);
    }

    if (is_array($var)) {
        return array_map('varDump', $var);
    }

    if (is_scalar($var) && !is_string($var)) {
        return $var;
    }

    $var = (string)$var;

    if (!ctype_print($var)) {
        $newStr = "";

        for ($i = 0; $i < strlen($var); $i++) {
            $c = $var[$i];
            $newStr .= ctype_print($c)? $c : sprintf("[%02X]", ord($c));
        }

        $var = $newStr;
    }

    $len = strlen($var);

    if ($len >= $maxDumpLen) {
        $var = substr($var, 0, $maxDumpLen) . ("... (< " . prettySize($len) . " >)");
    }

    return $var;
}

// Page output funcs

function debugEntries($entries, $req) {
    if (!PROJECT_DEBUG) {
        return null;
    }

    $jsonType = "Content-Type: application/json";
    $q = $req->query ?? [];

    if (!empty($q['headers'])) {
        header($jsonType);
        echo json_encode(UrlScraper::getRequestHeaders(), JSON_PRETTY_PRINT);
        exit();
    }

    if (!empty($q['htest'])) {
        header($jsonType);
        echo (new UrlScraper())->get("https://lagg.me/pub?headers=1");
        exit();
    }

    if (!empty($q['debug'])) {
        foreach ($entries as $entryGroup) {
            foreach ($entryGroup as $entry) {
                if (!empty($entry->meta) || !empty($entry->header)) {
                    $entry->header = varDump($entry->header ?? null);
                    $entry->meta = varDump($entry->meta ?? null);
                }
            }
        }

        printf("<script>var entries = %s; console.log(entries)</script>\n", json_encode($entries, JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}

function echoEntries($entries) {
    if ($entries instanceof Exception) {
?>
        <h1 class="http-error"><?=s($entries->getMessage())?></h1>
        <h3 class="http-error-back"><a href="<?=s(ROOT_VIRTUAL)?>">Back</a></h3>
<?php
        return;
    }

    foreach ($entries as $group => $data) {
        if (!$data) { continue; }
        $saneGroup = s($group);
        $saneGroupHref = strtolower($saneGroup);
?>
        <table class="entries">
            <caption id="<?=$saneGroupHref?>"><a href="#<?=$saneGroupHref?>">#</a><?=$saneGroup?></caption>
            <tbody>
<?php
                foreach ($data as $entry) {
                    echoRowHtml($entry);
                }
?>
            </tbody>
        </table>
<?php
    }
}

function echoRowHtml($entry) {
    $artist = $entry->meta["channelName"] ?? "";
    $album = "";
    $name = $entry->name;
    $mtime = prettyDate($entry->mtime ?? $entry->ctime ?? null);

    if (!empty($entry->header)) {
        // Expanded metadata
        $m = array_merge($entry->meta, $entry->meta["TXXX"] ?? []);
        $name = $m["TIT2"] ?? $m["TITLE"] ?? $name;
        $artist = $m["ARTISTSORT"] ?? $m["ALBUMARTISTSORT"] ?? $m["ARTISTS"] ?? $m["TPE1"] ?? $artist;
        $album = $m["TALB"] ?? $m["ALBUM"] ?? $album;
        $mtime = $m["ORIGINALDATE"] ?? $m["TYER"] ?? $m["originalyear"] ?? $mtime;
    }
?>
<tr<?=!empty($entry->slug)? ' id="' . s($entry->slug) . '"' : ''?>>
<?php
    if (!empty($entry->thumb)) {
?>
    <th class="thumb"><img src="<?=s($entry->thumb)?>" alt=""/></th>
<?php
    }
?>
    <th class="name"><a <?=empty($entry->size)? 'target="_blank" ' : ''?>href="<?=s($entry->href)?>"><?=s($name)?></a></th>
    <td class="description">
<?php
    if (!empty($entry->description)) {
?>
        <?=s($entry->description)?>
<?php
    }

    if ($artist) {
        if (!empty($entry->description)) {
?>
            -
<?php
        }
?>
        by <em><?=s($artist)?></em>
<?php
    }

    if ($album) {
?>
        from <em><?=s($album)?></em>
<?php
    }
?>
    </td>
    <td class="mtime"><?=s($mtime)?></td>
    <td class="size"><?=s(prettySize($entry->size ?? null))?></td>
</tr>
<?php
}

// </UTILS>

// <MAIN>

error_reporting(PROJECT_DEBUG? E_ALL : 0);

$entries = null;
$pageTitle = "ls";
$req = null;
$lister = new LsDir(ROOT_REAL, ROOT_VIRTUAL);

try {
    $req = $lister->resolveRequest();
    $pageTitle .= " $req->path/";

    $entries = $lister->assertRequest($req);

    $entries = $lister->scrapeEntries($entries);

    $entries = $lister->sortEntries($entries, [
        "by" => $req->query["sort"] ?? null,
        "dir" => $req->query["dir"] ?? null
    ]);

    $entries = $lister->aggregateEntries($entries);

    debugEntries($entries, $req);
} catch (Exception $ex) {
    $isHttpException = $ex instanceof HttpException;
    $code = $isHttpException? $ex->getCode() : 500;
    $msg = $isHttpException? $ex->getMessage() : "Internal error";
    $entries = $ex;

    if ($code) {
        http_response_code($code);
    }

    if ($ex instanceof RedirectException) {
        header("Location: $msg");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <title><?=s($pageTitle)?></title>
        <link rel="stylesheet" type="text/css" href="/style.css"/>
        <style>
            .entries {
                border-collapse: collapse;
                margin: 2em 1em;
                text-align: left;
            }

            .entries caption {
                font-weight: bold;
                text-align: left;
                margin-left: 1em;
                margin-bottom: .35em;
            }

            .entries tr td, .entries tr th {
                padding: .75em 1.5em;
            }

            .entries .name {
                font-weight: bold;
                font-size: 1.1em;
                padding: 0;
            }

            .entries tr {
                border: 1px solid transparent;
            }
            .entries tr:hover {
                background-color: rgb(34, 35, 48, .93);
                border: 1px solid #007442;
                border-radius: 6px;
            }

            .entries .name a {
                display: block;
                padding: .40em 1em;
                width: 100%;
            }

            .entries .thumb img {
                width: 64px;
            }
        </style>
    </head>
    <body>
    <?=s(echoEntries($entries))?>
    </body>
</html>
<?php

// </MAIN>
?>
