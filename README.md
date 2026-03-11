# Ls'Pub

Directory and file metadata lister/scraper tool in PHP.

# Configuration

Config is done by editing `config.php`

Example server config:

```
root /home/lagg/home-pages/pub;

location /pub {
    alias /home/lagg/home-bitbucket;
    try_files $uri @ls;
}

location @ls {
        include fastcgi_params;
        fastcgi_pass unix:/run/php-fpm/lagg.sock;
#        fastcgi_split_path_info ^(.+\.php)(.*)$;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME /home/lagg/projects/ls-pub/ls.php;
        fastcgi_param HTTP_PROXY "";
        fastcgi_param HTTPS $https;
}
```
