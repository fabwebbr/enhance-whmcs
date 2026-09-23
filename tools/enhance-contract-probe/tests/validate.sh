#!/bin/sh
set -eu
php -n -v
for probe_file in tools/enhance-contract-probe/*.php tools/enhance-contract-probe/tests/*.php; do
    sh tests/php-isolated.sh -l "$probe_file"
done
sh -n tests/php-isolated.sh
sh -n tools/enhance-contract-probe/tests/validate.sh
sh tests/php-isolated.sh tools/enhance-contract-probe/probe.php
sh tests/php-isolated.sh tools/enhance-contract-probe/tests/run.php
sh tests/php-isolated.sh -d zend.exception_ignore_args=0 tests/run.php
