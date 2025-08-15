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

const PROJECT_URL = "https://lagg.me" . ROOT_VIRTUAL;

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

class Entries {
    const DEFAULT_TYPE = null;
    const LINKS_TYPE = "url";

    const MAX_DESC_LEN = 140;

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


    public function aggregate($entries) {
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

    public function create(string $realPath, string $virtualRoot) {
        // Basic info/path building
        $entry = (object)[
            "name" => basename($realPath),
            "canonical" => $realPath,
        ];

        // Stat data
        $stat = is_readable($entry->canonical)? stat($entry->canonical) : null;
        foreach (["size", "atime", "ctime", "mtime"] as $k) {
            $entry->{$k} = $stat[$k] ?? null;
        }

        // Basic typing to know when to header-scrape
        $lastDot = strrpos($entry->name, ".");
        $entry->ext = $lastDot? trim(strtolower(substr($entry->name, $lastDot + 1))) : null;
        $entry->type = null;

        foreach (self::$extTypes as $type => $exts) {
            if (in_array($entry->ext, $exts)) {
                $entry->type = $type;
                break;
            }
        }

        $entry->href = "$virtualRoot/$entry->name";

        return $entry;
    }

    public function get(string $realRoot, string $virtualRoot) {
        $ls = [];

        if (!$realRoot || !is_dir($realRoot)) {
            return null;
        }

        $dir = opendir($realRoot);

        if (!$dir) {
            return null;
        }

        while (($name = readdir($dir)) !== false) {
            $entry = $this->create("$realRoot/$name", $virtualRoot);
            $ls[$entry->name] = $entry;
        }

        closedir($dir);

        return $ls;
    }

    public function scrape($entries) {
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

    public function sort($entries, $opts=[]) {
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
    const DIR_INDEX_NAME = "index";

    const OUT_ATOM = "atom";
    const OUT_HTML = "html";
    const OUT_JSON = "json";
    const OUT_RSS = "rss";
    const OUT_TEXT = "txt";

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

    public string $outputFormat = self::OUT_HTML;
    public bool $outputFormatSane = false;

    private $linkEntries = null;

    public static $outputExts = [self::OUT_ATOM, self::OUT_HTML, self::OUT_JSON, self::OUT_RSS, self::OUT_TEXT];

    public function __construct($realRoot, $virtualRoot) {
        $this->realRoot = pnorm($realRoot);
        $this->virtualRoot = pnorm($virtualRoot);

        $this->resolve();
    }

    public function assert() {
        $realPathMissing = empty($this->realPath);
        $realPathDir = !$realPathMissing && is_dir($this->realPath);
        $realPathReadable = !$realPathMissing && is_readable($this->realPath);

        if ($realPathMissing) {
            $entry = $this->getLinkEntries()[pnorm($this->basePath, null)] ?? null;

            if ($entry) {
                throw new RedirectException($entry->href);
            } else {
                throw new HttpException("Not found", 404);
            }
        } else if (strpos($this->realPath, $this->realRoot) !== 0 || !$realPathReadable) {
            throw new HttpException("Forbidden", 403);
        } else if (!$this->outputFormatSane) {
            throw new HttpException("Bad Request", 400);
        } else {
            return true;
        }
    }

    public function getLinkEntries() {
        if ($this->linkEntries) {
            return $this->linkEntries;
        }

        $realPath = (empty($this->realPath)? $this->realRoot : $this->realPath) . "/" . FILE_LINKS;

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
                "type" => Entries::LINKS_TYPE,
                "href" => $link,
                "name" => $name,
                "description" => $desc,
                "slug" => $slug,
            ];
        }

        fclose($stream);

        return ($this->linkEntries = $entries);
    }

    public function resolve() {
        $reqUri = $_SERVER["REQUEST_URI"];
        $workingUrl = parse_url($reqUri);

        parse_str($workingUrl["query"] ?? "", $this->query);

        $this->expandPath($workingUrl["path"] ?? "");
    }

    public function stripVirtualRoot(string $path) {
        $vlen = strlen($this->virtualRoot);
        if (substr($path, 0, $vlen) == $this->virtualRoot) {
            return substr($path, $vlen);
        } else {
            return $path;
        }
    }

    protected function expandPath(string $path) {
        // Start with path minus vroot
        $this->path = pnorm($path);
        $this->basePath = pnorm($this->stripVirtualRoot($this->path));

        // Attempt to see if absolute filename exists
        if (($this->realPath = realpath($this->realRoot . $this->basePath))) {
            $this->outputFormatSane = true;
            return;
        }

        // If not try again after hint parse
        $extDot = strrpos($this->basePath, '.');
        $ext = $extDot !== false? strtolower(substr($this->basePath, $extDot + 1)) : null;

        if (!in_array($ext, LsRequest::$outputExts)) {
            return;
        } else {
            $this->outputFormat = $ext;
            $this->basePath = substr($this->basePath, 0, $extDot);
        }

        // Sanity check for index hints that actually make sense
        $isIndex = (basename($this->basePath) == self::DIR_INDEX_NAME);

        if ($isIndex) {
            $this->basePath = substr($this->basePath, 0, -(strlen(self::DIR_INDEX_NAME) + 1));
        }

        $this->path = $this->virtualRoot . $this->basePath;
        $this->realPath = realpath($this->realRoot . $this->basePath);
        $isDir = $this->realPath && is_dir($this->realPath);

        $this->outputFormatSane = !$isDir || ($isDir && $isIndex);
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
            return pnorm($url["path"] ?? "", null);
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

class LsOutput {
    public static $censoredDumpKeys = ["[canonical]"];

    protected $stdout;
    protected string $title = "";

    public function dumpEntry($entry) {
        $flattened = [];
        $this->flattenVar($flattened, $entry);

        foreach ($flattened as $k => $v) {
            fputcsv($this->stdout, [$k, $v]);
        }
    }

    public function flush() {
        fclose($this->stdout);
    }

    public function getContentType() {
        return "text/plain";
    }

    public function init() {
        $this->stdout = fopen("php://output", "wb");
    }

    public function setTitle(string $title) {
        $this->title = $title;
    }

    public function writeEntries($entries) {
        foreach ($entries as $group => $data) {
            $this->writeEntryGroup($group, $data);
        }
    }

    public function writeEntry($entry) {
        $c = self::condenseEntry($entry);
        $name = $c->name ?? $entry->name;
        $desc = $entry->description ?? "";
        $thumb = $c->thumb ?? "";

        if ($c->artist) {
            $desc .= " by $c->artist";
        }

        if ($c->album) {
            $desc .= " from $c->album";
        }

        $fields = array_map(function($f) {
            return trim((string)$f);
        }, [$entry->type, $entry->href, $name, $desc, $thumb]);

        fputcsv($this->stdout, $fields);
    }

    public function writeEntryGroup(string $group, array $data) {
        if (empty($data)) {
            return;
        }

        $this->writeEntryGroupHeader($group);

        foreach ($data as $entry) {
            $this->writeEntry($entry);
        }

        $this->writeEntryGroupFooter($group);
    }

    public function writeEntryGroupFooter(string $group) {
        fputs($this->stdout, "## /$group\n");
    }

    public function writeEntryGroupHeader(string $group) {
        fputs($this->stdout, "## $group\n");
    }

    public function writeException(Exception $ex) {
        fputs($this->stdout, "## ERROR: " . $ex->getMessage() . "\n");
    }

    public function writeFooter() {
        fputs($this->stdout, "# /$this->title\n");
    }

    public function writeHeader() {
        fputs($this->stdout, "# $this->title\n");
    }

    public static function condenseEntry($entry) {
        $m = array_merge($entry->meta ?? [], $entry->meta[MetaScraper::ID3_TEXT_TAG] ?? []);
        $apicHref = $m[MetaScraper::ID3_APIC_TAG]->data ?? null;

        $name = $entry->name;
        $artist = $entry->meta["channelName"] ?? "";
        $album = "";
        $mtime = prettyDate($entry->mtime ?? $entry->ctime ?? null);
        $thumb = $entry->thumb ?? null;

        // Expanded metadata
        $name = $m["TIT2"] ?? $m["TITLE"] ?? $name;
        $artist = $m["ARTISTSORT"] ?? $m["ALBUMARTISTSORT"] ?? $m["ARTISTS"] ?? $m["TPE1"] ?? $artist;
        $album = $m["TALB"] ?? $m["ALBUM"] ?? $album;
        $mtime = $m["ORIGINALDATE"] ?? $m["TYER"] ?? $m["originalyear"] ?? $mtime;
        $thumb = $apicHref? (ROOT_VIRTUAL . "data/$apicHref") : $thumb; // TODO

        return (object)[
            "name" => $name,
            "artist" => $artist,
            "album" => $album,
            "mtime" => $mtime,
            "thumb" => $thumb
        ];
    }

    protected function flattenVar(&$flattened, $var, $keyPrefix="") {
        if (is_null($var) || is_scalar($var)) {
            $flattened[$keyPrefix] = in_array($keyPrefix, self::$censoredDumpKeys)? "<CENSORED>" : varDump($var);
            return;
        }

        if (is_object($var)) {
            $var = get_object_vars($var);
        }

        foreach ($var as $k => $v) {
            $this->flattenVar($flattened, $v, $keyPrefix . ($keyPrefix? "." : "") . "[$k]");
        }
    }
}

class OutputAtom extends LsOutput {
    public function dumpEntry($entry) {
        $thumb = $ce->thumb ?? null;
        $flattened = [];
        $this->flattenVar($flattened, $entry);
?>
    <entry>
        <title><?=s($entry->name ?? "")?></title>
        <updated><?=date(DATE_ATOM, $entry->mtime ?? 0)?></updated>
<?php
        if ($thumb) {
?>
        <link rel="enclosure" href="<?=s($thumb)?>"/>
<?php
        }
?>
        <content type="xhtml">
<?php
        foreach ($flattened as $k => $v) {
            echo s($k) . " = " . s($v) . "\n<br/>";
        }
?>
        </content>
    </entry>
<?php
    }

    public function getContentType() {
        return "application/xml";
    }

    public function writeEntry($entry) {
        $ce = self::condenseEntry($entry);
        $desc = $entry->description ?? "";
        $href = $entry->href;
        $isExternalLink = $entry->type == Entries::LINKS_TYPE;
        $size = prettySize($entry->size ?? null);
        $mtime = $entry->mtime ?? time();
        $id = sha1($entry->slug ?? null . $ce->name . PROJECT_URL . $size);
?>
    <entry>
        <title><?=s($ce->name)?></title>
        <link href="<?=s($href)?>"/>
        <id>urn:sha1:<?=s($id)?></id>
        <updated><?=date(DATE_ATOM, $mtime)?></updated>
<?php
        if ($ce->thumb) {
?>
        <link rel="enclosure" href="<?=s($ce->thumb)?>"/>
<?php
        }
?>
        <summary>
            <?=htmlentities($desc, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1)?>

            <?=s($desc && ($ce->artist || $ce->album)? "-" : "")?>

            <?=$ce->artist? ("by " . s($ce->artist)) : ""?>
            <?=$ce->album? ("from " . s($ce->album)) : ""?>
        </summary>
    </entry>
<?php
    }

    public function writeEntryGroupFooter(string $group) {
?>
    <!-- /<?=s($group)?> -->
<?php
    }

    public function writeEntryGroupHeader(string $group) {
?>
    <!-- <?=s($group)?> -->
<?php
    }

    public function writeException(Exception $ex) {
?>
        <pre class="http-error"><?=s($ex->getTraceAsString())?></pre>
<?php
    }

    public function writeHeader() {
        $updated = time();
        $id = sha1(PROJECT_NAME . PROJECT_URL);
?>
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
    <title><?=s($this->title)?></title>
    <link href="<?=s(PROJECT_URL)?>"/>
    <updated><?=date(DATE_ATOM, $updated)?></updated>
    <author><name><?=s(PROJECT_NAME)?></name></author>
    <id>urn:sha1:<?=s($id)?></id>
<?php
    }

    public function writeFooter() {
?>
</feed>
<?php
    }
}

class OutputHtml extends LsOutput {
    public function dumpEntry($entry) {
        $flattened = [];
        $this->flattenVar($flattened, $entry);
?>
<table class="entries">
    <tbody>
<?php
        foreach ($flattened as $k => $v) {
?>
        <tr><th><?=s($k)?><td><code><?=s($v)?></code></td></tr>
<?php
        }
?>
    </tbody>
</table>
<?php
    }

    public function getContentType() {
        return null;
    }

    public function writeEntry($entry) {
        $ce = self::condenseEntry($entry);
        $desc = $entry->description ?? "";
        $href = $entry->href;
        $id = $entry->slug ?? null;
        $isExternalLink = $entry->type == Entries::LINKS_TYPE;
        $size = prettySize($entry->size ?? null)
?>
    <tr<?=$id? (' id="' . s($id) . '"') : ''?>>
        <th class="thumb">
<?php
            if ($ce->thumb) {
?>
            <img src="<?=s($ce->thumb)?>" alt=""/>
<?php
            }
?>
        </th>
        <th class="name"><a <?=$isExternalLink? 'target="_blank" ' : ''?>href="<?=s($href)?>"><?=s($ce->name)?></a></th>
        <td class="description">
            <?=s($desc)?>

            <?=s($desc && ($ce->artist || $ce->album)? "-" : "")?>

            <?=$ce->artist? ("by <em>" . s($ce->artist) . "</em>") : ""?>
            <?=$ce->album? ("from <em>" . s($ce->album) . "</em>") : ""?>
        </td>
        <td class="mtime"><?=s($ce->mtime)?></td>
        <td class="size"><?=s($size)?></td>
    </tr>
<?php
    }

    public function writeEntryGroupFooter(string $group) {
?>
            </tbody>
        </table>
<?php
    }

    public function writeEntryGroupHeader(string $group) {
        $saneGroup = s($group);
        $saneGroupHref = strtolower($saneGroup);
?>
        <table class="entries">
            <caption id="<?=$saneGroupHref?>"><a href="#<?=$saneGroupHref?>">#</a><?=$saneGroup?></caption>
            <tbody>
<?php
    }

    public function writeException(Exception $ex) {
?>
        <h1 class="http-error"><?=s($ex->getMessage())?></h1>
        <h3 class="http-error-back"><a href="<?=s(ROOT_VIRTUAL)?>">Back</a></h3>
<?php
    }

    public function writeHeader() {
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <title><?=s($this->title)?></title>
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
<?php
    }

    public function writeFooter() {
?>
    </body>
</html>
<?php
    }
}

class OutputJson extends LsOutput {
    const JS_FLAGS = JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR;

    private $obj = [];
    private $errors = [];

    public function dumpEntry($entry) {
        $flattened = [];
        $this->flattenVar($flattened, $entry);
        echo json_encode($flattened, self::JS_FLAGS);
    }

    public function flush() {
        if (!empty($this->obj) || !empty($this->errors)) {
            echo json_encode(array_filter([
                "entries" => $this->obj,
                "errors" => $this->errors
            ]), self::JS_FLAGS);
        }

        $this->obj = [];
        $this->errors = [];
    }

    public function getContentType() {
        return "application/json";
    }

    public function init() {
        $this->errors = [];
        $this->obj = [];
    }

    public function writeEntry($entry) {
        $c = self::condenseEntry($entry);
        $this->obj[] = $c;
    }

    public function writeEntryGroupFooter(string $group) {
    }

    public function writeEntryGroupHeader(string $group) {
    }

    public function writeException(Exception $ex) {
        $this->errors[] = $ex->getTrace();
    }

    public function writeFooter() {
    }

    public function writeHeader() {
    }
}

// </UTILS>

// <MAIN>

class Main {
    private ?Entries $dir = null;
    private ?LsOutput $out = null;

    public function getEntries(LsRequest $req) {
        $entries = array_merge($req->getLinkEntries(), $this->dir->get($req->realPath, $req->path));

        $entries = $this->dir->scrape($entries);

        $entries = $this->dir->sort($entries, [
            "by" => $req->query["sort"] ?? null,
            "dir" => $req->query["dir"] ?? null
        ]);

        $entries = $this->dir->aggregate($entries);

        return $entries;
    }

    public function getEntry(LsRequest $req) {
        $entry = $this->dir->create($req->realPath, dirname($req->path));
        $entry = $this->dir->scrape([$entry])[0] ?? null;

        return $entry;
    }

    public function handleException(Exception $ex) {
        $isHttpException = $ex instanceof HttpException;
        $code = $isHttpException? $ex->getCode() : 500;
        $msg = $isHttpException? $ex->getMessage() : "Internal error";

        if ($this->out) {
            $this->out->writeException($ex);
        } else {
            echo s($ex->getMessage());
        }

        if ($code) {
            http_response_code($code);
        }

        if ($ex instanceof RedirectException) {
            header("Location: $msg");
        }
    }

    public function init() {
        error_reporting(PROJECT_DEBUG? E_ALL : 0);

        $this->dir = new Entries;
    }

    public function run() {
        $req = null;

        try {
            $req = new LsRequest(ROOT_REAL, ROOT_VIRTUAL);

            switch ($req->outputFormat) {
            case LsRequest::OUT_ATOM:
                $this->out = new OutputAtom;
                break;
            case LsRequest::OUT_JSON:
                $this->out = new OutputJson;
                break;
            case LsRequest::OUT_HTML:
                $this->out = new OutputHtml;
                break;
            case LsRequest::OUT_TEXT:
                $this->out = new LsOutput;
                break;
            default:
                throw new HttpException("Unsupported", 415);
                break;
            }

            $this->out->init();

            $ctype = $this->out->getContentType();

            if ($ctype) {
                header("Content-Type: $ctype");
            }
        } catch (Exception $ex) {
            $this->handleException($ex);
            return;
        }

        $this->out->setTitle("ls $req->path");
        $this->out->writeHeader();

        try {
            $req->assert();

            if (is_dir($req->realPath)) {
                $this->out->writeEntries($this->getEntries($req));
            } else {
                $this->out->dumpEntry($this->getEntry($req));
            }
        } catch (Exception $ex) {
            $this->handleException($ex);
        }

        $this->out->writeFooter();
        $this->out->flush();
    }
}

$ls = new Main;

$ls->init();

$ls->run();

// </MAIN>
?>
