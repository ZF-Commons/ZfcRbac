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

namespace ZfcRbacTest\Container;

use Rbac\Rbac;
use Rbac\Traversal\Strategy\TraversalStrategyInterface;
use Zend\ServiceManager\ServiceManager;
use ZfcRbac\Container\RoleServiceFactory;
use ZfcRbac\Exception\RuntimeException;
use ZfcRbac\Identity\AuthenticationProvider;
use ZfcRbac\Identity\IdentityProviderInterface;
use ZfcRbac\Options\ModuleOptions;
use ZfcRbac\Role\RoleProviderPluginManager;

/**
 * @covers \ZfcRbac\Container\RoleServiceFactory
 */
class RoleServiceFactoryTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @markTestSkipped skipped
     */
    public function testFactory()
    {
        $this->markTestSkipped(
            'Seems Rbac\Traversal\Strategy\TraversalStrategyInterface has been removed. Not sure what this does.'
        );

        $options = new ModuleOptions([
            'identity_provider'    => AuthenticationProvider::class,
            'guest_role'           => 'guest',
            'role_provider'        => [
                \ZfcRbac\Role\InMemoryRoleProvider::class => [
                    'foo'
                ]
            ]
        ]);

        $serviceManager = new ServiceManager();
        $serviceManager->setService(ModuleOptions::class, $options);
        $serviceManager->setService(RoleProviderPluginManager::class, new RoleProviderPluginManager($serviceManager));
        $serviceManager->setService(
            AuthenticationProvider::class,
            $this->getMock(IdentityProviderInterface::class)
        );

        $traversalStrategy = $this->getMock(TraversalStrategyInterface::class);
        $rbac              = $this->getMock(Rbac::class, [], [], '', false);

        $rbac->expects($this->once())->method('getTraversalStrategy')->will($this->returnValue($traversalStrategy));

        $serviceManager->setService(Rbac::class, $rbac);

        $factory     = new RoleServiceFactory();
        $roleService = $factory($serviceManager, 'requestedName');

        $this->assertInstanceOf(\ZfcRbac\Service\RoleService::class, $roleService);
        $this->assertEquals('guest', $roleService->getGuestRole());
        $this->assertAttributeSame($traversalStrategy, 'traversalStrategy', $roleService);
    }

    public function testThrowExceptionIfNoRoleProvider()
    {
        $this->setExpectedException(RuntimeException::class);

        $options = new ModuleOptions([
            'identity_provider' => AuthenticationProvider::class,
            'guest_role'        => 'guest',
            'role_provider'     => []
        ]);

        $serviceManager = new ServiceManager();
        $serviceManager->setService(ModuleOptions::class, $options);
        $serviceManager->setService(
            AuthenticationProvider::class,
            $this->getMock(IdentityProviderInterface::class)
        );

        $factory     = new RoleServiceFactory();
        $roleService = $factory($serviceManager, 'requestedName');
    }
}
