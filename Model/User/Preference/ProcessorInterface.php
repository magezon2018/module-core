<?php
/**
 * Magezon
 *
 * This source file is subject to the Magezon Software License, which is available at https://www.magezon.com/license.
 * Do not edit or add to this file if you wish to upgrade the to newer versions in the future.
 * If you wish to customize this module for your needs.
 * Please refer to https://www.magezon.com for more information.
 *
 * @category  Magezon
 * @package   Magezon_Core
 * @copyright Copyright (C) 2019 Magezon (https://www.magezon.com)
 */

namespace Magezon\Core\Model\User\Preference;

/**
 * Runs the contributed {@see HandlerInterface} pipelines over a preference tree.
 */
interface ProcessorInterface
{
    /**
     * Apply the read pipeline — on the way OUT to the client.
     *
     * @param array $preferences
     * @return array
     */
    public function processRead(array $preferences): array;

    /**
     * Apply the write pipeline — on the way IN, before persistence.
     *
     * @param array $preferences
     * @return array
     */
    public function processWrite(array $preferences): array;
}
