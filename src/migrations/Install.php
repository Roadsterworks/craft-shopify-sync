<?php

namespace roadsterworks\craftshopifysync\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use roadsterworks\craftshopifysync\CraftShopifySync;
use roadsterworks\craftshopifysync\db\Table;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable(Table::PRODUCTS, [
            'id' => $this->primaryKey(),
            'entryId' => $this->integer()->notNull(),
            'shopifyId' => $this->string(),
            'shopifyHandle' => $this->string(),
            'status' => $this->string(20)->notNull(),
            'contentHash' => $this->char(64),
            'mediaHash' => $this->char(64),
            'lastError' => $this->text(),
            'lastSyncedAt' => $this->dateTime(),
            'unpublishedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::PRODUCTS, ['entryId'], true);
        $this->createIndex(null, Table::PRODUCTS, ['shopifyId'], true);
        $this->createIndex(null, Table::PRODUCTS, ['status']);
        // Recent activity on the dashboard.
        $this->createIndex(null, Table::PRODUCTS, ['dateUpdated']);
        $this->addForeignKey(null, Table::PRODUCTS, ['entryId'], CraftTable::ELEMENTS, ['id'], 'CASCADE');

        // The app's access token, when it can't be saved to `.env`. One row per environment.
        $this->createTable(Table::TOKENS, [
            'id' => $this->primaryKey(),
            'shop' => $this->string()->notNull(),
            'accessToken' => $this->text()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        return true;
    }

    /**
     * Remove the Shopify Product field (rather than leave a “missing field”), the tables and caches.
     */
    public function safeDown(): bool
    {
        $plugin = CraftShopifySync::getInstance();
        $plugin->fieldLayoutService->uninstall();
        $plugin->shopifyService->clearCaches();

        $this->dropTableIfExists(Table::PRODUCTS);
        $this->dropTableIfExists(Table::TOKENS);

        return true;
    }
}
