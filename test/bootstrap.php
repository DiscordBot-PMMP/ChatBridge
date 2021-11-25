<?php
/*
 * ChatBridge, PocketMine-MP Plugin.
 *
 * Licensed under the Open Software License version 3.0 (OSL-3.0)
 * Copyright (C) 2020-present JaxkDev
 *
 * Twitter :: @JaxkDev
 * Discord :: JaxkDev#2698
 * Email   :: JaxkDev@gmail.com
 */

//JIT Should also be disabled for PHPStan analysis as it hangs on analysis.

//OPCache should also be disabled because of https://github.com/phpstan/phpstan/issues/5503
ini_set('opcache.enable', 'off');