<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminExportControllerTest extends WebTestCase
{
    public function testAdminExportsRoutesStayAvailable(): void
    {
        $router = static::getContainer()->get('router');

        $this->assertNotNull($router->getRouteCollection()->get('app_admin_export'));
        $this->assertNotNull($router->getRouteCollection()->get('app_admin_export_csv'));
        $this->assertNotNull($router->getRouteCollection()->get('app_admin_order_show'));
        $this->assertNotNull($router->getRouteCollection()->get('app_admin_order_update'));
        $this->assertSame('/admin/exports/workbook', $router->getRouteCollection()->get('app_admin_export')->getPath());
        $this->assertSame('/admin/exports/{type}.csv', $router->getRouteCollection()->get('app_admin_export_csv')->getPath());
        $this->assertSame('/admin/orders/{id}', $router->getRouteCollection()->get('app_admin_order_show')->getPath());
        $this->assertSame('/admin/orders/{id}/update', $router->getRouteCollection()->get('app_admin_order_update')->getPath());
    }
}
