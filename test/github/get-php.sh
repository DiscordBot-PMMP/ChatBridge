PHP=$"8.1" #Change this to void cache, 002
PM=$"5"
GIT_TAG=$"php-$PHP-latest"

wget -q -O - "https://github.com/pmmp/PHP-Binaries/releases/download/$GIT_TAG/PHP-Linux-x86_64-PM$PM.tar.gz" | tar -zx > /dev/null 2>&1
chmod +x ./bin/php7/bin/*

EXTENSION_DIR=$(find "$(pwd)/bin" -name *debug-zts*) #make sure this only captures from `bin` in case the user renamed their old binary folder
#Modify extension_dir directive if it exists, otherwise add it
LF=$'\n'
grep -q '^extension_dir' bin/php7/bin/php.ini && sed -i'bak' "s{^extension_dir=.*{extension_dir=\"$EXTENSION_DIR\"{" bin/php7/bin/php.ini || sed -i'bak' "1s{^{extension_dir=\"$EXTENSION_DIR\"\\$LF{" bin/php7/bin/php.ini