<?php
use PHPUnit\Framework\TestCase;
final class ArmouryReleasePublicationDiagnosticsTest extends TestCase {
    public function test_publication_requires_factions_and_units(): void {
        $this->assertSame('publish_no_factions', LS_Armoury::publication_issue(0, 1, 0));
        $this->assertSame('publish_no_units', LS_Armoury::publication_issue(1, 0, 0));
    }
    public function test_publication_rejects_broken_faction_links(): void {
        $this->assertSame('publish_invalid_units', LS_Armoury::publication_issue(2, 3, 1));
    }
    public function test_complete_cloned_catalogue_passes_prerequisites(): void {
        $this->assertSame('', LS_Armoury::publication_issue(2, 3, 0));
    }
}
