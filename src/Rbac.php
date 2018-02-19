<?php

declare(strict_types=1);
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

namespace ZfcRbac;

use Generator;
use Traversable;
use ZfcRbac\Role\HierarchicalRoleInterface;
use ZfcRbac\Role\RoleInterface;

/**
 * Rbac object. It is used to check a permission against roles
 */
class Rbac
{
    /**
     * Determines if access is granted by checking the roles for permission.
     *
     * @param  RoleInterface|RoleInterface[]|Traversable $roles
     * @param  string                                    $permission
     * @return bool
     */
    public function isGranted($roles, string $permission): bool
    {
        if ($roles instanceof RoleInterface) {
            $roles = [$roles];
        }

        foreach ($this->flattenRoles($roles) as $role) {
            /* @var \ZfcRbac\Role\RoleInterface $role */
            if ($role->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  RoleInterface[]|Traversable $roles
     * @return Generator
     */
    protected function flattenRoles($roles): Generator
    {
        foreach ($roles as $role) {
            yield $role;

            if (! $role instanceof HierarchicalRoleInterface) {
                continue;
            }

            yield from $this->flattenRoles($role->getChildren());
        }
    }
}
