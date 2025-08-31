<?php return [
    // Name used for titles/user-agent/etc.
    'project.name' => 'Ls\'Pub',
    // Canonical URL used for feeds/permalinks/etc.
    'project.url' => 'https://lagg.me',
    // Whether debug mode / params are supported
    'project.debug' => true,

    // If null, <working dir>/.cache is used
    'root.cache' => null,
    // If null, <working dir>/data is used
    'root.data' => null,
    // If null, <working dir> used
    'root.real' => null,
    // If null, / assumed
    'root.virtual' => null,

    // File where link list is stored
    'links.name' => 'links.txt',

    // Tube scraper conf
    'tube.key' => null,

    // Cache TTLs
    'cache.ttl.meta' => 300,
    'cache.ttl.tube' => 600,
]; ?>
