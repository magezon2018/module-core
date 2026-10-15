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

use Magento\Backend\Model\Auth\Session as AuthSession;

/**
 * The one way in and out of `admin_user.extra['persisted_preferences']`.
 *
 * Everything that serves preferences to the client MUST go through {@see get()}
 * so the read pipeline runs exactly once, everywhere. This class exists because
 * it previously did not: the editor's `DefaultConfigProvider` read the raw extra
 * for its SSR `serverData` payload while only the GET controller ran the
 * pipeline — and the client's persistence layer short-circuits on `serverData`
 * (`if (s) return s`, and an empty PHP array serialises to a truthy `[]`), so in
 * a normal editor boot the GET controller was never called at all. Measured on
 * `editor.localhost` 2026-07-29: a full boot (122 `window.mgz` keys) issued zero
 * requests to `mgzcore/users`. Every read-handler contribution was therefore
 * dead code on the only path that mattered.
 *
 * Null-safe by construction rather than by plugin: on the frontend area (the
 * Frontend Editor shell) there is no admin auth-session user, and the config
 * providers still run. Returning an empty tree is exactly "this user has stored
 * nothing".
 */
class Repository
{
    /**
     * Key inside `admin_user.extra`. Named by the WordPress preferences package
     * the client half is derived from — do not rename, stored data uses it.
     */
    const EXTRA_KEY = 'persisted_preferences';

    /**
     * @var AuthSession
     */
    private $authSession;

    /**
     * @var ProcessorInterface
     */
    private $processor;

    /**
     * @param AuthSession $authSession
     * @param ProcessorInterface $processor
     */
    public function __construct(
        AuthSession $authSession,
        ProcessorInterface $processor
    ) {
        $this->authSession = $authSession;
        $this->processor   = $processor;
    }

    /**
     * Stored preferences with the read pipeline applied. Use this for anything
     * client-facing.
     *
     * @return array
     */
    public function get(): array
    {
        return $this->processor->processRead($this->getRaw());
    }

    /**
     * Stored preferences exactly as persisted, no handlers.
     *
     * @return array
     */
    public function getRaw(): array
    {
        $extra = $this->getExtra();

        return isset($extra[self::EXTRA_KEY]) && is_array($extra[self::EXTRA_KEY])
            ? $extra[self::EXTRA_KEY]
            : [];
    }

    /**
     * Run the write pipeline and persist. Returns what was actually stored —
     * which is not what was submitted whenever a handler diverted a key
     * elsewhere and stripped it.
     *
     * @param array $preferences
     * @return array
     */
    public function save(array $preferences): array
    {
        $user = $this->authSession->getUser();
        if (!$user || !$user->getId()) {
            return [];
        }

        $stored = $this->processor->processWrite($preferences);

        $extra = $this->getExtra();
        $extra[self::EXTRA_KEY] = $stored;

        $user->saveExtra($extra);

        // `User::saveExtra()` UPDATEs the row directly and never touches the
        // loaded model. The admin auth session holds that very object, so
        // without this the session keeps serving the pre-save value — the next
        // page render would emit stale `serverData` and the save would look lost.
        $user->setExtra($extra);

        return $stored;
    }

    /**
     * @return array
     */
    private function getExtra(): array
    {
        $user = $this->authSession->getUser();
        if (!$user) {
            return [];
        }

        // Already an array: the user resource model unserialises `extra` in
        // `_afterLoad()`. The cast only covers a null/scalar left by a partially
        // built user object.
        return (array) $user->getExtra();
    }
}
