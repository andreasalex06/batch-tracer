<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->group('api/v1', static function ($routes) {
    $routes->get('session', 'Api::session');
    $routes->post('login', 'Api::login', ['filter'=>'csrf']);
    $routes->post('logout', 'Api::logout', ['filter'=>'csrf']);
    $routes->get('catalog/(:segment)', 'Workspace::catalog/$1');
    $routes->post('catalog/(:segment)', 'Workspace::saveCatalog/$1', ['filter'=>'csrf']);
    $routes->post('catalog/(:segment)/(:num)', 'Workspace::saveCatalog/$1/$2', ['filter'=>'csrf']);
    $routes->get('dashboard', 'Workspace::dashboard');
    $routes->get('batches', 'Workspace::batches');
    $routes->post('batches', 'Workspace::createBatch', ['filter'=>'csrf']);
    $routes->get('batches/(:num)', 'Workspace::batch/$1');
    $routes->get('batches/(:num)/traceability', 'Workspace::batch/$1');
    $routes->get('ingredient-lots/(:num)/traceability', 'Workspace::lot/$1');
    $routes->post('batches/(:num)/ingredients', 'Workspace::ingredient/$1', ['filter'=>'csrf']);
    $routes->post('batches/(:num)/transition', 'Workspace::transition/$1', ['filter'=>'csrf']);
    $routes->post('batches/(:num)/quality-checks', 'Workspace::check/$1', ['filter'=>'csrf']);
    $routes->post('batches/(:num)/qa-decision', 'Workspace::decision/$1', ['filter'=>'csrf']);
    $routes->get('inventory/movements', 'Workspace::listing/movements');
    $routes->post('inventory/movements', 'Workspace::movement', ['filter'=>'csrf']);
    $routes->get('shipments', 'Workspace::listing/shipments');
    $routes->post('shipments', 'Workspace::ship', ['filter'=>'csrf']);
    $routes->post('shipments/(:num)/reverse', 'Workspace::reverse/$1', ['filter'=>'csrf']);
    $routes->post('shipments/(:num)/dispatch', 'Workspace::dispatch/$1', ['filter'=>'csrf']);
    $routes->get('complaints', 'Workspace::listing/complaints');
    $routes->post('complaints', 'Workspace::complaint', ['filter'=>'csrf']);
    $routes->get('recall-cases', 'Workspace::listing/recall-cases');
    $routes->post('recall-cases', 'Workspace::recall', ['filter'=>'csrf']);
    $routes->get('recall-cases/(:num)', 'Workspace::recallDetail/$1');
    $routes->get('recall-cases/(:num)/reconciliation', 'Workspace::reconciliation/$1');
    $routes->get('recall-cases/(:num)/export', 'Workspace::export/$1');
    $routes->post('recall-cases/(:num)/destination-responses', 'Workspace::respond/$1', ['filter'=>'csrf']);
    $routes->post('recall-cases/(:num)/close', 'Workspace::closeRecall/$1', ['filter'=>'csrf']);
    $routes->post('recall-cases/(:num)/review', 'Workspace::reviewRecall/$1', ['filter'=>'csrf']);
    $routes->get('audit', 'Workspace::listing/audit');
});
