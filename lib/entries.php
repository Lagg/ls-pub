<?php namespace LsPub;

class Entries {
    const DEFAULT_TYPE = 0;

    const FILE_TYPE = 1;
    const DIR_TYPE = 2;
    const LINKS_TYPE = 3;

    const AUDIO_STYPE = 4;
    const IMAGE_STYPE = 5;
    const VIDEO_STYPE = 6;

    const MAX_DESC_LEN = 140;

    public array $entries;

    public static $stypeExts = [
        self::AUDIO_STYPE => ["mp3", "ogg", "flac"],
        self::IMAGE_STYPE => ["jpg", "png", "bmp"],
        self::VIDEO_STYPE => ["mp4", "ogv", "avi"]
    ];

    public static $typeLabels = [
        self::AUDIO_STYPE => "Music",
        self::IMAGE_STYPE => "Images",
        self::VIDEO_STYPE => "Videos",
        self::LINKS_TYPE => "Links",
        self::FILE_TYPE => "Files",
        self::DIR_TYPE => "Dirs"
    ];

    public static $sortableFields = ["name", "type", "stype", "description", "size", "ctime", "atime", "mtime"];

    public function __construct(array $entries) {
        $this->entries = [];
        $i = 0;

        foreach ($entries as $entry) {
            $k = $entry->name;

            if (isset($this->entries[$k])) {
                $k .= '-' . ++$i;
            }

            $this->entries[$k] = $entry;
        }
    }

    public function aggregate() {
        $aggregated = array_fill_keys(self::$typeLabels, []);

        foreach ($this->entries as $entry) {
            $key = self::$typeLabels[$entry->stype] ?? self::$typeLabels[$entry->type] ?? "Misc";

            if (!isset($aggregated[$key])) {
                $aggregated[$key] = [];
            }

            $aggregated[$key][] = $entry;
        }

        return array_filter($aggregated);
    }

    public function scrape() {
        $metaCkey = "meta-file";
        $tubeCkey = "meta-tube";

        $apiKey = Config::get("tube.key");
        $cacheMetaTtl = Config::get("cache.ttl.meta");
        $cacheTubeTtl = Config::get("cache.ttl.tube");

        $tube = $apiKey? new TubeScraper($apiKey) : null;

        $cfresh = false;
        $fileMeta = Cache::get($metaCkey, []);

        foreach ($this->entries as $entry) {
            $n = $entry->canonical ?? null;

            if (!$n) {
                continue;
            }

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
            Cache::set($metaCkey, $fileMeta, $cacheMetaTtl);
        }

        if ($tube) {
            $tubeCkey .= implode(TubeScraper::getTubeIds($this->entries));
            $tubeMeta = Cache::get($tubeCkey);

            if (is_null($tubeMeta)) {
                $tubeMeta = $tube->scrapeVideoMeta($this->entries);

                if ($tubeMeta) {
                    Cache::set($tubeCkey, $tubeMeta, $cacheTubeTtl);
                }
            }

            foreach ($this->entries as $entry) {
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
                    "tags" => $snippet->tags ?? []
                ];

                $entry->ctime = strtotime($snippet->publishedAt);
                $entry->mtime = $entry->ctime;
                $entry->description = $snippet->description;
                $entry->name = $snippet->title;
                $entry->thumb = $snippet->thumbnails->default->url;
                $entry->size = null;

                $entry->description = self::getSaneDescription($entry);
            }
        }
    }

    public function sort($opts=[]) {
        $sort = $opts["by"] ?? self::$sortableFields[0] ?? null;
        $sortDesc = ($opts["dir"] ?? null) == "desc";
        $sortSet = isset($opts["by"]);

        if (!in_array($sort, self::$sortableFields)) {
            $sort = null;
        }

        usort($this->entries, function($a, $b) use ($sort, $sortDesc, $sortSet) {
            $a = (!$sortSet && $a->type == self::LINKS_TYPE)? 0 : ($a->{$sort} ?? null);
            $b = (!$sortSet && $b->type == self::LINKS_TYPE)? 0 : ($b->{$sort} ?? null);

            if ($sort == "type" || $sort == "stype") {
                $a = self::$typeLabels[$a] ?? $a;
                $b = self::$typeLabels[$b] ?? $b;
            }

            if ($a < $b) {
                return !$sortDesc? -1 : 1;
            } else if ($a > $b) {
                return !$sortDesc? 1 : -1;
            } else {
                return 0;
            }
        });
    }

    public static function fromDir(string $realPath) {
        if (!$realPath || !is_readable($realPath)) {
            throw new LsPubException("Invalid realpath");
        } else if (!is_dir($realPath)) {
            return [self::newEntry(["canonical" => $realPath])];
        }

        $dir = opendir($realPath);

        if (!$dir) {
            return null;
        }

        $linksName = Config::get("links.name");
        $ls = [];

        while (($name = readdir($dir)) !== false) {
            $entry = self::newEntry(["canonical" => "$realPath/$name"]);
            $ls[] = $entry;

            if ($entry->name == $linksName) {
                $ls = array_merge($ls, self::fromLinks($entry->canonical));
            }
        }

        closedir($dir);

        return $ls;
    }

    public static function fromLinks(string $realPath) {
        if (!$realPath || !is_readable($realPath)) {
            throw new LsPubException("Invalid realpath");
        }

        $entries = [];
        $stream = fopen($realPath, "r");

        while (($line = fgetcsv($stream, null, ",", "\"", "\\")) !== false) {
            $line = array_map(function($field) {
                return trim((string)$field);
            }, $line);

            $link = $line[0] ?? "";
            $name = $line[1] ?? "";
            $desc = $line[2] ?? "";

            if (!$link || $link[0] == '#') {
                continue;
            }

            $entries[] = self::newEntry([
                "canonical" => $realPath,
                "type" => self::LINKS_TYPE,
                "href" => $link,
                "name" => $name,
                "description" => explode(LsOutput::NEWLINE_TOKEN, $desc)
            ]);
        }

        fclose($stream);

        return $entries;
    }

    public static function getSaneDescription($entry, $length=self::MAX_DESC_LEN) {
        $desc = str_ireplace([
            'provided to youtube',
            'auto-generated by youtube.',
            'auto-generated by youtube',
            'youtube'
        ], [
            'Uploaded',
            '',
            '',
            'You'
        ], $entry->description);
        $saneDesc = [];

        foreach (explode("\n", $desc) as $line) {
            $fc = $line[0] ?? null;
            $fco = $fc? ord($fc) : 0;

            // Skip lines with
            if (
                stripos($line, "://") !== false // URLs
                || $fco == 194 // Copyright
                || $fco == 226 // Music copyright
                || $fc == '#' // Hashtag
            ) {
                continue;
            }

            // Skip line if redundant title data
            if (stripos($line, $entry->name) === 0) {
                continue;
            }

            // Skip line if follow req
            if (preg_match('/^(join|subscribe|follow).*:/i', $line)) {
                continue;
            }

            // Attempt graceful length cap
            if (strlen($line) > $length) {
                $line = substr($line, 0, $length);
                $lastSpace = strrpos($line, ' ');

                $line = trim(($lastSpace !== false)? substr($line, 0, $lastSpace) : $line, " .");
                $line .= "...";

                $saneDesc[] = $line;
                break;
            } else {
                $saneDesc[] = trim($line);
            }

            // Reduce remaining length budget
            $length -= strlen($line);
        }

        return array_filter($saneDesc);
    }

    public static function newEntry(array $fields=[]) {
        $entry = (object)array_merge([
            "name" => null,
            "type" => null,
            "stype" => null,
            "description" => [],
            "canonical" => null,
            "href" => null
        ], $fields);

        // Sane defaults
        if (!$entry->name) {
            $entry->name = $entry->canonical? basename($entry->canonical) : mt_rand();
        }

        if (!$entry->type) {
            $entry->type = self::DEFAULT_TYPE;
        }

        if (!$entry->stype) {
            $entry->stype = self::DEFAULT_TYPE;
        }

        // File meta
        if ($entry->canonical) {
            $stat = is_readable($entry->canonical)? stat($entry->canonical) : null;

            foreach (["size", "atime", "ctime", "mtime"] as $k) {
                $entry->{$k} = $stat[$k] ?? null;
            }

            if ($stat && !$entry->type) {
                $entry->type = self::FILE_TYPE;

                if (is_dir($entry->canonical)) {
                    $entry->type = self::DIR_TYPE;
                }
            }

            // Basic typing to know when to header-scrape
            $lastDot = strrpos($entry->canonical, ".");
            $entry->ext = $lastDot? trim(strtolower(substr($entry->canonical, $lastDot + 1))) : null;

            foreach (self::$stypeExts as $type => $exts) {
                if (in_array($entry->ext, $exts)) {
                    $entry->stype = $type;
                    break;
                }
            }
        }

        return $entry;
    }
}

?>
