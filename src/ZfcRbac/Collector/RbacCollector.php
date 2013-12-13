<?php
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

namespace ZfcRbac\Collector;

use Rbac\Permission\PermissionInterface;
use Rbac\Role\RoleInterface;
use RecursiveIteratorIterator;
use ReflectionProperty;
use Serializable;
use Zend\Mvc\MvcEvent;
use ZendDeveloperTools\Collector\CollectorInterface;
use ZfcRbac\Options\ModuleOptions;
use ZfcRbac\Service\AuthorizationService;

/**
 * RbacCollector
 */
class RbacCollector implements CollectorInterface, Serializable
{
    /**
     * Collector priority
     */
    const PRIORITY = -100;

    /**
     * @var array
     */
    protected $collection = [];

    /**
     * @var array
     */
    protected $collectedGuards = [];

    /**
     * @var array
     */
    protected $collectedRoles = [];

    /**
     * @var array
     */
    protected $collectedPermissions = [];

    /**
     * @var array
     */
    protected $collectedOptions = [];

    /**
     * Collector Name.
     *
     * @return string
     */
    public function getName()
    {
        return 'zfc_rbac';
    }

    /**
     * Collector Priority.
     *
     * @return integer
     */
    public function getPriority()
    {
        return self::PRIORITY;
    }

    /**
     * Collects data.
     *
     * @param MvcEvent $mvcEvent
     */
    public function collect(MvcEvent $mvcEvent)
    {
        if (!$application = $mvcEvent->getApplication()) {
            return;
        }

        $serviceManager = $application->getServiceManager();

        /* @var \ZfcRbac\Service\AuthorizationService $authorizationService */
        $authorizationService = $serviceManager->get('ZfcRbac\Service\AuthorizationService');

        /* @var \ZfcRbac\Options\ModuleOptions $options */
        $options = $serviceManager->get('ZfcRbac\Options\ModuleOptions');

        // Start collect all the data we need!
        $this->collectOptions($options, $authorizationService);
        $this->collectGuards($options->getGuards());
        $this->collectRolesAndPermissions($authorizationService);
    }

    /**
     * Collect options
     *
     * @param ModuleOptions        $moduleOptions
     * @param AuthorizationService $authorizationService
     *
     * @return void
     */
    private function collectOptions(ModuleOptions $moduleOptions, AuthorizationService $authorizationService)
    {
        $identityRoles = $authorizationService->getIdentityRoles();

        $currentRoles = array();
        foreach ($identityRoles as $role) {
            if ($role instanceof RoleInterface) {
                $currentRoles[] = $role->getName();
            } else {
                $currentRoles[] = $role;
            }
        }

        $this->collectedOptions = [
            'current_roles'     => $currentRoles,
            'guest_role'        => $moduleOptions->getGuestRole(),
            'protection_policy' => $moduleOptions->getProtectionPolicy()
        ];
    }

    /**
     * Collect guards
     *
     * @param  array $guards
     *
     * @return void
     */
    private function collectGuards($guards)
    {
        $this->collectedGuards = [];

        foreach ($guards as $type => $rules) {
            $this->collectedGuards[$type] = $rules;
        }
    }

    /**
     * Collect roles and permissions
     *
     * @param  AuthorizationService $authorizationService
     *
     * @return void
     */
    private function collectRolesAndPermissions(AuthorizationService $authorizationService)
    {
        $rbac                 = $authorizationService->getRbac();
        $this->collectedRoles = $this->collectedPermissions = [];

        // Role recursive iterator
        $roles = new RecursiveIteratorIterator($rbac, RecursiveIteratorIterator::CHILD_FIRST);

        /* @var RoleInterface $role */
        foreach ($roles as $role) {
            if (null === $role->getParent()) {
                $this->collectedRoles[] = $role->getName();
            } else {
                $this->collectedRoles[$role->getName()] = $role->getParent()->getName();
            }

            // Rbac does not allow us to retrieve permissions from a role, so we need to use reflection. It
            // obviously adds some overhead but this is the only way to do it
            $reflProperty = new ReflectionProperty($role, 'permissions');
            $reflProperty->setAccessible(true);

            $permissions = $reflProperty->getValue($role);

            foreach ($permissions as $permissionName => $permission) {
                if ($permission instanceof PermissionInterface) {
                    $this->collectedPermissions[$permission->getName()][] = $role->getName();
                } else {
                    $this->collectedPermissions[$permissionName][] = $role->getName();
                }
            }
        }

        // Because multiple roles may have the same permissions, the previous logic may have duplicate roles
        // for each collected permissions, so we need a bit of cleaning
        foreach ($this->collectedPermissions as &$permissions) {
            $permissions = array_unique($permissions);
        }
    }

    /**
     * @return array|string[]
     */
    public function getCollection()
    {
        return $this->collection;
    }

    /**
     * {@inheritDoc}
     */
    public function serialize()
    {
        return serialize([
            'guards'      => $this->collectedGuards,
            'roles'       => $this->collectedRoles,
            'permissions' => $this->collectedPermissions,
            'options'     => $this->collectedOptions
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function unserialize($serialized)
    {
        $this->collection = unserialize($serialized);
    }
}
