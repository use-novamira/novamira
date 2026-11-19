<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\ReadMode;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The permission callback of an Ability, wrapped so that read mode refuses it.
 *
 * WordPress 6.9's WP_Ability::execute() runs no filter that can veto an execution, and it does not check
 * that the Ability is still registered: an Ability object kept by other code before the read-mode sweep
 * (for example by another MCP adapter) can still be executed, and its permission callback is then the
 * only gate. (WordPress 7.1 adds wp_pre_execute_ability and wp_ability_permission_result, which read
 * mode also uses, see novamira_read_mode_pre_execute_ability().) The guard is bound to its Ability's name at registration and decides when it is called, so it
 * also holds when read mode is turned on after the object was kept.
 *
 * In read mode it refuses unless the name is on the allowlist, the administrator has not turned the Ability
 * off in the Abilities Hub, and the implementation is Novamira's own:
 * once the read-mode sweep has seen the object, the guard verifies that object; before that, it
 * verifies the registration arguments it was created from. It refuses with false, without calling the
 * wrapped callback. Not a WP_Error: WP_Ability::execute() reports a WP_Error from a permission callback
 * with _doing_it_wrong(), which would raise developer notices for other plugins' Abilities. The
 * read-mode wording reaches agents through Novamira's own gates instead. Outside read mode it returns
 * exactly what the wrapped callback returns.
 */
final class PermissionGuard
{
    private \Closure $callback;

    private ?\WP_Ability $ability = null;

    /** The registered permission callback. */
    private mixed $permission_callback;

    /**
     * @param callable $permission_callback The registered permission callback.
     * @param mixed    $execute_callback    The registered execute callback, as this guard saw it.
     * @param bool     $custom_class        Whether the registration asked for a custom ability_class.
     * @param bool     $declared_readonly   Whether the registration carries a `readonly` annotation of true.
     */
    public function __construct(
        public string $ability_name,
        callable $permission_callback,
        private mixed $execute_callback,
        private bool $custom_class,
        private bool $declared_readonly = false,
    ) {
        $this->permission_callback = $permission_callback;
        $this->callback = \Closure::fromCallable($permission_callback);
    }

    /** The permission callback this guard wraps, as it was registered. */
    public function wrapped_callback(): mixed
    {
        return $this->permission_callback;
    }

    /** Verify this object from now on. A guard belongs to one registration, so it binds once. */
    public function bind(\WP_Ability $ability): void
    {
        if ($this->ability !== null || $ability->get_name() !== $this->ability_name) {
            return;
        }

        $this->ability = $ability;
    }

    /** Whether this guard belongs to this exact registered object and its current name. */
    public function is_bound_to(\WP_Ability $ability): bool
    {
        return $this->ability === $ability && $this->ability_name === $ability->get_name();
    }

    /** WordPress passes the Ability input only when the Ability declares an input schema. */
    public function __invoke(mixed ...$arguments): mixed
    {
        if ($this->refuses()) {
            return false;
        }

        return ($this->callback)(...$arguments);
    }

    private function refuses(): bool
    {
        if (!\novamira_is_read_mode()) {
            return false;
        }

        if (!\novamira_read_mode_permits_ability($this->ability_name)) {
            return true;
        }

        if ($this->ability !== null) {
            return !\novamira_read_mode_trusts_ability($this->ability);
        }

        return !$this->registration_is_trusted();
    }

    /** Whether the registration arguments this guard was created from describe an implementation read mode trusts. */
    private function registration_is_trusted(): bool
    {
        if ($this->custom_class) {
            return false;
        }

        if (array_key_exists($this->ability_name, \novamira_read_mode_trusted_abilities())) {
            return \novamira_read_mode_callbacks_match(
                $this->ability_name,
                $this->execute_callback,
                $this->permission_callback,
            );
        }

        return (
            $this->declared_readonly
            && \novamira_read_mode_declared_enabled()
            && \novamira_read_mode_declared_name_ok($this->ability_name)
        );
    }
}
