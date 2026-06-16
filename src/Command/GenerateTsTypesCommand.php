<?php

declare(strict_types=1);

namespace LiquidRazor\DtoApiBundle\Command;

use LiquidRazor\DtoApiBundle\OpenApi\OpenApiBuilder;
use LiquidRazor\DtoApiBundle\OpenApi\TypeScript\TsTypeGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function dirname;
use function is_dir;
use function is_file;
use function sprintf;

/**
 * Generates TypeScript type definitions from the bundle's OpenAPI document.
 *
 * Reads `components.schemas` off {@see OpenApiBuilder::build()} (the same 3.1
 * document served at `/_schema/openapi.json`) and renders one `export type` per
 * schema via {@see TsTypeGenerator}.
 *
 *   bin/console dto-api:generate:ts-types                       # print to stdout
 *   bin/console dto-api:generate:ts-types -o assets/api.d.ts    # write to a file
 *   bin/console dto-api:generate:ts-types -o assets/api.d.ts --check  # CI guard
 */
#[AsCommand(
    name: 'dto-api:generate:ts-types',
    description: 'Generate TypeScript types from the OpenAPI schema components.',
)]
final class GenerateTsTypesCommand extends Command
{
    public function __construct(
        private readonly OpenApiBuilder $builder,
        private readonly TsTypeGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'File to write the generated .ts to (prints to stdout if omitted)',
            )
            ->addOption(
                'check',
                null,
                InputOption::VALUE_NONE,
                'Exit non-zero if the output file is missing or stale (requires --output)',
            )
            ->addOption(
                'nullable-wrapper',
                null,
                InputOption::VALUE_REQUIRED,
                'Wrap nullable types as Wrapper<T> instead of T | null (e.g. Option)',
            )
            ->addOption(
                'nullable-import',
                null,
                InputOption::VALUE_REQUIRED,
                'Module to import the nullable wrapper from (e.g. ../shared.types)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $nullableWrapper */
        $nullableWrapper = $input->getOption('nullable-wrapper');
        /** @var string|null $nullableImport */
        $nullableImport = $input->getOption('nullable-import');

        $ts = $this->generator->generate($this->builder->build(), $nullableWrapper, $nullableImport);

        /** @var string|null $path */
        $path = $input->getOption('output');

        if ((bool) $input->getOption('check')) {
            if (null === $path) {
                $io->error('--check requires --output.');

                return Command::INVALID;
            }

            $current = is_file($path) ? file_get_contents($path) : null;
            if ($current === $ts) {
                $io->success('TypeScript types are up to date.');

                return Command::SUCCESS;
            }

            $io->error('TypeScript types are stale. Re-run without --check.');

            return Command::FAILURE;
        }

        if (null === $path) {
            // Raw dump — no decoration, so the output pipes cleanly.
            $output->write($ts);

            return Command::SUCCESS;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        file_put_contents($path, $ts);
        $io->success(sprintf('Wrote TypeScript types to %s', $path));

        return Command::SUCCESS;
    }
}
