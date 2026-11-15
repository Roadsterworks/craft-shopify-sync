<?php

namespace roadsterworks\craftshopifysync\controllers;

use Craft;
use craft\helpers\Cp;
use craft\web\Controller;
use craft\web\CpScreenResponseBehavior;
use roadsterworks\craftshopifysync\CraftShopifySync;
use roadsterworks\craftshopifysync\web\assets\cp\CpAsset;
use yii\web\Response;

/**
 * Control panel controller that requires the plugin permission named by {@see $permission}.
 */
abstract class BaseCpController extends Controller
{
    protected string $permission = CraftShopifySync::PERMISSION_ACCESS;

    public function beforeAction($action): bool
    {
        $this->requireCpRequest();
        $this->requirePermission($this->permission);

        return parent::beforeAction($action);
    }

    protected function plugin(): CraftShopifySync
    {
        return CraftShopifySync::getInstance();
    }

    /**
     * A Craft control panel screen for one of the plugin's pages.
     *
     * @param array<string, mixed> $variables
     * @param (callable(CpScreenResponseBehavior): void)|null $configure
     */
    protected function screen(string $page, string $title, array $variables = [], ?callable $configure = null): Response
    {
        $this->getView()->registerAssetBundle(CpAsset::class);

        $response = $this->asCpScreen();
        /** @var CpScreenResponseBehavior $screen */
        $screen = $response->getBehavior(CpScreenResponseBehavior::NAME);
        $screen->title($title);
        $screen->selectedSubnavItem($page);
        $screen->contentTemplate("craft-shopify-sync/{$page}/_content", $variables);

        if ($page !== 'dashboard') {
            $screen->addCrumb(CraftShopifySync::t('Shopify Sync'), CraftShopifySync::HANDLE);
        }

        if ($configure !== null) {
            $configure($screen);
        }

        return $response;
    }

    /**
     * A screen that saves to `$action`, or a read-only notice where admin changes are disabled.
     *
     * @param array<string, mixed> $variables
     */
    protected function formScreen(string $page, string $title, string $action, array $variables = []): Response
    {
        return $this->screen($page, $title, $variables, function(CpScreenResponseBehavior $screen) use ($page, $action) {
            if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
                $screen->noticeHtml(Cp::readOnlyNoticeHtml());

                return;
            }

            $screen->action($action);
            $screen->redirectUrl(CraftShopifySync::HANDLE . "/{$page}");
        });
    }
}
