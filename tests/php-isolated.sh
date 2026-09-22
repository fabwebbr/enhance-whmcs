#!/bin/sh
# PHP 8 removes disabled internal functions before compiling userland fakes.
# Inventory only: no transport functions are invoked in this first process.
set -eu
PHP_BIN=${PHP_BIN:-php}
disabled=$("$PHP_BIN" -n -r '
    $functions = array_merge(get_extension_funcs("curl") ?: [], get_extension_funcs("sockets") ?: [], [
        "curl_init", "curl_setopt_array", "curl_setopt", "curl_exec", "curl_error",
        "curl_errno", "curl_getinfo", "curl_close", "fsockopen", "pfsockopen",
        "stream_socket_client", "stream_socket_server", "stream_socket_sendto",
        "exec", "system", "passthru", "shell_exec", "proc_open", "popen", "mail"
    ]);
    echo implode(",", array_unique($functions));
')
exec "$PHP_BIN" -n -d "disable_functions=$disabled" \
    -d allow_url_fopen=0 -d allow_url_include=0 "$@"
