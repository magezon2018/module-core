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
 * One step in the admin-user preference pipeline.
 *
 * Handlers are contributed by other modules through the `readHandlers` /
 * `writeHandlers` arguments of {@see Processor} and run in DI declaration order,
 * each receiving the previous handler's output. A handler may add keys the client
 * needs but the user never stored (read), or divert keys to their real home and
 * strip them before persistence (write) — see the AI Content Generator's API-key
 * pair for the canonical example of both halves.
 *
 * Preferences are the admin user's own UI state, persisted in
 * `admin_user.extra['persisted_preferences']`. They are NOT Magento system
 * config; a handler that writes `core_config_data` is doing something
 * deliberately exceptional and owns the ACL check for it.
 */
interface HandlerInterface
{
    /**
     * @param array $preferences
     * @return array
     */
    public function process(array $preferences): array;
}
