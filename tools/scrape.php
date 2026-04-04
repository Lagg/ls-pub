<?php namespace LsCmd;

class Scrape extends Command {
    public function run($cmd) {
        $realPaths = $cmd->args;

        if (empty($realPaths)) {
            $realPaths = [\LsPub\Config::get("root.real", ".")];
        }

        for ($i = 0; $i < count($realPaths); $i++) {
            $realPath = $realPaths[$i];

            $entries = new \LsPub\Entries(\LsPub\Entries::fromDir($realPath));
            $entries->loadMeta();

            $this->out("\n# Scraped " . count($entries->entries) . " entries from $realPath\n");

            foreach ($entries->entries as $entry) {
                if ($entry->type == \LsPub\Entries::DIR_TYPE) {
                    $realPaths[] = $entry->canonical;
                }
            }
        }
    }
}

?>
