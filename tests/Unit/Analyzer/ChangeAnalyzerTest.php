<?php

declare(strict_types=1);

namespace Nola\Deploy\Tests\Unit\Analyzer;

use Nola\Deploy\Analyzer\ChangeAnalyzer;
use Nola\Deploy\Analyzer\ChangeSet;
use PHPUnit\Framework\TestCase;

class ChangeAnalyzerTest extends TestCase
{
    private ChangeAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new ChangeAnalyzer();
    }

    public function testFullRebuildNeedsEverything(): void
    {
        $cs = new ChangeSet();
        $cs->isFullRebuild = true;

        $this->assertTrue($this->analyzer->needsDiCompile($cs));
        $this->assertTrue($this->analyzer->needsStaticDeploy($cs));
        $this->assertTrue($this->analyzer->needsSetupUpgrade($cs));
    }

    public function testNoChangesNeedsNothing(): void
    {
        $cs = new ChangeSet();

        $this->assertFalse($this->analyzer->needsDiCompile($cs));
        $this->assertFalse($this->analyzer->needsStaticDeploy($cs));
        $this->assertFalse($this->analyzer->needsSetupUpgrade($cs));
    }

    public function testPhpChangeNeedsDiCompileAndStaticDeploy(): void
    {
        $cs = new ChangeSet();
        $cs->phpFiles = ['app/code/Vendor/Module/Model/Test.php'];
        $cs->allFiles = $cs->phpFiles;

        $this->assertTrue($this->analyzer->needsDiCompile($cs));
        $this->assertTrue($this->analyzer->needsStaticDeploy($cs));
        $this->assertFalse($this->analyzer->needsSetupUpgrade($cs));
    }

    public function testDiXmlChangeNeedsDiCompile(): void
    {
        $cs = new ChangeSet();
        $cs->diXmlFiles = ['app/code/Vendor/Module/etc/di.xml'];
        $cs->allFiles = $cs->diXmlFiles;

        $this->assertTrue($this->analyzer->needsDiCompile($cs));
    }

    public function testComposerChangeNeedsDiAndUpgrade(): void
    {
        $cs = new ChangeSet();
        $cs->composerFiles = ['composer.lock'];
        $cs->allFiles = $cs->composerFiles;

        $this->assertTrue($this->analyzer->needsDiCompile($cs));
        $this->assertTrue($this->analyzer->needsSetupUpgrade($cs));
    }

    public function testThemeChangeNeedsStaticDeploy(): void
    {
        $cs = new ChangeSet();
        $cs->themeFiles = ['app/design/frontend/Nola/default/web/css/styles.less'];
        $cs->allFiles = $cs->themeFiles;

        $this->assertFalse($this->analyzer->needsDiCompile($cs));
        $this->assertTrue($this->analyzer->needsStaticDeploy($cs));
    }

    public function testDbSchemaChangeNeedsUpgrade(): void
    {
        $cs = new ChangeSet();
        $cs->dbSchemaFiles = ['app/code/Vendor/Module/etc/db_schema.xml'];
        $cs->allFiles = $cs->dbSchemaFiles;

        $this->assertTrue($this->analyzer->needsSetupUpgrade($cs));
    }

    public function testGetChangedThemesFromThemeFiles(): void
    {
        $cs = new ChangeSet();
        $cs->themeFiles = ['app/design/frontend/Nola/default/web/css/styles.less'];
        $cs->allFiles = $cs->themeFiles;

        $allThemes = ['Nola/default', 'Magento/backend'];
        $changed = $this->analyzer->getChangedThemes($cs, $allThemes);

        $this->assertContains('Nola/default', $changed);
    }

    public function testPhpChangesAffectAllThemes(): void
    {
        $cs = new ChangeSet();
        $cs->phpFiles = ['app/code/Vendor/Module/Block/Test.php'];
        $cs->allFiles = $cs->phpFiles;

        $allThemes = ['Nola/default', 'Magento/backend'];
        $changed = $this->analyzer->getChangedThemes($cs, $allThemes);

        $this->assertEquals($allThemes, $changed);
    }

    public function testChangeSummary(): void
    {
        $cs = new ChangeSet();
        $cs->phpFiles = ['file.php'];
        $cs->themeFiles = ['theme.xml'];
        $cs->allFiles = ['file.php', 'theme.xml'];

        $summary = $cs->getSummary();
        $this->assertStringContainsString('1 PHP file(s)', $summary);
        $this->assertStringContainsString('1 theme file(s)', $summary);
    }

    public function testFullRebuildSummary(): void
    {
        $cs = new ChangeSet();
        $cs->isFullRebuild = true;

        $this->assertStringContainsString('Full rebuild', $cs->getSummary());
    }

    public function testNoChangesSummary(): void
    {
        $cs = new ChangeSet();
        $this->assertStringContainsString('No changes', $cs->getSummary());
    }
}
