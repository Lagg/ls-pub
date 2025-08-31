<?php namespace LsPub;

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

class Config {
    const FILE_NAME = "config.php";

    public static $conf = null;

    public static function getConf() {
        if (!is_null(self::$conf)) {
            return self::$conf;
        } else {
            self::$conf = [];
        }

        if (file_exists(self::FILE_NAME)) {
            self::$conf = include(self::FILE_NAME);
        }

        return self::$conf;
    }

    public static function get(string $key, $default=null) {
        return self::getConf()[$key] ?? $default;
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

    public function __construct($dir=null) {
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
        return Config::get("root.data", getcwd() . "/data");
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

?>
