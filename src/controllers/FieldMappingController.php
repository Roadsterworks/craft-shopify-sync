<?php

namespace roadsterworks\craftshopifysync\controllers;

use roadsterworks\craftshopifysync\CraftShopifySync;
use yii\web\Response;

class FieldMappingController extends BaseCpController
{
    protected string $permission = CraftShopifySync::PERMISSION_FIELD_MAPPING;

    public function actionIndex(): Response
    {
        return $this->renderPage();
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $posted = (array)$this->request->getBodyParam('mappings', []);
        $errors = $this->plugin()->fieldMappingService->saveMappings($posted);

        if ($errors !== []) {
            $this->setFailFlash(CraftShopifySync::t('Couldn’t save field mappings.'));

            return $this->renderPage($errors, $posted);
        }

        return $this->asSuccess(CraftShopifySync::t('Field mappings saved.'));
    }

    /**
     * @param string[] $errors
     * @param array<string, array<string, array<string, string>>>|null $posted Values from a failed save.
     */
    private function renderPage(array $errors = [], ?array $posted = null): Response
    {
        $mappingData = $this->plugin()->fieldMappingService->getMappingData((bool)$this->request->getQueryParam('refresh'), $posted);
        $variables = ['mappingData' => $mappingData, 'errors' => $errors];
        $title = CraftShopifySync::t('Field Mapping');

        // Nothing to save until a section with entry types is chosen.
        return $mappingData['entryTypes'] === []
            ? $this->screen('field-mapping', $title, $variables)
            : $this->formScreen('field-mapping', $title, 'craft-shopify-sync/field-mapping/save', $variables);
    }
}
