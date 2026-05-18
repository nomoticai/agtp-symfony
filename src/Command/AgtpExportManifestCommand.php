<?php

declare(strict_types=1);

namespace Agtp\Symfony\Command;

use Agtp\HandlerRegistry;
use Agtp\ManifestExporter;
use Agtp\Symfony\Registry\AgtpHandlerCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Symfony Console command: ``bin/console agtp:export-manifest``.
 *
 * Generates daemon-side endpoint TOML files from the registered
 * ``#[AgtpEndpoint]`` attributes. Closes the silent-drift gap between
 * handler attributes and agtpd's endpoint manifest: the attribute is
 * the source of truth, this command projects it to the daemon's
 * filesystem format.
 *
 * Typical use, run on the Symfony app host:
 *
 *     bin/console agtp:export-manifest --output=/etc/agtpd/endpoints
 *
 * Or to a staging directory under the app for review before deploy:
 *
 *     bin/console agtp:export-manifest --output=var/agtp/endpoints
 */
#[AsCommand(
    name: 'agtp:export-manifest',
    description: 'Export AGTP endpoint TOML files from registered handlers.',
)]
final class AgtpExportManifestCommand extends Command
{
    public function __construct(
        private readonly AgtpHandlerCollector $collector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'output',
                null,
                InputOption::VALUE_REQUIRED,
                'Directory to write endpoint TOML files into. One file per handler.',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Print TOML to stdout instead of writing files.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $registry = HandlerRegistry::default();
        $count = 0;
        foreach ($this->collector->collect($registry) as $_) {
            $count++;
        }
        if ($count === 0) {
            $io->warning('No services tagged "agtp.endpoint" were found.');
            return Command::SUCCESS;
        }

        $exporter = new ManifestExporter($registry);

        if ((bool) $input->getOption('dry-run')) {
            $output->writeln($exporter->renderAll());
            return Command::SUCCESS;
        }

        $outDir = (string) $input->getOption('output');
        if ($outDir === '') {
            $io->error('--output is required (or pass --dry-run to preview).');
            return Command::FAILURE;
        }

        $written = $exporter->writeToDirectory($outDir);
        $io->success(sprintf(
            'Wrote %d endpoint TOML file(s) to %s.',
            count($written),
            $outDir,
        ));
        foreach ($written as $path) {
            $io->writeln('  - ' . $path);
        }
        return Command::SUCCESS;
    }
}
