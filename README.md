# phpstorm-protocol-handler

[Open files from the command line](https://www.jetbrains.com/help/phpstorm/opening-files-from-command-line.html)


## Install

1. Run 
    ```bash
    mkdir -p ~/bin \
    && \
    ln -s \
        $(realpath --relative-to="${HOME}/bin" "${PWD}/phpstorm-protocol-handler.php") \
        ~/bin/phpstorm-protocol-handler.php
    ```
2. Run
    ```bash
    desktop-file-install \
        --dir="${HOME}/.local/share/applications" \
        --mode="$(expr '0777' - $(umask))" \
        --rebuild-mime-info-cache \
        "${PWD}/phpstorm-protocol-handler.desktop"
    ```


##  How to check

* Run `kde-open5 "phpstorm://open?file=${PWD}/README.md&line=3&column=6"` 
* Run `xdg-open "phpstorm://open?file=${PWD}/README.md&line=3&column=6"` 


## Xdebug settings

[xdebug.file_link_format](https://xdebug.org/docs/all_settings#file_link_format)
> `xdebug.file_link_format="phpstorm://open?file=%f&line=%l"`
