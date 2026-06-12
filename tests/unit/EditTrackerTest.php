<?php

namespace justinholtweb\rat\tests\unit;

use craft\base\Element;
use justinholtweb\rat\services\EditTracker;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the save-filtering predicate.
 *
 * shouldTrack() is the gatekeeper that keeps drafts, revisions, propagating
 * saves, and bulk resaves out of the log. Elements are mocked without their
 * constructor so only getIsDraft()/getIsRevision() are stubbed and the
 * propagating/resaving flags are set as plain public properties — no Craft
 * application boot required.
 */
final class EditTrackerTest extends TestCase
{
    private EditTracker $tracker;

    protected function setUp(): void
    {
        $this->tracker = new EditTracker();
    }

    private function makeElement(
        bool $isDraft = false,
        bool $isRevision = false,
        bool $propagating = false,
        bool $resaving = false,
    ): Element {
        $element = $this->getMockBuilder(Element::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIsDraft', 'getIsRevision'])
            ->getMock();
        $element->method('getIsDraft')->willReturn($isDraft);
        $element->method('getIsRevision')->willReturn($isRevision);
        $element->propagating = $propagating;
        $element->resaving = $resaving;
        return $element;
    }

    public function testCanonicalSaveIsTracked(): void
    {
        $this->assertTrue($this->tracker->shouldTrack($this->makeElement()));
    }

    public function testDraftsAreSkipped(): void
    {
        $this->assertFalse($this->tracker->shouldTrack($this->makeElement(isDraft: true)));
    }

    public function testRevisionsAreSkipped(): void
    {
        $this->assertFalse($this->tracker->shouldTrack($this->makeElement(isRevision: true)));
    }

    public function testPropagatingSavesAreSkipped(): void
    {
        $this->assertFalse($this->tracker->shouldTrack($this->makeElement(propagating: true)));
    }

    public function testBulkResavesAreSkipped(): void
    {
        $this->assertFalse($this->tracker->shouldTrack($this->makeElement(resaving: true)));
    }
}
