<?php namespace LsPub;

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
            throw new HttpException("Not found", 404);
        } else if (strpos($this->realPath, $this->realRoot) !== 0 || !$realPathReadable) {
            throw new HttpException("Forbidden", 403);
        } else if (!$this->outputFormatSane) {
            throw new HttpException("Bad Request", 400);
        } else {
            return true;
        }
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

class UrlScraper {
    private array $httpHeaders;

    public function __construct() {
        $name = Config::get("project.name");
        $userAgent = "$name Metadata Scraper";

        $this->httpHeaders = [
            "User-Agent: $userAgent"
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
}

?>
