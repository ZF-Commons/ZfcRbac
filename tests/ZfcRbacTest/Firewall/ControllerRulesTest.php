<?php

namespace ZfcRbacTest\Firewall;

use PHPUnit_Framework_TestCase;
use ZfcRbac\Firewall\ControllerRules as ControllerFirewall;

class ControllerRulesTest extends PHPUnit_Framework_TestCase
{
    public function getControllerFirewallParameters()
    {
        return array(
            array(
                array(
                    'rules' => array(
                        'actions' => 'foo',
                        'roles' => 'guest'
                    )
                ),
                'controllerName' => 'IndexController',
                array(
                    array(
                        'resource' => 'IndexController:foo',
                        'result' => true
                    ),
                    array(
                        'resource' => 'IndexController:bar',
                        'result' => false
                    ),
                )
            ),
        );
    }

    /**
     * @dataProvider getControllerFirewallParameters
     */
    public function testControllerRulesFirewall($rules, $controllerName, $checks)
    {
        $firewall = new ControllerFirewall($rules, $controllerName);
        $mockRbac = $this->getMock('ZfcRbac\Service\Rbac');
        $mockRbac->expects($this->any())
            ->method('hasRole')
            ->will($this->returnCallback(function ($val) {
                return $val === array('guest');
            }));

        $firewall->setRbac($mockRbac);

        foreach ($checks as $check) {
            $this->assertEquals($check['result'], $firewall->isGranted($check['resource']));
        }
    }
}
