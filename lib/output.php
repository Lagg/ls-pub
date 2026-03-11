<?php namespace LsPub;

class LsOutput {
    const NEWLINE_TOKEN = " -- ";

    public string $projectName;
    public string $projectUrl;

    public static $censoredDumpKeys = ["[canonical]"];

    protected $stdout;
    protected string $title = "";

    private $lines = [];

    public function __construct() {
        $vroot = (string)(Config::get("root.virtual") ?? "/");
        $this->projectName = (string)Config::get("project.name");
        $this->projectUrl = Config::get("project.url") . $vroot;
    }

    public function dumpEntry($entry) {
        $flattened = [];
        $this->flattenVar($flattened, $entry);

        foreach ($flattened as $k => $v) {
            $this->lines[] = [$k, $v];
        }
    }

    public function flush() {
        $maxFieldLen = empty($this->lines)? 0 : max(array_map("count", $this->lines));

        $this->lines = array_map(function($line) use ($maxFieldLen) {
            return array_pad($line, $maxFieldLen, "");
        }, $this->lines);

        foreach ($this->lines as $line) {
            $line = array_map(function($f) { return trim((string)$f); }, $line);
            fputcsv($this->stdout, $line);
        }
        $this->lines = [];

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
        $desc = implode(self::NEWLINE_TOKEN, $entry->description);
        $thumb = $c->thumb ?? "";

        if ($desc) {
            $desc .= " -";
        }

        if ($c->artist) {
            $desc .= " by $c->artist";
        }

        if ($c->album) {
            $desc .= " from $c->album";
        }

        $this->lines[] = [$entry->type, $entry->stype, $entry->href, $name, $desc, $thumb];
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
        $this->lines[] = ["##", "/$group"];
    }

    public function writeEntryGroupHeader(string $group) {
        $this->lines[] = ["##", $group];
    }

    public function writeException(\Exception $ex) {
        $this->lines[] = ["##", "ERROR: " . $ex->getMessage()];
    }

    public function writeFooter() {
        $this->lines[] = ["#", "/$this->title"];
    }

    public function writeHeader() {
        $this->lines[] = ["#", $this->title];
    }

    public static function condenseEntry($entry) {
        $m = array_merge($entry->meta ?? [], $entry->meta[MetaScraper::ID3_TEXT_TAG] ?? []);
        $apicHref = $m[MetaScraper::ID3_APIC_TAG]->data ?? null;

        $name = $entry->name;
        $artist = $entry->meta["channelName"] ?? "";
        $album = "";
        $mtime = prettyDate($entry->mtime ?? $entry->ctime ?? null);
        $thumb = $entry->thumb ?? null;
        $thumbRoot = pnorm(Config::get("root.virtual", "/"));

        // Expanded metadata
        $name = $m["TIT2"] ?? $m["TITLE"] ?? $name;
        $artist = $m["ARTISTSORT"] ?? $m["ALBUMARTISTSORT"] ?? $m["ARTISTS"] ?? $m["TPE1"] ?? $artist;
        $album = $m["TALB"] ?? $m["ALBUM"] ?? $album;
        $mtime = $m["ORIGINALDATE"] ?? $m["TYER"] ?? $m["originalyear"] ?? $mtime;
        $thumb = $apicHref? "$thumbRoot/data/$apicHref" : $thumb;

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
        $href = $entry->href;
        $isExternalLink = $entry->type == Entries::LINKS_TYPE;
        $size = prettySize($entry->size ?? null);
        $mtime = $entry->mtime ?? time();
        $id = sha1($entry->slug ?? null . $ce->name . $this->projectUrl . $size);
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
<?php
        foreach ($entry->description as $desc) {
            echo htmlentities($desc, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1) . (count($entry->description) > 1? "<br/>" : "");
        }
?>

            <?=s($entry->description && ($ce->artist || $ce->album)? "-" : "")?>

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

    public function writeException(\Exception $ex) {
?>
        <pre class="http-error"><?=s($ex->getTraceAsString())?></pre>
<?php
    }

    public function writeHeader() {
        $updated = time();
        $id = sha1($this->projectName . $this->projectUrl);
?>
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
    <title><?=s($this->title)?></title>
    <link href="<?=s($this->projectUrl)?>"/>
    <updated><?=date(DATE_ATOM, $updated)?></updated>
    <author><name><?=s($this->projectName)?></name></author>
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
        $href = $entry->href;
        $id = $entry->slug ?? null;
        $isExternalLink = $entry->type == Entries::LINKS_TYPE;
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
<?php
        foreach ($entry->description as $desc) {
            echo s($desc) . (count($entry->description) > 1? "<br/>" : "");
        }
?>

            <?=s($entry->description && ($ce->artist || $ce->album)? "-" : "")?>

            <?=$ce->artist? ("by <em>" . s($ce->artist) . "</em>") : ""?>
            <?=$ce->album? ("from <em>" . s($ce->album) . "</em>") : ""?>
        </td>
<?php
        if (!empty($ce->mtime)) {
?>
        <td class="mtime"><?=s($ce->mtime)?></td>
<?php
        }

        if (!$isExternalLink && !empty($entry->size)) {
?>
        <td class="size"><?=s(prettySize($entry->size))?></td>
<?php
        }
?>
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

    public function writeException(\Exception $ex) {
?>
        <h1 class="http-error"><?=s($ex->getMessage())?></h1>
        <h3 class="http-error-back"><a href="<?=s(Config::get("root.virtual", "/"))?>">Back</a></h3>
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

    public function writeException(\Exception $ex) {
        $this->errors[] = $ex->getTrace();
    }

    public function writeFooter() {
    }

    public function writeHeader() {
    }
}

class OutputRss extends LsOutput {
    public function dumpEntry($entry) {
        $thumb = $ce->thumb ?? null;
        $flattened = [];
        $this->flattenVar($flattened, $entry);
?>
    <item>
        <title><?=s($entry->name ?? "")?></title>
        <pubDate><?=date(DATE_RSS, $entry->mtime ?? 0)?></pubDate>
        <description>
<?php
        foreach ($flattened as $k => $v) {
            echo s($k) . " = " . s($v) . "\n<br/>";
        }
?>
        </description>
    </item>
<?php
    }

    public function getContentType() {
        return "application/xml";
    }

    public function writeEntry($entry) {
        $ce = self::condenseEntry($entry);
        $href = $entry->href;
        $isExternalLink = $entry->type == Entries::LINKS_TYPE;
        $size = prettySize($entry->size ?? null);
        $mtime = $entry->mtime ?? time();
        $id = sha1($entry->slug ?? null . $ce->name . $this->projectUrl . $size);
?>
    <item>
        <title><?=s($ce->name)?></title>
        <link><?=s($href)?></link>
        <guid>urn:sha1:<?=s($id)?></guid>
        <pubDate><?=date(DATE_RSS, $mtime)?></pubDate>
        <description>
<?php
        foreach ($entry->description as $desc) {
            echo htmlentities($desc, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1) . (count($entry->description) > 1? "<br/>" : "");
        }
?>

            <?=s($entry->description && ($ce->artist || $ce->album)? "-" : "")?>

            <?=$ce->artist? ("by " . s($ce->artist)) : ""?>
            <?=$ce->album? ("from " . s($ce->album)) : ""?>
        </description>
    </item>
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

    public function writeException(\Exception $ex) {
?>
        <pre class="http-error"><?=s($ex->getTraceAsString())?></pre>
<?php
    }

    public function writeHeader() {
        $updated = time();
?>
<?xml version="1.0" encoding="utf-8"?>
<rss version="2.0">
    <channel>
        <title><?=s($this->title)?></title>
        <description>Dir list</description>
        <link><?=s($this->projectUrl)?></link>
        <pubDate><?=date(DATE_RSS, $updated)?></pubDate>
        <generator><?=s($this->projectName)?></generator>
<?php
    }

    public function writeFooter() {
?>
    </channel>
</rss>
<?php
    }

}

