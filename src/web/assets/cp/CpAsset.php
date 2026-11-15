<?php

namespace roadsterworks\craftshopifysync\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;
use craft\web\View;

class CpAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';
    public $depends = [CraftCpAsset::class];
    public $css = ['cp.css'];
    public $js = ['cp.js'];

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        if ($view instanceof View) {
            $view->registerTranslations('craft-shopify-sync', [
                'Can’t reach Shopify: {error}',
                'Couldn’t load live stats.',
                'Queued',
            ]);
        }
    }
}
