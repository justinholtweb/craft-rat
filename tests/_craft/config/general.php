<?php

use craft\config\GeneralConfig;

return GeneralConfig::create()
    ->securityKey('rat-plugin-test-suite-key')
    ->devMode(true)
    ->allowAdminChanges(true)
    ->omitScriptNameInUrls(true)
    ->defaultWeekStartDay(0)
    // The test site URL doesn't resolve, so letting Craft fire off its own
    // queue-runner requests would leave every element save waiting on a
    // connection that never completes.
    ->runQueueAutomatically(false)
    ->enableTemplateCaching(false);
