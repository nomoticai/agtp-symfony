<?php

declare(strict_types=1);

namespace Agtp\Symfony\Tests\Command;

use Agtp\AgtpEndpoint;
use Agtp\EndpointContext;
use Agtp\EndpointResponse;
use Agtp\HandlerRegistry;
use Agtp\Symfony\Command\AgtpExportManifestCommand;
use Agtp\Symfony\Registry\AgtpHandlerCollector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class AgtpExportManifestCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        HandlerRegistry::resetDefault();
        $this->tmpDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'agtp-symfony-export-test-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
        HandlerRegistry::resetDefault();
    }

    public function testWritesTomlFiles(): void
    {
        $collector = new AgtpHandlerCollector([new ExportCommandFixtureHandler()]);
        $command = new AgtpExportManifestCommand($collector);

        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($app->find('agtp:export-manifest'));
        $tester->execute(['--output' => $this->tmpDir]);

        $tester->assertCommandIsSuccessful();
        $this->assertFileExists($this->tmpDir . '/query_status.toml');
        $contents = file_get_contents($this->tmpDir . '/query_status.toml');
        $this->assertStringContainsString('method = "QUERY"', $contents ?: '');
        $this->assertStringContainsString('path = "/status"', $contents ?: '');
    }

    public function testDryRunPrintsToStdout(): void
    {
        $collector = new AgtpHandlerCollector([new ExportCommandFixtureHandler()]);
        $command = new AgtpExportManifestCommand($collector);

        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($app->find('agtp:export-manifest'));
        $tester->execute(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        $this->assertStringContainsString('method = "QUERY"', $display);
        $this->assertFalse(
            is_dir($this->tmpDir),
            'dry-run must not have created the output directory'
        );
    }

    public function testFailsWithoutOutputOrDryRun(): void
    {
        $collector = new AgtpHandlerCollector([new ExportCommandFixtureHandler()]);
        $command = new AgtpExportManifestCommand($collector);

        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($app->find('agtp:export-manifest'));
        $exitCode = $tester->execute([]);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('--output is required', $tester->getDisplay());
    }

    public function testNoHandlersWarning(): void
    {
        $collector = new AgtpHandlerCollector([]);
        $command = new AgtpExportManifestCommand($collector);

        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($app->find('agtp:export-manifest'));
        $tester->execute(['--output' => $this->tmpDir]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('No services tagged', $tester->getDisplay());
    }
}

final class ExportCommandFixtureHandler
{
    #[AgtpEndpoint(method: 'QUERY', path: '/status')]
    public function status(EndpointContext $ctx): EndpointResponse
    {
        return new EndpointResponse(body: ['status' => 'ok']);
    }
}
