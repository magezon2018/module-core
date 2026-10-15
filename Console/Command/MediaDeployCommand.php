<?php
/**
 * Magezon
 *
 * This source file is subject to the Magezon Software License, which is available at https://www.magezon.com/license
 * Do not edit or add to this file if you wish to upgrade the to newer versions in the future.
 * If you wish to customize this module for your needs.
 * Please refer to https://www.magezon.com for more information.
 *
 * @category  Magezon
 * @package   Magezon_Core
 * @copyright Copyright (C) 2026 Magezon (https://www.magezon.com)
 */

namespace Magezon\Core\Console\Command;

use Magezon\Core\Model\Media\Seeder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento magezon:media:deploy [module …] [--force] [--dry-run]`
 *
 * The manual half of the media seeding that `Magezon\Core\Setup\Recurring`
 * performs automatically on `setup:upgrade`. Two jobs it does that the
 * automatic run cannot:
 *
 *  - repair on demand, without asking a merchant to run a full upgrade;
 *  - `--force`, the only way to restore a shipped asset that was overwritten
 *    locally (the automatic run never overwrites, by design).
 */
class MediaDeployCommand extends Command
{
    const ARGUMENT_MODULE = 'module';
    const OPTION_FORCE    = 'force';
    const OPTION_DRY_RUN  = 'dry-run';

    /**
     * @var Seeder
     */
    private $seeder;

    /**
     * @param Seeder      $seeder
     * @param string|null $name
     */
    public function __construct(Seeder $seeder, $name = null)
    {
        $this->seeder = $seeder;
        parent::__construct($name);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('magezon:media:deploy')
            ->setDescription('Copy the media files shipped by installed Magezon modules into pub/media')
            ->addArgument(
                self::ARGUMENT_MODULE,
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Magezon module name(s), e.g. Magezon_Core. Default: every enabled Magezon module.'
            )
            ->addOption(
                self::OPTION_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Overwrite files that already exist in pub/media (default: keep them)'
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'List the modules that ship media and exit without writing anything'
            );

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modules = (array) $input->getArgument(self::ARGUMENT_MODULE);
        $force   = (bool) $input->getOption(self::OPTION_FORCE);

        // A name of another vendor is silently dropped by the seeder; say so
        // rather than let it look like the module simply ships no media.
        foreach ($modules as $moduleName) {
            if (!$this->seeder->isSupported($moduleName)) {
                $output->writeln(sprintf(
                    '<comment>Skipping %s: only %s* modules are seeded.</comment>',
                    $moduleName,
                    Seeder::VENDOR_PREFIX
                ));
            }
        }

        if ($input->getOption(self::OPTION_DRY_RUN)) {
            return $this->reportContributors($output, $modules);
        }

        $report = $this->seeder->seed($modules, $force);

        if (!$report) {
            $output->writeln('<comment>No enabled Magezon module ships a media/ directory.</comment>');

            return 0;
        }

        return $this->reportResult($output, $report);
    }

    /**
     * `--dry-run`: which modules would be read, and from where.
     *
     * @param  OutputInterface $output
     * @param  string[]        $modules
     * @return int
     */
    private function reportContributors(OutputInterface $output, array $modules)
    {
        $contributors = $this->seeder->getContributingModules();

        if ($modules) {
            $contributors = array_values(array_intersect($contributors, $modules));
        }

        if (!$contributors) {
            $output->writeln('<comment>No enabled Magezon module ships a media/ directory.</comment>');

            return 0;
        }

        foreach ($contributors as $moduleName) {
            $output->writeln(sprintf('<info>%s</info> — %s', $moduleName, $this->seeder->getSourceDir($moduleName)));
        }

        return 0;
    }

    /**
     * Render the per-module outcome; a non-zero exit code when anything failed,
     * so a deployment script notices.
     *
     * @param  OutputInterface $output
     * @param  array           $report
     * @return int
     */
    private function reportResult(OutputInterface $output, array $report)
    {
        $failed = 0;

        foreach ($report as $moduleName => $result) {
            $copied  = count($result[Seeder::COPIED]);
            $skipped = count($result[Seeder::SKIPPED]);
            $failed += count($result[Seeder::FAILED]);

            $output->writeln(sprintf(
                '<info>%s</info>: %d copied, %d already present',
                $moduleName,
                $copied,
                $skipped
            ));

            foreach ($result[Seeder::COPIED] as $path) {
                $output->writeln('  + pub/media/' . $path);
            }

            foreach ($result[Seeder::FAILED] as $failure) {
                $output->writeln('  <error>! ' . $failure . '</error>');
            }
        }

        return $failed > 0 ? 1 : 0;
    }
}
