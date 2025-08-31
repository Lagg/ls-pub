<?php namespace LsPub;

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

    // TODO: vroot shouldn't be needed

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
            "description" => []
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

        $apiKey = Config::get("tube.key");
        $cacheMetaTtl = Config::get("cache.ttl.meta");
        $cacheTubeTtl = Config::get("cache.ttl.tube");

        $tube = $apiKey? new TubeScraper($apiKey) : null;

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
            Cache::set($metaCkey, $fileMeta, $cacheMetaTtl);
        }

        if ($tube) {
            $tubeCkey .= "-" . sha1(implode(TubeScraper::getTubeIds($entries)));
            $tubeMeta = Cache::get($tubeCkey);

            if (is_null($tubeMeta)) {
                $tubeMeta = $tube->scrapeVideoMeta($entries);

                if ($tubeMeta) {
                    Cache::set($tubeCkey, $tubeMeta, $cacheTubeTtl);
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
                $entry->description = $snippet->description;
                $entry->name = $snippet->title;
                $entry->thumb = $snippet->thumbnails->default->url;

                $entry->description = self::getSaneDescription($entry);
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
            $fc = $line[0] ?? '';
            $fco = ord($fc);

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
}

?>
