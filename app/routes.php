<?php

declare(strict_types=1);

use App\Controllers\ActivityController;
use App\Controllers\AttachmentController;
use App\Controllers\AuthController;
use App\Controllers\CompanyController;
use App\Controllers\ContextController;
use App\Controllers\CustomerController;
use App\Controllers\DashboardController;
use App\Controllers\DeliveryController;
use App\Controllers\DocumentSettingsController;
use App\Controllers\FamilyController;
use App\Controllers\BankController;
use App\Controllers\CustomsAgencyController;
use App\Controllers\ImportController;
use App\Controllers\InvoiceController;
use App\Controllers\ItemController;
use App\Controllers\LicenceImportController;
use App\Controllers\MasterDataImportController;
use App\Controllers\MovementController;
use App\Controllers\OrderController;
use App\Controllers\PortalController;
use App\Controllers\PurchaseOrderController;
use App\Controllers\ReferenceController;
use App\Controllers\ShipmentDocumentController;
use App\Controllers\SiteController;
use App\Controllers\SupplierController;
use App\Controllers\UserController;
use App\Controllers\WarehouseController;
use App\Core\Router;

return static function (Router $r): void {
    $r->get('/login', [AuthController::class, 'showLogin'], null);
    $r->post('/login', [AuthController::class, 'login'], null);
    $r->post('/logout', [AuthController::class, 'logout']);

    $r->post('/context/locale', [ContextController::class, 'locale'], null);
    $r->post('/context/site', [ContextController::class, 'site']);

    $r->get('/', [DashboardController::class, 'index']);
    $r->get('/cockpit/drill', [DashboardController::class, 'drill']);

    $r->get('/items', [ItemController::class, 'index']);
    $r->get('/items/data', [ItemController::class, 'data']);
    $r->get('/items/search', [ItemController::class, 'search']);
    $r->get('/items/new', [ItemController::class, 'create']);
    $r->post('/items', [ItemController::class, 'store']);
    $r->get('/items/{id}', [ItemController::class, 'show']);
    $r->get('/items/{id}/edit', [ItemController::class, 'edit']);
    $r->post('/items/{id}', [ItemController::class, 'update']);

    $r->get('/movements', [MovementController::class, 'index']);
    $r->get('/movements/data', [MovementController::class, 'data']);
    $r->get('/movements/new', [MovementController::class, 'create']);
    $r->post('/movements', [MovementController::class, 'store']);

    $r->get('/warehouses', [WarehouseController::class, 'index']);
    $r->post('/warehouses', [WarehouseController::class, 'store']);
    $r->post('/warehouses/{id}', [WarehouseController::class, 'update']);

    $r->get('/sites', [SiteController::class, 'index']);
    $r->post('/sites', [SiteController::class, 'store']);
    $r->post('/sites/{id}', [SiteController::class, 'update']);

    $r->get('/settings/company', [CompanyController::class, 'edit']);
    $r->post('/settings/company', [CompanyController::class, 'update']);
    $r->post('/settings/company/logo/delete', [CompanyController::class, 'deleteLogo']);
    $r->get('/company/logo', [CompanyController::class, 'logo']);

    $r->get('/settings/import',[MasterDataImportController::class, 'index']);
    $r->post('/settings/import', [MasterDataImportController::class, 'upload']);
    $r->post('/settings/import/confirm', [MasterDataImportController::class, 'confirm']);
    $r->post('/settings/import/cancel', [MasterDataImportController::class, 'cancel']);
    $r->get('/settings/import/template', [MasterDataImportController::class, 'template']);

    $r->get('/users', [UserController::class, 'index']);
    $r->get('/users/new', [UserController::class, 'create']);
    $r->post('/users', [UserController::class, 'store']);
    $r->get('/users/{id}/edit', [UserController::class, 'edit']);
    $r->post('/users/{id}', [UserController::class, 'update']);

    $r->get('/activity', [ActivityController::class, 'index']);

    $r->get('/references/next', [ReferenceController::class, 'next']);

    // Référentiel : familles d'articles
    $r->get('/families', [FamilyController::class, 'index']);
    $r->post('/families', [FamilyController::class, 'store']);
    $r->post('/families/{id}', [FamilyController::class, 'update']);
    $r->post('/families/{id}/delete', [FamilyController::class, 'delete']);

    // Référentiel : agences en douane / transitaires
    $r->get('/customs-agencies', [CustomsAgencyController::class, 'index']);
    $r->post('/customs-agencies', [CustomsAgencyController::class, 'store']);
    $r->get('/customs-agencies/{id}', [CustomsAgencyController::class, 'show']);
    $r->post('/customs-agencies/{id}', [CustomsAgencyController::class, 'update']);

    // Référentiel : banques et agences
    $r->get('/banks', [BankController::class, 'index']);
    $r->post('/banks', [BankController::class, 'store']);
    $r->get('/banks/{id}', [BankController::class, 'show']);
    $r->post('/banks/{id}', [BankController::class, 'update']);
    $r->post('/banks/{id}/delete', [BankController::class, 'delete']);
    $r->post('/banks/{id}/branches', [BankController::class, 'storeBranch']);
    $r->post('/banks/{id}/branches/{branch_id}', [BankController::class, 'updateBranch']);
    $r->post('/banks/{id}/branches/{branch_id}/delete', [BankController::class, 'deleteBranch']);

    // Tiers
    foreach (['/customers' => CustomerController::class, '/suppliers' => SupplierController::class] as $base => $ctrl) {
        $r->get($base, [$ctrl, 'index']);
        $r->get("$base/new", [$ctrl, 'create']);
        $r->post($base, [$ctrl, 'store']);
        $r->get("$base/{id}", [$ctrl, 'show']);
        $r->get("$base/{id}/edit", [$ctrl, 'edit']);
        $r->post("$base/{id}", [$ctrl, 'update']);
    }
    $r->get('/customers/{id}/delete', [CustomerController::class, 'confirmDelete']);
    $r->post('/customers/{id}/delete', [CustomerController::class, 'destroy']);
    $r->post('/customers/{id}/sites', [CustomerController::class, 'saveSite']);
    $r->post('/customers/{id}/sites/{site_id}', [CustomerController::class, 'saveSite']);

    // PO clients (dossier pivot)
    $r->get('/orders', [OrderController::class, 'index']);
    $r->get('/orders/data', [OrderController::class, 'data']);
    $r->get('/orders/new', [OrderController::class, 'create']);
    $r->post('/orders', [OrderController::class, 'store']);
    $r->get('/orders/{id}', [OrderController::class, 'show']);
    $r->get('/orders/{id}/edit', [OrderController::class, 'edit']);
    $r->post('/orders/{id}', [OrderController::class, 'update']);
    $r->post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
    $r->post('/orders/{id}/close', [OrderController::class, 'close']);

    // Sourcing et PO fournisseurs
    $r->post('/orders/{id}/quotes', [PurchaseOrderController::class, 'storeQuote']);
    $r->post('/orders/{id}/quotes/{quote_id}/reject', [PurchaseOrderController::class, 'rejectQuote']);
    $r->get('/orders/{id}/purchase-orders/new', [PurchaseOrderController::class, 'create']);
    $r->post('/orders/{id}/purchase-orders', [PurchaseOrderController::class, 'store']);
    $r->get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    $r->get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
    $r->get('/purchase-orders/{id}/edit', [PurchaseOrderController::class, 'edit']);
    $r->post('/purchase-orders/{id}', [PurchaseOrderController::class, 'update']);
    $r->post('/purchase-orders/{id}/send', [PurchaseOrderController::class, 'send']);
    $r->post('/purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel']);
    $r->get('/purchase-orders/{id}/print', [PurchaseOrderController::class, 'print']);

    // Importation : licences et inspections
    $r->get('/licences', [ImportController::class, 'index']);
    $r->get('/licences/new', [ImportController::class, 'create']);
    // Création depuis un document : téléverser → extraire → vérifier → confirmer
    $r->get('/licences/import', [LicenceImportController::class, 'create']);
    $r->post('/licences/import', [LicenceImportController::class, 'upload']);
    $r->get('/licences/import/{token}', [LicenceImportController::class, 'review']);
    $r->get('/licences/import/{token}/document', [LicenceImportController::class, 'document']);
    $r->post('/licences/import/{token}/save', [LicenceImportController::class, 'save']);
    $r->post('/licences/import/{token}/confirm', [LicenceImportController::class, 'confirm']);
    $r->post('/licences/import/{token}/discard', [LicenceImportController::class, 'discard']);
    $r->post('/licences', [ImportController::class, 'store']);
    $r->get('/licences/{id}', [ImportController::class, 'show']);
    $r->get('/licences/{id}/edit', [ImportController::class, 'edit']);
    $r->post('/licences/{id}', [ImportController::class, 'update']);
    $r->get('/purchase-orders/{id}/inspections/new', [ImportController::class, 'createInspection']);
    $r->post('/purchase-orders/{id}/inspections', [ImportController::class, 'storeInspection']);
    $r->post('/inspections/{id}/decision', [ImportController::class, 'decide']);
    $r->post('/inspections/{id}/report', [ImportController::class, 'attachReport']);

    // Livraisons
    $r->get('/deliveries', [DeliveryController::class, 'index']);
    $r->get('/deliveries/data', [DeliveryController::class, 'data']);
    $r->get('/purchase-orders/{id}/deliveries/new', [DeliveryController::class, 'create']);
    $r->post('/purchase-orders/{id}/deliveries', [DeliveryController::class, 'store']);
    $r->post('/deliveries/{id}/confirm', [DeliveryController::class, 'confirm']);
    $r->post('/deliveries/{id}/cancel', [DeliveryController::class, 'cancel']);
    $r->get('/deliveries/{id}/print', [DeliveryController::class, 'print']);
    $r->get('/deliveries/{id}', [DeliveryController::class, 'show']);
    $r->post('/deliveries/{id}/depart', [DeliveryController::class, 'depart']);
    $r->post('/deliveries/{id}/customs', [DeliveryController::class, 'customs']);
    $r->post('/deliveries/{id}/checklist', [DeliveryController::class, 'refreshChecklist']);

    // Check-list documentaire
    $r->get('/documents/settings', [DocumentSettingsController::class, 'index']);
    $r->get('/documents/settings/simulate', [DocumentSettingsController::class, 'simulate']);
    $r->post('/documents/types', [DocumentSettingsController::class, 'storeType']);
    $r->post('/documents/types/{id}', [DocumentSettingsController::class, 'updateType']);
    $r->post('/documents/rules', [DocumentSettingsController::class, 'storeRule']);
    $r->post('/documents/rules/{id}', [DocumentSettingsController::class, 'updateRule']);
    $r->post('/documents/rules/{id}/delete', [DocumentSettingsController::class, 'deleteRule']);
    $r->post('/documents/rules/bulk-delete', [DocumentSettingsController::class, 'bulkDeleteRules']);
    foreach (['submit', 'validate', 'reject', 'waive', 'reset'] as $action) {
        $r->post("/shipment-documents/{id}/$action", [ShipmentDocumentController::class, $action]);
    }
    $r->get('/shipment-documents/{id}/file', [ShipmentDocumentController::class, 'file']);

    // Portail transitaire
    $r->get('/portal', [PortalController::class, 'index']);
    $r->get('/portal/deliveries/{id}', [PortalController::class, 'delivery']);
    $r->post('/portal/deliveries/{id}/file-number', [PortalController::class, 'fileNumber']);

    // Factures et paiements
    // Factures clients et fournisseurs : deux pages distinctes ; /finance (anciens liens) mène aux factures clients
    $r->get('/finance', [InvoiceController::class, 'legacy']);
    $r->get('/finance/customers', [InvoiceController::class, 'customers']);
    $r->get('/finance/suppliers', [InvoiceController::class, 'suppliers']);
    $r->get('/finance/data', [InvoiceController::class, 'data']);
    $r->post('/orders/{id}/invoices', [InvoiceController::class, 'store']);
    $r->post('/orders/{id}/invoices/preview', [InvoiceController::class, 'preview']);
    $r->get('/invoices/{id}', [InvoiceController::class, 'show']);
    $r->get('/invoices/{id}/print', [InvoiceController::class, 'print']);
    $r->post('/invoices/{id}', [InvoiceController::class, 'update']);
    $r->post('/invoices/{id}/approve', [InvoiceController::class, 'approve']);
    $r->post('/invoices/{id}/payments', [InvoiceController::class, 'pay']);
    $r->post('/invoices/{id}/attachments', [InvoiceController::class, 'attach']);
    $r->post('/invoices/{id}/cancel', [InvoiceController::class, 'cancel']);

    // Pièces jointes
    $r->post('/orders/{id}/attachments', [AttachmentController::class, 'store']);
    $r->get('/attachments/{id}', [AttachmentController::class, 'download']);
};
