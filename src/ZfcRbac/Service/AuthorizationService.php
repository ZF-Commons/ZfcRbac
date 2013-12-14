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

namespace ZfcRbac\Service;

use Traversable;
use Zend\EventManager\EventManagerAwareInterface;
use Zend\EventManager\EventManagerAwareTrait;
use Zend\Permissions\Rbac\Rbac;
use ZfcRbac\Assertion\AssertionInterface;
use ZfcRbac\Exception;
use ZfcRbac\Identity\IdentityInterface;
use ZfcRbac\Identity\IdentityProviderInterface;
use Zend\Permissions\Rbac\RoleInterface;
use RecursiveIteratorIterator;

/**
 * Authorization service is a simple service that internally uses a Rbac container
 */
class AuthorizationService implements EventManagerAwareInterface
{
    use EventManagerAwareTrait;

    /**
     * @var Rbac
     */
    protected $rbac;

    /**
     * @var IdentityProviderInterface
     */
    protected $identityProvider;

    /**
     * @var string
     */
    protected $guestRole;

    /**
     * Is the container correctly loaded?
     *
     * @var bool
     */
    protected $isLoaded = false;

    /**
     * Should we force reload the roles and permissions each time isGranted is called?
     *
     * This can be used for very complex use cases with tons of roles and permissions, so that
     * it can triggers database queries only for a given role/permission couple
     *
     * @var bool
     */
    protected $forceReload = false;

    /**
     * Constructor
     *
     * @param Rbac                      $rbac
     * @param IdentityProviderInterface $identityProvider
     * @param string                    $guestRole
     */
    public function __construct(Rbac $rbac, IdentityProviderInterface $identityProvider, $guestRole = '')
    {
        $this->rbac             = $rbac;
        $this->identityProvider = $identityProvider;
        $this->guestRole        = $guestRole;

        // We register the guest role inside the container
        if ($this->guestRole) {
            $this->rbac->addRole($this->guestRole);
        }
    }

    /**
     * Get the Rbac container
     *
     * @return Rbac
     */
    public function getRbac()
    {
        $this->load();

        return $this->rbac;
    }

    /**
     * Get the identity roles from the identity, applying some more logic
     *
     * @return string[]|\Zend\Permissions\Rbac\RoleInterface[]
     */
    public function getIdentityRoles()
    {
        $identity = $this->identityProvider->getIdentity();

        if (null === $identity) {
            return empty($this->guestRole) ? [] : [$this->guestRole];
        }

        if (!$identity instanceof IdentityInterface) {
            throw new Exception\RuntimeException(sprintf(
                'ZfcRbac expects your identity to implement ZfcRbac\Identity\IdentityInterface, "%s" given',
                is_object($identity) ? get_class($identity) : gettype($identity)
            ));
        }

        $roles = $identity->getRoles();

        if ($roles instanceof Traversable) {
            $roles = iterator_to_array($roles);
        }

        return (array) $roles;
    }

    /**
     * Set if we should force reload each time isGranted is called
     *
     * @param boolean $forceReload
     * @param void
     */
    public function setForceReload($forceReload)
    {
        $this->forceReload = (bool) $forceReload;
    }

    /**
     * Check if the permission is granted to the current identity
     *
     * Note: if an identity has multiple role, ALL the roles must be granted for the permission
     * to be granted
     *
     * @param  string                           $permission
     * @param  callable|AssertionInterface|null $assertion
     * @return bool
     * @throws Exception\InvalidArgumentException If an invalid assertion is passed
     */
    public function isGranted($permission, $assertion = null)
    {
        $roles = $this->getIdentityRoles();

        if (empty($roles)) {
            return false;
        }

        // First load everything inside the container
        $this->load($roles, $permission);

        // Check the assertion first
        if (null !== $assertion) {
            $identity = $this->identityProvider->getIdentity();

            if (is_callable($assertion)) {
                if (!$assertion($identity)) {
                    return false;
                }
            } elseif ($assertion instanceof AssertionInterface) {
                if (!$assertion->assert($identity)) {
                    return false;
                }
            } else {
                throw new Exception\InvalidArgumentException(sprintf(
                    'Assertions must be callable or implement ZfcRbac\Assertion\AssertionInterface, "%s" given',
                    is_object($assertion) ? get_class($assertion) : gettype($assertion)
                ));
            }
        }

        foreach ($roles as $role) {
            // If role does not exist, we consider this as not valid
            if (!$this->rbac->hasRole($role)) {
                return false;
            }

            if ($this->rbac->isGranted($role, $permission)) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Returns true, if the current identity holds (statisfies) one of the given roles.
     * 
     * @todo Refactor
     * @internal This is a hotfix for AbstractGuard and should not be used by any other component.
     * @param Traversable|array $roles
     * @throws Exception\InvalidArgumentException
     * @return boolean
     */
    public function doesIdentityStatisfyRoles($roles)
    {
        $identityRoles = $this->getIdentityRoles();

        if (empty($identityRoles)) {
            return false;
        }

        // Load everything inside the container
        $this->load($identityRoles);
        
        // If roles is an instance of RoleInterface, we convert it to a string.
        $roleNames = [];
        foreach ($roles as $role) {
            if ($role instanceof RoleInterface) {
                $roleNames[] = $role->getName();
            } else {
                $roleNames[] = $role;
            }
        }
            
        $flattenedIdentityRoles = $this->flattenRoles($identityRoles);
        
        // check for intersetions
        $intersect = array_intersect($flattenedIdentityRoles, $roleNames);
        
        if (!empty($intersect)) {
            // Intersection found -> identity has at least one of the required roles
            return true;
        }
        
        return false;
    }
    
    /**
     * Flattens a given set of roles.
     * 
     * @param array $roles
     * @return array
     */
    protected function flattenRoles(array $roles)
    {
        $flattenedRoles = [];
        
        foreach ($roles as $role) {
        
            // Skip duplicates (saves us some time)
            if (in_array($role, $flattenedRoles)) {
                continue;
            }

            if ($role instanceof RoleInterface) {
                $flattenedRoles[] = $role->getName();
            } else {
                $flattenedRoles[] = $role;
            }
             
            if ($this->rbac->hasRole($role)) {
                $roleFromRbac = $this->rbac->getRole($role);
        
                // We need to iterate through the identities children.
                $it = new RecursiveIteratorIterator($roleFromRbac, RecursiveIteratorIterator::SELF_FIRST);
                foreach ($it as $leaf) {
        
                    if (in_array($leaf->getName(), $flattenedRoles)) {
                        // Skip treesearch, as we've been here already
                        break;
                    }
        
                    $flattenedRoles[] = $leaf->getName();
                }
        
            }
        }
        
        return $flattenedRoles;
    }

    /**
     * Load roles and permissions inside the container by triggering load events
     *
     * @see \ZfcRbac\Role\RoleLoaderListener
     * @see \ZfcRbac\Provider\ProviderLoaderListener
     *
     * @param  array  $roles
     * @param  string $permission
     * @return void
     */
    protected function load(array $roles = [], $permission = '')
    {
        if ($this->isLoaded && !$this->forceReload) {
            return;
        }

        $eventManager = $this->getEventManager();

        $rbacEvent = new RbacEvent($this->rbac, $roles, $permission);
        $rbacEvent->setTarget($this);

        $eventManager->trigger(RbacEvent::EVENT_LOAD_ROLES, $rbacEvent);
        $eventManager->trigger(RbacEvent::EVENT_LOAD_PERMISSIONS, $rbacEvent);

        $this->isLoaded = true;
    }
}
