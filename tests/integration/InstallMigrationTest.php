<?php

namespace justinholtweb\rat\tests\integration;

use Craft;
use craft\test\TestCase;
use justinholtweb\rat\migrations\Install;
use justinholtweb\rat\tests\support\RatTestTrait;

/**
 * Schema-level coverage of the install migration.
 *
 * These tests touch DDL, which MySQL can't roll back, so each one leaves the
 * schema exactly as it found it.
 */
final class InstallMigrationTest extends TestCase
{
    use RatTestTrait;

    public function testTheLogTableHasEveryExpectedColumn(): void
    {
        $columns = Craft::$app->getDb()->getTableSchema('{{%rat_editlog}}', true)->columns;

        foreach ([
            'id', 'elementId', 'siteId', 'userId', 'elementType',
            'elementLabel', 'isNew', 'dirtyAttributes', 'dateCreated', 'dateUpdated', 'uid',
        ] as $column) {
            $this->assertArrayHasKey($column, $columns);
        }

        $this->assertFalse($columns['elementId']->allowNull);
        $this->assertFalse($columns['siteId']->allowNull);
        $this->assertTrue($columns['userId']->allowNull);
        $this->assertTrue($columns['elementLabel']->allowNull);
        $this->assertSame(255, $columns['elementLabel']->size);
    }

    /**
     * The Last Editor column and sort both look rows up by (elementId, siteId),
     * once per index row, so that pair has to stay indexed.
     */
    public function testTheElementSiteLookupIsIndexed(): void
    {
        $this->assertContains(['elementId', 'siteId'], $this->indexColumnSets());
    }

    public function testTheHotSingleColumnLookupsAreIndexed(): void
    {
        $sets = $this->indexColumnSets();

        foreach ([['elementId'], ['userId'], ['elementType'], ['dateCreated']] as $expected) {
            $this->assertContains($expected, $sets);
        }
    }

    /**
     * @return array<int, string[]> Each index as its ordered list of columns.
     */
    private function indexColumnSets(): array
    {
        $db = Craft::$app->getDb();
        $table = $db->getSchema()->getRawTableName('{{%rat_editlog}}');

        $rows = $db->createCommand("SHOW INDEX FROM `$table`")->queryAll();

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['Key_name']][(int)$row['Seq_in_index']] = $row['Column_name'];
        }

        return array_map(function (array $columns) {
            ksort($columns);
            return array_values($columns);
        }, array_values($byName));
    }

    public function testDeletingAnElementCascadesToItsLogRows(): void
    {
        $element = $this->createUser();
        $this->assertNotEmpty($this->logRowsFor($element->id));

        // Hard delete, as Craft's garbage collector eventually does.
        Craft::$app->getDb()->createCommand()
            ->delete('{{%elements}}', ['id' => $element->id])
            ->execute();

        $this->assertSame([], $this->logRowsFor($element->id));
    }

    public function testTheMigrationCanBeRolledBackAndReapplied(): void
    {
        $db = Craft::$app->getDb();

        $down = new Install(['db' => $db]);
        $this->assertTrue($down->safeDown());
        $this->assertFalse($db->tableExists('{{%rat_editlog}}'));

        $up = new Install(['db' => $db]);
        $this->assertTrue($up->safeUp());
        $db->getSchema()->refresh();
        $this->assertTrue($db->tableExists('{{%rat_editlog}}'));
    }
}
