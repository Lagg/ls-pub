<?php
// <CONFIGURATION>

// Path mapping

// If null, <working dir>/.cache is used
const ROOT_CACHE = null;
// If null, <working dir>/data is used
const ROOT_DATA = null;
// If null, <working dir> used
const ROOT_REAL = null;
// Assumed vroot if given which gets mapped to ROOT_REAL
const ROOT_VIRTUAL = null;
// File where link list is stored
const FILE_LINKS = "links.txt";

// API/remote conf

// Tube scraping, if not given disabled
const TUBE_API_KEY = null;

// Project misc

// Whether debug mode / params are supported
const PROJECT_DEBUG = true;
// Name used for titles/user-agent/etc.
const PROJECT_NAME = "Ls'Pub";

// Caching

const CACHE_TTL_META = 300;
const CACHE_TTL_TUBE = 600;

// </CONFIGURATION>

// <LIB>

// Error handling

class LsPubException extends Exception {
}

class HttpException extends LsPubException {
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

class Cache {
    public static $cache = null;

    public static function get(...$args) {
        return self::getCache()->get(...$args);
    }

    public static function getCache() {
        if (!self::$cache) {
            self::$cache = new CacheBase;
        }

        return self::$cache;
    }

    public static function set(...$args) {
        return self::getCache()->set(...$args);
    }
}

class CacheBase extends FileBase {
    const DEFAULT_TTL = 60;

    public function __construct($dir=ROOT_CACHE) {
        parent::__construct($dir);
    }

    public function get(string $key, $default=null) {
        $data = parent::get($key);
        $data = $data? unserialize($data) : $data;

        $now = time();
        $storedAt = $data->ctime ?? null;
        $age = $now - $storedAt;
        $ttl = $data->ttl ?? null;

        if (!$storedAt || !$ttl || ($age >= $ttl)) {
            $data = null;
            $this->unset($key);
        }

        return $data->payload ?? $default;
    }

    public function getDefaultDir() {
        return getcwd() . "/.cache";
    }

    public function set(string $key, $data, int $ttl=self::DEFAULT_TTL) {
        $obj = (object)[
            "ctime" => time(),
            "ttl" => $ttl,
            "payload" => $data
        ];

        return parent::set($key, serialize($obj));
    }
}

class File {
    public static $file = null;

    public static function get(...$args) {
        return self::getFile()->get(...$args);
    }

    public static function getFile() {
        if (!self::$file) {
            self::$file = new FileBase;
        }

        return self::$file;
    }

    public static function set(...$args) {
        return self::getFile()->set(...$args);
    }
}


class FileBase {
    const MAX_KEY_LEN = 60;

    protected $dir;

    public function __construct($dir=ROOT_DATA) {
        $this->dir = $dir ?? $this->getDefaultDir();
    }

    public function get(string $key, $default=null) {
        $path = $this->getKeyPath($key);

        if (is_readable($path)) {
            return file_get_contents($path);
        } else {
            return $default;
        }
    }

    public function getDefaultDir() {
        return getcwd() . "/data";
    }

    public function init() {
        $success = file_exists($this->dir);

        if (!$success) {
            $success = mkdir($this->dir, 0777, true);
        }

        if (!$success) {
            throw new LsPubException("File dir creation error");
        }

        return $success;
    }

    public function set(string $key, string $data) {
        $path = $this->getKeyPath($key);
        $success = null;

        $this->init();

        $fp = fopen($path, "wb");

        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $data);
            fflush($fp);
            flock($fp, LOCK_UN);

            $success = true;
        } else {
            $success = false;
        }

        fclose($fp);

        return $success;
    }

    public function unset(string $key) {
        $path = $this->getKeyPath($key);

        if (file_exists($path)) {
            return unlink($path);
        } else {
            return true;
        }
    }

    protected function getKeyPath(string $key) {
        return "$this->dir/" . $this->sanitizeKey($key);
    }

    protected function sanitizeKey(string $key) {
        if (strlen($key) > self::MAX_KEY_LEN || !ctype_alnum(strtr($key, "-.", "00"))) {
            $key = sha1($key);
        }

        return $key;
    }

}

class LsDir {
    const DEFAULT_TYPE = null;
    const LINKS_TYPE = "url";

    const MAX_DESC_LEN = 140;

    public $root;
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

    public function __construct(string $root, string $virtualRoot=null) {
        $this->root = $root;
        $this->virtualRoot = $virtualRoot ?? $this->root;
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

    public function getEntries() {
        $ls = [];

        if (!$this->root || !is_dir($this->root)) {
            return null;
        }

        $dir = opendir($this->root);

        if (!$dir) {
            return null;
        }

        while (($name = readdir($dir)) !== false) {
            // Basic info/path building
            $entry = (object)[
                "name" => $name,
                "canonical" => "$this->root/$name",
                "href" => "$this->virtualRoot/$name"
            ];

            // Stat data
            $stat = is_readable($entry->canonical)? stat($entry->canonical) : null;
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

    public function scrapeEntries($entries) {
        $metaCkey = "meta-file";
        $tubeCkey = "meta-tube";

        $tube = TUBE_API_KEY? new TubeScraper(TUBE_API_KEY) : null;

        $cfresh = false;
        $fileMeta = Cache::get($metaCkey, []);

        foreach ($entries as $entry) {
            $n = $entry->canonical ?? $entry->name;

            if (empty($fileMeta[$n])) {
                $fileMeta[$n] = ((new MetaScraper($entry))->scrape());
                $cfresh = $cfresh || !!$fileMeta[$n];
            }

            if ($fileMeta[$n]) {
                $entry->header = $fileMeta[$n]->header;
                $entry->meta = $fileMeta[$n]->data;
            }
        }

        if ($cfresh) {
            Cache::set($metaCkey, $fileMeta, CACHE_TTL_META);
        }

        if ($tube) {
            $tubeCkey .= "-" . sha1(implode(TubeScraper::getTubeIds($entries)));
            $tubeMeta = Cache::get($tubeCkey);

            if (is_null($tubeMeta)) {
                $tubeMeta = $tube->scrapeVideoMeta($entries);

                if ($tubeMeta) {
                    Cache::set($tubeCkey, $tubeMeta, CACHE_TTL_TUBE);
                }
            }

            foreach ($entries as $entry) {
                $tubeId = TubeScraper::getTubeId($entry->href ?? null);

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

class LsRequest {
    public string $realRoot;
    public string $virtualRoot;

    // Path sent to server/app slightly sanitized
    public string $path;
    // Path with vroot stripped
    public string $basePath;
    // Filesystem path
    public string $realPath;
    // Query string opts
    public array $query = [];

    private $linkEntries = null;

    public function __construct($realRoot, $virtualRoot) {
        $this->realRoot = '/' . trim($realRoot, '/');
        $this->virtualRoot = '/' . trim($virtualRoot, '/');

        $this->resolveRequest();
    }

    public function assertRequest() {
        $realPathMissing = empty($this->realPath);
        $realPathReadable = !$realPathMissing && is_readable($this->realPath);

        if ($realPathMissing) {
            $entry = $this->getLinkEntries()[trim($this->basePath, '/')] ?? null;

            if ($entry) {
                throw new RedirectException($entry->href);
            } else {
                throw new HttpException("Not found", 404);
            }
        } else if (strpos($this->realPath, $this->realRoot) !== 0 || !$realPathReadable) {
            throw new HttpException("Forbidden", 403);
        } else {
            return true;
        }
    }

    public function getLinkEntries() {
        if ($this->linkEntries) {
            return $this->linkEntries;
        }

        $realPath = ($this->realPath? $this->realPath : $this->realRoot) . "/" . FILE_LINKS;

        $stream = is_readable($realPath)? fopen($realPath, "r") : null;
        $entries = [];

        if (!$stream) {
            return $entries;
        }

        while (($line = fgetcsv($stream)) !== false) {
            $line = array_map(function($field) {
                return trim((string)$field);
            }, $line);

            $link = $line[0] ?? "";
            $name = $line[1] ?? "";
            $desc = $line[2] ?? "";
            $slug = $line[3] ?? "l" . crc32($link);

            if (!$link || $link[0] == '#') {
                continue;
            }

            $entries[$slug] = (object)[
                "type" => LsDir::LINKS_TYPE,
                "href" => $link,
                "name" => $name,
                "description" => $desc,
                "slug" => $slug,
            ];
        }

        fclose($stream);

        return ($this->linkEntries = $entries);
    }

    public function resolveRequest() {
        $reqUri = $_SERVER["REQUEST_URI"];
        $workingUrl = parse_url($reqUri);

        parse_str($workingUrl["query"] ?? "", $this->query);

        $this->path = "/" . trim($workingUrl["path"] ?? "", "/");
        $this->basePath = $this->path;

        // Strip vroot from req path
        if (substr($this->basePath, 0, strlen($this->virtualRoot)) == $this->virtualRoot) {
            $this->basePath = substr($this->basePath, strlen($this->virtualRoot));
        }

        // Map real root to vroot-less req path
        $this->realPath = realpath($this->realRoot . $this->basePath);

        return $this->assertRequest();
    }
}

class MetaScraper {
    const ID3_APIC_TAG = "APIC";
    const ID3_TEXT_TAG = "TXXX";

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

    public static $mimeExts = [
        "image/jpeg" => "jpg",
        "image/png" => "png"
    ];

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

    public function parseId3Frame($detachApics=true) {
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
        if ($frame->id == self::ID3_TEXT_TAG) {
            $frame->data = self::unpackId3TextFrame($frame->data);
        }

        if ($frame->id == self::ID3_APIC_TAG) {
            $frame->data = self::unpackId3ApicFrame($frame->data);

            if ($detachApics) {
                $apicName = (sha1($frame->data->data) . "." . $frame->data->ext);
                File::set($apicName, $frame->data->data);
                $frame->data->data = $apicName;
            }
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
        if (!$this->isScrapable()) {
            return null;
        }

        $meta = (object)[
            "header" => null,
            "data" => []
        ];

        $this->openFile();

        if (($meta->header = $this->parseId3Header())) {
            while (($frame = $this->parseId3Frame())) {
                $id = is_array($frame->data)? $frame->data[0] : $frame->id;
                $val = is_array($frame->data)? $frame->data[1] ?? null : $frame->data;

                if (isset($meta->data[$id])) {
                    if (!is_array($meta->data[$id])) {
                        $meta->data[$id] = [$meta->data[$id]];
                    }

                    $meta->data[$id][] = $val;
                } else {
                    $meta->data[$id] = $val;
                }
            }
        }

        if (!$meta->header) {
            $this->rewindFile();

            while(($page = $this->parseOggPage()) && $page->page < self::OGG_PAGE_MAX) {
                if ($page->page == 0) {
                    $meta->header = $page;
                }

                if (self::isOggCommentPayload($page->data)) {
                    foreach (self::unpackOggComment($page->data)->user_comment_list as $k => $v) {
                        $meta->data[$k] = $v;
                    }
                }
            }
        }

        $this->closeFile();

        return $meta;
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

    public static function unpackId3ApicFrame(string $data) {
        $offset = 0;
        $term = strpos($data, "\0", $offset);

        if ($term === false) {
            return null;
        }

        $mime = substr($data, $offset, $term - $offset);
        $mimeExt = self::$mimeExts[$mime] ?? "bin";
        $offset += strlen($mime) + 1;

        $type = ord($data[$offset]);
        $offset++;

        $term = strpos($data, "\0", $offset);

        if ($term === false) {
            return null;
        }

        $desc = substr($data, $offset, $term - $offset);
        $offset += (strlen($desc) + 1);

        $data = substr($data, $offset);

        return (object)[
            "ext" => $mimeExt,
            "mime" => $mime,
            "type" => $type,
            "desc" => $desc,
            "data" => $data
        ];
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
        $items = [];

        $entriesOrLinks = self::getTubeIds($entriesOrLinks);

        if (empty($entriesOrLinks)) {
            return $items;
        }

        $idChunk = implode(",", $entriesOrLinks);
        $urlScraper = new UrlScraper();
        $requestUrl = self::VIDEO_LIST_URL . self::VIDEO_LIST_QUERY . "&id=$idChunk&key=$this->apiKey";

        $meta = json_decode($urlScraper->get($requestUrl));

        foreach ($meta->items ?? [] as $item) {
            $items[$item->id] = $item;
        }

        return $items;
    }

    public static function getTubeId(string $href) {
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

    public static function getTubeIds($entriesOrLinks) {
        if (!is_array($entriesOrLinks)) {
            $entriesOrLinks = [$entriesOrLinks];
        }

        $entriesOrLinks = array_filter(array_map(function($link) {
            $link = is_string($link)? $link : ($link->href ?? null);
            return self::getTubeId($link);
        }, $entriesOrLinks));

        sort($entriesOrLinks);

        return $entriesOrLinks;
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
        header($jsonType);
        echo json_encode(varDump($entries), JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit();
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
        $m = array_merge($entry->meta, $entry->meta[MetaScraper::ID3_TEXT_TAG] ?? []);
        $name = $m["TIT2"] ?? $m["TITLE"] ?? $name;
        $artist = $m["ARTISTSORT"] ?? $m["ALBUMARTISTSORT"] ?? $m["ARTISTS"] ?? $m["TPE1"] ?? $artist;
        $album = $m["TALB"] ?? $m["ALBUM"] ?? $album;
        $mtime = $m["ORIGINALDATE"] ?? $m["TYER"] ?? $m["originalyear"] ?? $mtime;
    }

    $apicHref = $m[MetaScraper::ID3_APIC_TAG]->data ?? null;
    if (empty($entry->thumb) && $apicHref) {
        // TODO
        $entry->thumb = ROOT_VIRTUAL . "data/$apicHref";
    }
?>
<tr<?=!empty($entry->slug)? ' id="' . s($entry->slug) . '"' : ''?>>
    <th class="thumb">
<?php if (!empty($entry->thumb)) { ?> <img src="<?=s($entry->thumb)?>" alt=""/> <?php } ?>
    </th>
    <th class="name"><a <?=$entry->type == LsDir::LINKS_TYPE? 'target="_blank" ' : ''?>href="<?=s($entry->href)?>"><?=s($name)?></a></th>
    <td class="description">
        <?=s($entry->description ?? "")?>

        <?=s(!empty($entry->description) && (!!$artist || !!$album)? "-" : "")?>

        <?=$artist? ("by <em>" . s($artist) . "</em>") : ""?>
        <?=$album? ("from <em>" . s($album) . "</em>") : ""?>
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
$lister = null;

try {
    $req = new LsRequest(ROOT_REAL, ROOT_VIRTUAL);
    $lister = new LsDir($req->realPath, $req->path);

    $pageTitle .= " $req->path/";

    $entries = array_merge($req->getLinkEntries(), $lister->getEntries());

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
