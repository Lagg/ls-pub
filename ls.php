<?php
require_once("lib/main.php");

// <MAIN>

class Main {
    private ?LsPub\LsOutput $out = null;

    public function getEntries(LsPub\LsRequest $req) {
        $entries = new LsPub\Entries(LsPub\Entries::fromDir($req->realPath));

        $entries->scrape();

        $entries->sort([
            "by" => $req->query["sort"] ?? null,
            "dir" => $req->query["dir"] ?? null
        ]);

        foreach ($entries->entries as $entry) {
            $entry->href = $entry->href ?? "$req->path/$entry->name";
        }

        return $entries->aggregate();
    }

    public function handleException(Exception $ex) {
        $isHttpException = $ex instanceof LsPub\HttpException;
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

        if ($ex instanceof LsPub\RedirectException) {
            header("Location: $msg");
        }
    }

    public function init() {
        $debug = LsPub\Config::get("project.debug");

        error_reporting($debug? E_ALL : 0);
    }

    public function run() {
        $req = null;
        $rootReal = LsPub\Config::get("root.real");
        $rootVirt = LsPub\Config::get("root.virtual");

        try {
            $req = new LsPub\LsRequest($rootReal, $rootVirt);

            switch ($req->outputFormat) {
            case LsPub\LsRequest::OUT_ATOM:
                $this->out = new LsPub\OutputAtom;
                break;
            case LsPub\LsRequest::OUT_JSON:
                $this->out = new LsPub\OutputJson;
                break;
            case LsPub\LsRequest::OUT_HTML:
                $this->out = new LsPub\OutputHtml;
                break;
            case LsPub\LsRequest::OUT_RSS:
                $this->out = new LsPub\OutputRss;
                break;
            case LsPub\LsRequest::OUT_TEXT:
                $this->out = new LsPub\LsOutput;
                break;
            default:
                throw new LsPub\HttpException("Unsupported", 415);
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

            $entries = $this->getEntries($req);

            if (is_dir($req->realPath)) {
                $this->out->writeEntries($entries);
            } else {
                $this->out->dumpEntry(array_values($entries)[0]);
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
