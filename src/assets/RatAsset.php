<?php

namespace justinholtweb\rat\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class RatAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->css = ['css/rat.css'];
        $this->js = ['js/rat.js'];

        parent::init();
    }
}
