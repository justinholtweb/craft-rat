<?php

namespace justinholtweb\rat\tests\integration;

use Craft;
use craft\test\TestCase;

final class SmokeTest extends TestCase
{
    public function testCraftBootsAndRatIsInstalled(): void
    {
        $this->assertTrue(Craft::$app->getIsInstalled());
        $this->assertNotNull(Craft::$app->getPlugins()->getPlugin('rat'));
        $this->assertTrue(Craft::$app->getDb()->tableExists('{{%rat_editlog}}'));
    }
}
