<?php

namespace justinholtweb\rat\tests\unit;

use justinholtweb\rat\models\EditLog;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for the EditLog model's pure presentation logic.
 *
 * The model is built without its constructor so these run as plain unit
 * tests with no Craft application boot. Only the methods that don't touch
 * Craft::$app are exercised here (label mapping, dirty-attribute decoding,
 * and the early-return guards on getUser()/getElement()).
 *
 * Methods that touch Craft::$app are exercised only on their null-guard paths.
 */
final class EditLogTest extends TestCase
{
    private function makeLog(array $props = []): EditLog
    {
        /** @var EditLog $log */
        $log = (new ReflectionClass(EditLog::class))->newInstanceWithoutConstructor();
        foreach ($props as $name => $value) {
            $log->$name = $value;
        }
        return $log;
    }

    // ---- getElementTypeLabel -----------------------------------------------

    /**
     * @dataProvider knownTypeProvider
     */
    public function testKnownElementTypesMapToFriendlyLabels(string $class, string $expected): void
    {
        $log = $this->makeLog(['elementType' => $class]);
        $this->assertSame($expected, $log->getElementTypeLabel());
    }

    public static function knownTypeProvider(): array
    {
        return [
            ['craft\\elements\\Entry', 'Entry'],
            ['craft\\elements\\Asset', 'Asset'],
            ['craft\\elements\\GlobalSet', 'Global'],
            ['craft\\elements\\Category', 'Category'],
            ['craft\\elements\\Tag', 'Tag'],
            ['craft\\elements\\User', 'User'],
            ['craft\\commerce\\elements\\Product', 'Product'],
            ['craft\\commerce\\elements\\Variant', 'Variant'],
            ['craft\\commerce\\elements\\Order', 'Order'],
        ];
    }

    public function testUnknownElementTypeFallsBackToClassBasename(): void
    {
        $log = $this->makeLog(['elementType' => 'acme\\plugin\\elements\\Widget']);
        $this->assertSame('Widget', $log->getElementTypeLabel());
    }

    public function testNullElementTypeProducesEmptyLabel(): void
    {
        $log = $this->makeLog(['elementType' => null]);
        $this->assertSame('', $log->getElementTypeLabel());
    }

    // ---- getDirtyAttributesList --------------------------------------------

    public function testDirtyAttributesDecodeFromJson(): void
    {
        $log = $this->makeLog(['dirtyAttributes' => json_encode(['title', 'slug'])]);
        $this->assertSame(['title', 'slug'], $log->getDirtyAttributesList());
    }

    public function testNullDirtyAttributesReturnEmptyArray(): void
    {
        $log = $this->makeLog(['dirtyAttributes' => null]);
        $this->assertSame([], $log->getDirtyAttributesList());
    }

    public function testInvalidJsonDirtyAttributesReturnEmptyArray(): void
    {
        $log = $this->makeLog(['dirtyAttributes' => 'not-json']);
        $this->assertSame([], $log->getDirtyAttributesList());
    }

    // ---- null guards --------------------------------------------------------

    public function testGetUserReturnsNullWithoutUserId(): void
    {
        $log = $this->makeLog(['userId' => null]);
        $this->assertNull($log->getUser());
    }

    public function testGetElementReturnsNullWithoutElementId(): void
    {
        $log = $this->makeLog(['elementId' => null, 'elementType' => 'craft\\elements\\Entry']);
        $this->assertNull($log->getElement());
    }

    public function testGetElementReturnsNullWithoutElementType(): void
    {
        $log = $this->makeLog(['elementId' => 5, 'elementType' => null]);
        $this->assertNull($log->getElement());
    }
}
