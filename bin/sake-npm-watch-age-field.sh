#!/bin/bash

source ~/.nvm/nvm.sh
nvm use node

echo '------------------------------'
echo ' run build'
echo '------------------------------'
vendor/bin/webpack install vendor/sunnysideup/age-field/client
vendor/bin/webpack build vendor/sunnysideup/age-field/client --no-jquery
echo '------------------------------'
