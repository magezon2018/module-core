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

namespace Magezon\Core\Model\Media;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Module\ModuleListInterface;

/**
 * Materialise the media files a module ships into `pub/media`.
 *
 * A module declares media by convention — anything under its own `media/`
 * directory is copied to the identical path below `pub/media`:
 *
 *     app/code/Magezon/Core/media/magezon/no_image.png
 *         -> pub/media/magezon/no_image.png
 *
 * No per-module code, no registration: the seeder walks every enabled
 * **`Magezon_*`** module and copies whatever it finds.
 *
 * The vendor restriction is deliberate. `Magezon_Core` ships inside the
 * merchant's store as one vendor's extension, and a third-party module writing
 * another vendor's files into `pub/media` on every upgrade would be acting well
 * outside its own product — a surprise nobody opted into, and a support problem
 * the moment two such modules disagree about a path. The convention only binds
 * modules whose vendor also owns this seeder.
 *
 * Why this exists at all. Magento's own answer to "ship media with a package"
 * is the `magento2-component` composer type with `extra.map: [["*","pub/media"]]`
 * (see `magento/sample-data-media`), executed by the composer installer plugin.
 * Magezon extensions are also distributed as plain `app/code` ZIPs, which the
 * merchant unpacks by hand — composer never runs, so that mapping never happens.
 * This class is the same contract re-implemented on the one hook that is always
 * executed either way: `setup:upgrade` (see `Magezon\Core\Setup\Recurring`).
 *
 * Semantics, and the reasons behind them:
 *
 *  - Idempotent, and NON-destructive by default. A destination that already
 *    exists is left untouched, so a merchant who replaced `no_image.png` with
 *    their own artwork does not silently lose it on the next upgrade. Pass
 *    `$force` (CLI `--force`) to overwrite deliberately.
 *  - Recurring rather than one-shot. A `DataPatch` records itself in
 *    `patch_list` and never runs again — a deleted asset could then never be
 *    restored. Running on every upgrade makes the install self-healing.
 *  - Writes through the MEDIA `WriteInterface`, never `copy()`. On a store with
 *    remote storage enabled (S3/Cloud) `pub/media` is not a local directory at
 *    all; only the driver-backed writer reaches it.
 */
class Seeder
{
    /**
     * Directory, relative to a module's root, holding files destined for
     * `pub/media`. Paths below it are preserved verbatim.
     */
    const SOURCE_DIR = 'media';

    /**
     * Only modules of this vendor are seeded — see the class docblock.
     */
    const VENDOR_PREFIX = 'Magezon_';

    /**#@+
     * Report section keys returned by seed().
     */
    const COPIED  = 'copied';
    const SKIPPED = 'skipped';
    const FAILED  = 'failed';
    /**#@-*/

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var ComponentRegistrar
     */
    private $componentRegistrar;

    /**
     * @var ModuleListInterface
     */
    private $moduleList;

    /**
     * @var FileDriver
     */
    private $driver;

    /**
     * @param Filesystem          $filesystem
     * @param ComponentRegistrar  $componentRegistrar
     * @param ModuleListInterface $moduleList
     * @param FileDriver          $driver
     */
    public function __construct(
        Filesystem $filesystem,
        ComponentRegistrar $componentRegistrar,
        ModuleListInterface $moduleList,
        FileDriver $driver
    ) {
        $this->filesystem         = $filesystem;
        $this->componentRegistrar = $componentRegistrar;
        $this->moduleList         = $moduleList;
        $this->driver             = $driver;
    }

    /**
     * Copy the shipped media of the given modules into `pub/media`.
     *
     * Names outside the seeder's vendor are ignored, whether they arrive here
     * explicitly or from the enabled-module list.
     *
     * @param  string[] $moduleNames Module names ('Magezon_Core'); empty = every
     *                               enabled Magezon module.
     * @param  bool     $force       Overwrite destinations that already exist.
     * @return array                 [moduleName => [copied|skipped|failed => paths]]
     *                               Only modules that actually ship media appear.
     */
    public function seed(array $moduleNames = [], $force = false)
    {
        $report = [];

        foreach ($this->resolveModules($moduleNames) as $moduleName) {
            $sourceDir = $this->getSourceDir($moduleName);
            if ($sourceDir === null) {
                continue;
            }

            $report[$moduleName] = $this->seedModule($sourceDir, (bool) $force);
        }

        return $report;
    }

    /**
     * Names of the enabled Magezon modules that ship a `media/` directory.
     *
     * @return string[]
     */
    public function getContributingModules()
    {
        $names = [];

        foreach ($this->resolveModules([]) as $moduleName) {
            if ($this->getSourceDir($moduleName) !== null) {
                $names[] = $moduleName;
            }
        }

        return $names;
    }

    /**
     * Whether the seeder handles this module at all — i.e. whether it belongs to
     * the vendor that owns the seeder. Callers use it to explain a refusal
     * instead of silently returning nothing.
     *
     * @param  string $moduleName
     * @return bool
     */
    public function isSupported($moduleName)
    {
        return strpos((string) $moduleName, self::VENDOR_PREFIX) === 0;
    }

    /**
     * Absolute path of a module's `media/` directory, or null when it ships none
     * (or the module is not registered at all).
     *
     * @param  string $moduleName
     * @return string|null
     */
    public function getSourceDir($moduleName)
    {
        $modulePath = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $moduleName);
        if (!$modulePath) {
            return null;
        }

        $sourceDir = rtrim($modulePath, '/') . '/' . self::SOURCE_DIR;

        return $this->driver->isDirectory($sourceDir) ? $sourceDir : null;
    }

    /**
     * Narrow a caller-supplied list to the modules this seeder acts on: enabled,
     * and of its own vendor. An empty list means "every enabled Magezon module".
     *
     * Disabled modules are dropped on purpose — seeding assets for a module the
     * store does not run would litter `pub/media` with files nothing reads.
     *
     * @param  string[] $moduleNames
     * @return string[]
     */
    private function resolveModules(array $moduleNames)
    {
        $candidates = [];

        foreach ($this->moduleList->getNames() as $moduleName) {
            if ($this->isSupported($moduleName)) {
                $candidates[] = $moduleName;
            }
        }

        if (!$moduleNames) {
            return $candidates;
        }

        return array_values(array_intersect($candidates, $moduleNames));
    }

    /**
     * Walk one module's `media/` tree and copy it.
     *
     * @param  string $sourceDir
     * @param  bool   $force
     * @return array  [copied|skipped|failed => string[]]
     */
    private function seedModule($sourceDir, $force)
    {
        $result = [
            self::COPIED  => [],
            self::SKIPPED => [],
            self::FAILED  => []
        ];

        $mediaDir     = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $prefixLength = strlen(rtrim($sourceDir, '/')) + 1;

        foreach ($this->collectFiles($sourceDir) as $absolutePath) {
            $relativePath = str_replace('\\', '/', substr($absolutePath, $prefixLength));

            if (!$force && $mediaDir->isExist($relativePath)) {
                $result[self::SKIPPED][] = $relativePath;
                continue;
            }

            try {
                // writeFile() creates the parent directories for us (Write::openFile).
                $mediaDir->writeFile($relativePath, $this->driver->fileGetContents($absolutePath));
                $result[self::COPIED][] = $relativePath;
            } catch (\Exception $e) {
                $result[self::FAILED][] = $relativePath . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Every regular file below $sourceDir, sorted for a stable report.
     *
     * Dot-files are skipped: `.gitkeep` exists only so an otherwise empty
     * directory survives git, and `.DS_Store` should never leave a Mac.
     *
     * @param  string $sourceDir
     * @return string[] absolute paths
     */
    private function collectFiles($sourceDir)
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isFile() || strpos($item->getFilename(), '.') === 0) {
                continue;
            }

            $files[] = $item->getPathname();
        }

        sort($files);

        return $files;
    }
}
