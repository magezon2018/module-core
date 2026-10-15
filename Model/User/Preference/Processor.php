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
 * Composite over the DI-contributed preference handlers.
 *
 * Same shape as Magento's own `SearchCriteria\CollectionProcessor`: an array of
 * named steps injected through di.xml, applied in declaration order.
 *
 *     <type name="Magezon\Core\Model\User\Preference\Processor">
 *         <arguments>
 *             <argument name="readHandlers" xsi:type="array">
 *                 <item name="api_key" xsi:type="object">Vendor\Module\...\ReadHandler</item>
 *             </argument>
 *         </arguments>
 *     </type>
 */
class Processor implements ProcessorInterface
{
    /**
     * @var HandlerInterface[]
     */
    private $readHandlers;

    /**
     * @var HandlerInterface[]
     */
    private $writeHandlers;

    /**
     * @param HandlerInterface[] $readHandlers
     * @param HandlerInterface[] $writeHandlers
     */
    public function __construct(
        array $readHandlers = [],
        array $writeHandlers = []
    ) {
        // Validated here rather than per call: a mis-declared handler is a wiring
        // mistake, and failing at construction points at the di.xml that caused it
        // instead of at whichever request happened to touch preferences first.
        $this->assertHandlers($readHandlers, 'readHandlers');
        $this->assertHandlers($writeHandlers, 'writeHandlers');

        $this->readHandlers  = $readHandlers;
        $this->writeHandlers = $writeHandlers;
    }

    /**
     * @inheritDoc
     */
    public function processRead(array $preferences): array
    {
        return $this->run($this->readHandlers, $preferences);
    }

    /**
     * @inheritDoc
     */
    public function processWrite(array $preferences): array
    {
        return $this->run($this->writeHandlers, $preferences);
    }

    /**
     * @param HandlerInterface[] $handlers
     * @param array $preferences
     * @return array
     */
    private function run(array $handlers, array $preferences): array
    {
        foreach ($handlers as $handler) {
            $preferences = $handler->process($preferences);
        }

        return $preferences;
    }

    /**
     * @param array $handlers
     * @param string $argument
     * @return void
     * @throws \InvalidArgumentException
     */
    private function assertHandlers(array $handlers, string $argument): void
    {
        foreach ($handlers as $name => $handler) {
            if (!$handler instanceof HandlerInterface) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Preference handler "%s" declared in %s is a %s; it must implement %s.',
                        $name,
                        $argument,
                        get_debug_type($handler),
                        HandlerInterface::class
                    )
                );
            }
        }
    }
}
