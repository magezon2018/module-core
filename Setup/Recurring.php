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

namespace Magezon\Core\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magezon\Core\Model\Media\Seeder;
use Psr\Log\LoggerInterface;

/**
 * Seed every enabled Magezon module's shipped media into `pub/media` on each
 * `setup:upgrade`.
 *
 * A `Setup\Recurring` script is re-executed on EVERY upgrade (unlike a data
 * patch, which records itself in `patch_list` and never runs twice) — which is
 * exactly the semantic wanted here: the assets are a product dependency, so an
 * install that lost them must be able to heal itself.
 *
 * Living in `Magezon_Core` covers the whole catalogue at once: Core is required
 * by every Magezon module, so one recurring script seeds all of them — including
 * modules installed later, since the seeder re-scans the enabled list each run.
 *
 * Copying files is neither schema nor data, so the phase is a free choice; the
 * schema phase is used because it runs earlier, letting any data patch that
 * needs an asset already find it on disk.
 */
class Recurring implements InstallSchemaInterface
{
    /**
     * @var Seeder
     */
    private $seeder;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Seeder          $seeder
     * @param LoggerInterface $logger
     */
    public function __construct(Seeder $seeder, LoggerInterface $logger)
    {
        $this->seeder = $seeder;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        // Media is an asset, never a prerequisite of the schema itself: a
        // read-only pub/media or a single unreadable file must not be able to
        // abort the merchant's setup:upgrade. Report and carry on.
        try {
            $this->seeder->seed();
        } catch (\Exception $e) {
            $this->logger->error('Magezon media seeding failed: ' . $e->getMessage());
        }
    }
}
