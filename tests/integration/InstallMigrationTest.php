<?php

namespace justinholtweb\rat\tests\integration;

use Craft;
use craft\test\TestCase;
use justinholtweb\rat\migrations\Install;
use justinholtweb\rat\migrations\m261008_000000_record_deletes_restores_and_moves;
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
            'elementLabel', 'isNew', 'action', 'details', 'dirtyAttributes', 'dateCreated', 'dateUpdated', 'uid',
        ] as $column) {
            $this->assertArrayHasKey($column, $columns);
        }

        $this->assertFalse($columns['elementId']->allowNull);
        $this->assertFalse($columns['siteId']->allowNull);
        $this->assertTrue($columns['userId']->allowNull);
        $this->assertTrue($columns['elementLabel']->allowNull);
        $this->assertSame(255, $columns['elementLabel']->size);
        $this->assertFalse($columns['action']->allowNull);
        $this->assertSame('save', $columns['action']->defaultValue);
        $this->assertTrue($columns['details']->allowNull);
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

        foreach ([['elementId'], ['userId'], ['elementType'], ['dateCreated'], ['action']] as $expected) {
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

    /**
     * Until 5.2.0 a foreign key deleted an element's log rows along with it, so a permanent delete
     * erased the history of exactly the thing somebody would come looking for.
     */
    public function testLogRowsOutliveTheirElement(): void
    {
        $element = $this->createUser();
        $this->assertNotEmpty($this->logRowsFor($element->id));

        // Hard delete, as Craft's garbage collector eventually does.
        Craft::$app->getDb()->createCommand()
            ->delete('{{%elements}}', ['id' => $element->id])
            ->execute();

        $this->assertNotEmpty($this->logRowsFor($element->id));
    }

    public function testTheUpgradeMigrationBringsA51TableUpToDate(): void
    {
        $db = Craft::$app->getDb();
        $table = '{{%rat_editlog}}';

        // Put the table back the way 5.1 left it: no action or details, cascading foreign key.
        $old = new Install(['db' => $db]);
        $old->dropColumn($table, 'details');
        $old->dropIndexIfExists($table, ['action']);
        $old->dropColumn($table, 'action');
        $old->addForeignKey(null, $table, ['elementId'], '{{%elements}}', ['id'], 'CASCADE', null);
        $db->getSchema()->refresh();
        $this->assertNotEmpty($this->elementForeignKeys());

        // Before the migration runs, saves are still recorded and a delete still goes through.
        $user = $this->createUser();
        $this->assertCount(1, $this->logRowsFor($user->id));
        $doomed = $this->createUser();
        $this->assertTrue(Craft::$app->getElements()->deleteElement($doomed));
        $this->assertCount(1, $this->logRowsFor($doomed->id), 'the delete itself has nowhere to go yet');

        $this->assertTrue((new m261008_000000_record_deletes_restores_and_moves(['db' => $db]))->safeUp());
        $db->getSchema()->refresh();

        $columns = $db->getTableSchema($table, true)->columns;
        $this->assertArrayHasKey('action', $columns);
        $this->assertArrayHasKey('details', $columns);
        $this->assertSame([], $this->elementForeignKeys());
        $this->assertContains(['action'], $this->indexColumnSets());
        $this->assertSame('save', $this->logRowsFor($user->id)[0]['action'], 'existing rows are saves');
    }

    private function elementForeignKeys(): array
    {
        $foreignKeys = Craft::$app->getDb()->getTableSchema('{{%rat_editlog}}', true)->foreignKeys;

        return array_filter($foreignKeys, fn(array $fk) => array_key_exists('elementId', $fk));
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
