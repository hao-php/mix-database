#!/usr/bin/env bash

if (( "$#" != 1 ))
then
    echo "The target cannot be empty"
    exit 1
fi

vendor/bin/phpunit -c tests/phpunit.xml.dist "$1"
