<?php namespace LsPub;

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

    private $filename;
    private $stream;

    private $chunk;
    private $chunkSize;

    public static $mimeExts = [
        "image/jpeg" => "jpg",
        "image/png" => "png"
    ];

    public function __construct($filename) {
        $this->filename = $filename;
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
        return !empty($this->filename) && is_file($this->filename) && is_readable($this->filename);
    }

    public function openFile() {
        $this->stream = fopen($this->filename, "r");
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
        $frame->data = self::unpackId3Payload($this->readChunk($frame->size));

        // Per spec saying it's always a "desc string" followed by null followed by real string
        if ($frame->id == self::ID3_TEXT_TAG) {
            $frame->data = self::unpackId3TextFrame($frame->data, true);
        } else if ($frame->id == self::ID3_APIC_TAG) {
            $frame->data = self::unpackId3ApicFrame($frame->data);

            if ($detachApics) {
                $apicName = (sha1($frame->data->data) . "." . $frame->data->ext);
                File::set($apicName, $frame->data->data);
                $frame->data->data = $apicName;
            }
        } else {
            $frame->data = self::decodeId3Text($frame->data);
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

    public static function unpackId3TextFrame(string $data, bool $decode=false) {
        $data = explode("\x00", $data, 2);
        return $decode? array_map('LsPub\MetaScraper::decodeId3Text', $data) : $data;
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

class PageScraper {
    public $page;
    public $url;

    public function __construct($pageUrl=null) {
        $this->url = $pageUrl instanceof Url? $pageUrl : new Url(trim($pageUrl, '/'));
    }

    public function getHrefPermalink(string $href) {
        $urlAbs = $this->url->url;
        $href = trim($href, '/');
        $urlPath = parse_url($urlAbs, PHP_URL_PATH);
        $urlPathPos = strpos($urlAbs, $urlPath);
        $hostPart = trim(($urlPath && $urlPathPos)? substr($urlAbs, $urlPathPos) : $urlAbs);
        $phref = (object)parse_url($href);

        if (empty($phref->scheme) || empty($phref->host)) {
            return "$hostPart/$href";
        } else {
            return $href;
        }
    }

    public function scrape() {
        $dom = new \DOMDocument;
        $page = $this->getPage();
        $out = [];

        if (preg_match('/^ *< *! *doctype *html/i', $page)) {
            $dom->loadHTML($page);
            $out = $this->scrapeHtmlRoot($dom);
        } else if (preg_match('/^ *< *\? *xml/i', $page)) {
            $dom->loadXML($page);
            $out = $this->scrapeFeedRoot($dom);
        } else {
            throw new LsPubException("Unscrapable page");
        }

        $out["name"] = $out["name"] ?? null;
        $out["links"] = $out["links"] ?? null;
        $out["entries"] = $out["entries"] ?? null;
        $out["href"] = $this->url->urlInfo["url"];
        $out["root"] = $dom->documentElement->tagName;
        $out["syndication"] = $out["syndication"] ?? null;

        return (object)$out;
    }

    private function getPage() {
        if (!$this->page && $this->url) {
            $this->page = $this->url->get();
        } else if (!$this->page && !$this->url) {
            throw new LsPubException("Need either URL or page data to scrape");
        }

        return $this->page;
    }

    private function scrapeAtomEntries($dom) {
        $entries = [];

        foreach ($dom->getElementsByTagName("entry") as $entry) {
            $title = $entry->getElementsByTagName("title")[0]->textContent ?? null;
            $href = $entry->getElementsByTagName("link")[0] ?? null;
            $href = $href? $href->getAttribute("href") : null;
            $id = $entry->getElementsByTagName("id")[0]->textContent ?? null;
            $updatedAt = $entry->getElementsByTagName("updated")[0]->textContent ?? null;
            $summary = ($entry->getElementsByTagName("summary")[0] ?? $entry->getElementsByTagName("content")[0])->nodeValue ?? null;

            $updatedAt = $updatedAt? \DateTime::createFromFormat(\DateTime::ATOM, $updatedAt) : $updatedAt;

            $entries[] = [
                "name" => $title,
                "href" => $this->getHrefPermalink($href),
                "id" => $id,
                "mtime" => $updatedAt? $updatedAt->getTimestamp() : null,
                "description" => $summary
            ];
        }

        return $entries;
    }

    private function scrapeFeedRoot($dom) {
        $title = $dom->getElementsByTagName("title")[0]->textContent ?? null;
        $entries = [];

        switch ($dom->documentElement->nodeName) {
        case "feed":
            $entries = $this->scrapeAtomEntries($dom);
            break;
        case "rss":
            $entries = $this->scrapeRssEntries($dom);
            break;
        }

        return [
            "name" => $title,
            "entries" => $entries
        ];
    }

    private function scrapeHtmlRoot($dom) {
        $links = [];
        $head = $dom->getElementsByTagName("head")[0] ?? null;
        $title = $head? ($head->getElementsByTagName("title")[0]->textContent ?? null) : null;
        $syndication = null;

        foreach ($head? $head->getElementsByTagName("link") : [] as $link) {
            $href = $link->getAttribute("href");
            $rel = $link->getAttribute("rel");
            $type = $link->getAttribute("type");

            $link = [$this->getHrefPermalink($href), $rel, $type];

            if (!$syndication && $rel == "alternate" && substr($type, -3) == "xml") {
                $syndication = $link;
            }

            $links[] = $link;
        }

        $entry = [
            "name" => $title,
            "links" => $links,
            "syndication" => $syndication
        ];

        return $entry;
    }

    private function scrapeRssEntries($dom) {
        $entries = [];

        foreach ($dom->getElementsByTagName("item") as $entry) {
            $title = $entry->getElementsByTagName("title")[0]->textContent ?? null;
            $href = $entry->getElementsByTagName("link")[0]->textContent ?? null;
            $id = $entry->getElementsByTagName("guid")[0]->textContent ?? null;
            $updatedAt = $entry->getElementsByTagName("pubDate")[0]->textContent ?? null;
            $description = $entry->getElementsByTagName("description")[0]->nodeValue ?? null;

            $updatedAt = $updatedAt? \DateTime::createFromFormat(\DateTime::RSS, $updatedAt) : $updatedAt;

            $entries[] = [
                "name" => $title,
                "href" => $href,
                "id" => $id,
                "mtime" => $updatedAt? $updatedAt->getTimestamp() : null,
                "description" => $description
            ];
        }

        return $entries;
    }
}

class TubeScraper {
    const VIDEO_LIST_QUERY = "?part=id,snippet";
    const VIDEO_LIST_URL = "https://www.googleapis.com/youtube/v3/videos";

    private string $apiKey;

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }

    public function scrapeVideos($videoIds) {
        $videoIds = implode(",", $videoIds);
        $requestUrl = self::VIDEO_LIST_URL . self::VIDEO_LIST_QUERY . "&id=$videoIds&key=$this->apiKey";
        $url = new Url($requestUrl);

        $meta = json_decode($url->get());

        foreach ($meta->items ?? [] as $item) {
            $items[$item->id] = $item;
        }

        return $items;
    }

    public static function getTubeId($href) {
        $href = (string)$href;

        if (empty($href)) {
            return null;
        }

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
        $ids = [];

        if (!is_array($entriesOrLinks)) {
            $entriesOrLinks = [$entriesOrLinks];
        }

        foreach ($entriesOrLinks as $entry) {
            $href = is_string($entry)? $entry : ($entry->href ?? null);

            if (!$href) {
                continue;
            } else {
                $ids[] = self::getTubeId($href);
            }
        }

        sort ($ids);

        return $ids;
    }
}

?>
