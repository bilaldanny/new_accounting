# Route table

Generated from the booted router. `permission` and `tenant scope` are a static check of the controller source (a menu-permission or
gate call, the company scope helpers or a `company_id` filter); page routes are guarded by `EnforcePageMenuPermission`. Closures have no
controller to scan.

| method | route | action | middleware | permission | tenant scope |
|---|---|---|---|---|---|
| DELETE | /api/activities/{activity} | ActivityController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/api-keys/{id} | ApiKeyController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/approval-center/workflows/{id} | ApprovalCenterController@deleteWorkflow | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/asset-categories/{id} | AssetCategoryController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/backups/{id} | BackupController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | none |
| DELETE | /api/bank-instruments/{id} | BankInstrumentController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/bank-issuers/{bank_issuer} | BankIssuerController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/bank-reconciliations/{id} | BankReconciliationController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/banks/{bank} | BankController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/blog-posts/{blog_post} | BlogPostController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | none |
| DELETE | /api/branches/{branch} | BranchController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/brands/{brand} | BrandController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/budgets/{id} | BudgetController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/business-settings/notification-templates/{kind} | BusinessSettingsController@deleteNotificationTemplate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/business-settings/retention/{entity} | BusinessSettingsController@deleteRetention | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/cash-collections/{cash_collection} | CashCollectionController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/cashiers/{id} | CashierController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/categories/{category} | CategoryController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/cheque-layouts/{id} | ChequePrintController@destroyLayout | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/cities/{city} | CityController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | none |
| DELETE | /api/commission-agents/{commission_agent} | CommissionAgentController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/companies/{company} | CompanyController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/consignment/settlements/{id} | ConsignmentController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/consumers/{consumer} | ConsumerController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/cost-centers/{id} | CostCenterController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/countries/{country} | CountryController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | none |
| DELETE | /api/coupons/{coupon} | CouponController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | none |
| DELETE | /api/credit-debit-notes/{credit_debit_note} | CreditDebitNoteController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/crm/allocation-rules/{id} | CrmAutomationController@deleteRule | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/currencies/{currency} | CurrencyController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | none |
| DELETE | /api/custom-fields/{id} | DocumentCenterController@deleteField | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/customer-groups/{customer_group} | CustomerGroupController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/customers/{customer} | CustomerController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/customers/{id}/attachments/{attachmentId} | ContactAttachmentController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/customersubscriptionplans/{customersubscriptionplan} | CustomerSubscriptionPlanController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/dashboard-tools/kpis/{id} | DashboardToolsController@deleteKpi | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/dashboard-tools/role-layouts/{roleId} | DashboardToolsController@resetRoleLayout | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/departments/{department} | DepartmentController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/deposits/{deposit} | DepositController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/discounts/{discount} | DiscountController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/document-reminders/{id} | DocumentCenterController@deleteReminder | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/document-templates/{id} | DocumentCenterController@deleteTemplate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/documents/{id}/attachments/{attachmentId} | DocumentOutputController@deleteAttachment | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/expenses/{expense} | ExpenseController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/financialyears/{financialyear} | FinancialYearController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/fixed-assets/{id} | FixedAssetController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/fundtransfers/{fundtransfer} | FundTransferController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/gift-cards/{gift_card} | GiftCardController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/hrm/bonuses/{id} | HrmPayrollController@deleteBonus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/hrm/devices/{id} | HrmAttendanceController@deleteDevice | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/hrm/documents/{id} | HrmController@deleteDocument | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/hrm/employees/{id} | HrmController@deleteEmployee | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/hrm/rates/{id} | HrmController@deleteRate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/hrm/shifts/{id} | HrmController@deleteShift | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/hrm/tax-slabs/{id} | HrmController@deleteSlab | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/issue-notes/{issue_note} | IssueNoteController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/item-types/{item_type} | ItemTypeController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/journal-entries/{journal_entry} | JournalEntryController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/journal-schedules/{id} | JournalScheduleController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/lead-sources/{lead_source} | LeadSourceController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/leads/{lead} | LeadController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/manufacturing/boms/{id} | ManufacturingController@deleteBom | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/manufacturing/plans/{id} | ManufacturingController@deletePlan | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/menus/{menu} | MenuController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | none |
| DELETE | /api/message-templates/{id} | DocumentCenterController@deleteMessage | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/opportunities/{opportunity} | OpportunityController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/payment-terms/{id} | PaymentTermController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/payments/{payment} | PaymentController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/petty-cash/entries/{id} | PettyCashController@destroyEntry | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/petty-cash/funds/{id} | PettyCashController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/pipeline-stages/{pipeline_stage} | PipelineStageController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/portal-users/{id} | PortalUserController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/pricelists/{pricelist} | PriceListController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/product-attributes/{id} | ProductAttributeController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/product-discount-rules/{id} | ProductDiscountRuleController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/products/{product} | ProductController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/project-work/{projectId}/milestones/{id} | ProjectWorkController@deleteMilestone | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/project-work/{projectId}/tasks/{id} | ProjectWorkController@deleteTask | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/projects/{id} | ProjectController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/purchase-approval-rules/{id} | PurchaseApprovalRuleController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/purchase-payments/{purchase_payment} | PurchasePaymentController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/purchase-requisitions/{purchase_requisition} | PurchaseRequisitionController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/purchase-returns/{purchase_return} | PurchaseReturnController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/purchases/{purchase} | PurchaseController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/quality-inspections/{id} | QualityInspectionController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/receiving-notes/{receiving_note} | ReceivingNoteController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/recurring-invoices/{id} | RecurringInvoiceController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/report-builder/{id} | ReportBuilderController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/report-filters/{id} | ReportFilterController@destroy | auth:sanctum, ValidateBulkActionBody | none | company scoped |
| DELETE | /api/report-schedules/{id} | ReportScheduleController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/riders/{id} | DispatchController@destroyRider | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/rma/{id} | RmaController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/roles/{role} | RoleController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/saas/companies/{companyId}/addons/{id} | SaasAdminController@removeAddon | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/sales-kpi/tiers/{id} | SalesKpiController@destroyTier | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/sales-routes/{id} | SalesRouteController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/security/ip-rules/{id} | SecurityCenterController@deleteIpRule | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/sell-payments/{sell_payment} | SellPaymentController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/sell-returns/{sell_return} | SellReturnController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/sells/{sell} | SellController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/service/jobs/{id}/parts/{partId} | ServiceJobController@removePart | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/states/{state} | StateController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | none |
| DELETE | /api/stock-takes/{stock_take} | StockTakeController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/stockadjustments/{stockadjustment} | StockAdjustmentController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/stocktransfers/{stocktransfer} | StockTransferController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/subscriptionplans/{subscriptionplan} | SubscriptionPlanController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | none |
| DELETE | /api/suppliers/{id}/attachments/{attachmentId} | ContactAttachmentController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/suppliers/{supplier} | SupplierController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/system/demo-companies/{id} | SystemCenterController@removeDemo | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/system/franchise-groups/{id} | SystemCenterController@deleteGroup | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/system/help-articles/{id} | SystemCenterController@deleteArticle | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/tax-exemptions/{id} | TaxExemptionController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/tax-payments/{id} | TaxPaymentController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/taxes/{tax} | TaxController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/tenant-billing/domains/{id} | TenantSelfServiceController@removeDomain | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/timezones/{timezone} | TimezoneController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | none |
| DELETE | /api/transporters/{transporter} | TransporterController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/units/{unit} | UnitController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/users/{user} | UserController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/variations/{variation} | VariationController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/warehouse-locations/{id} | WarehouseLocationController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/warehouses/{warehouse} | WarehouseController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/warranties/{warranty} | WarrantyController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/warranty-claims/{id} | WarrantyClaimController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| DELETE | /api/webhooks/{webhook} | WebhookController@destroy | auth:sanctum, ValidateBulkActionBody | controller check | none |
| DELETE | /settings/profile | ProfileController@destroy | auth, verified | none | none |
| GET | / | LandingController@index | guest | none | none |
| GET | /.well-known/passkey-endpoints | Closure |  | - | - |
| GET | /403 | Closure |  | - | - |
| GET | /acpayment | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /acpayment/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /acpayment/approval | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /acpayment/approval/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /acpayment/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /acpayment/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /activities | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /activities/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /activities/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /activities/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /activities/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /allocation | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /analytics | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /api/account-balances/fetch-balance | AccountBalanceController@fetchBalance | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/activities | ActivityController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/activities/timeline | ActivityController@timeline | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/activities/trash | ActivityController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/activities/{activity} | ActivityController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/allocation/suggest | AllocationController@suggest | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/analytics-overview | AnalyticsOverviewController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/api-keys | ApiKeyController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/apilogs | ApiLogController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/approval-center/delegations | ApprovalCenterController@delegations | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/approval-center/directory | ApprovalCenterController@directory | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/approval-center/discount-codes | ApprovalCenterController@pendingDiscounts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/approval-center/history | ApprovalCenterController@history | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/approval-center/inbox | ApprovalCenterController@inbox | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/approval-center/outbox | ApprovalCenterController@outbox | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/approval-center/settings | ApprovalCenterController@settings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/approval-center/types | ApprovalCenterController@types | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/approval-center/workflows | ApprovalCenterController@workflows | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/asset-approvals | AssetApprovalController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/asset-categories | AssetCategoryController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/asset-categories/{id} | AssetCategoryController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/audit-logs | AuditLogController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/backups | BackupController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/backups/{id}/download | BackupController@download | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/bad-debt/invoices | BadDebtController@invoices | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/bad-debt/provision | BadDebtController@provisionPreview | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/bad-debt/settings | BadDebtController@settings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/bad-debt/writeoffs | BadDebtController@writeoffs | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/bank-instruments | BankInstrumentController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/bank-issuers | BankIssuerController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/bank-issuers/create | BankIssuerController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/bank-issuers/trash | BankIssuerController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/bank-issuers/{bank_issuer} | BankIssuerController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/bank-issuers/{bank_issuer}/edit | BankIssuerController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/bank-reconciliations | BankReconciliationController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/bank-reconciliations/accounts | BankReconciliationController@accounts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/bank-reconciliations/{id} | BankReconciliationController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/banks | BankController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/banks/create | BankController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/banks/generate-code | BankController@generateCode | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/banks/trash | BankController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/banks/{bank} | BankController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/banks/{bank}/edit | BankController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/blog-posts | BlogPostController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/blog-posts/trash | BlogPostController@trash | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/blog-posts/{blog_post} | BlogPostController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/branches | BranchController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/branches/create | BranchController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/branches/generate-code | BranchController@generateCode | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/branches/trash | BranchController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/branches/{branch} | BranchController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/branches/{branch}/edit | BranchController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/branding | TenantSelfServiceController@branding | throttle:60,1 | controller check | company scoped |
| GET | /api/brands | BrandController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/brands/create | BrandController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/brands/trash | BrandController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/brands/{brand} | BrandController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/brands/{brand}/edit | BrandController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/budgets | BudgetController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/budgets/{id} | BudgetController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/business-settings | BusinessSettingsController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/cash-collection-approvals | CashCollectionApprovalController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/cash-collections | CashCollectionController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/cash-collections/open-invoices | CashCollectionController@openInvoices | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/cash-collections/trash | CashCollectionController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/cash-collections/{cash_collection} | CashCollectionController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/cash-flow-forecast | CashFlowForecastController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/cashiers | CashierController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/cashiers/performance | CashierController@performance | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/categories | CategoryController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/categories/create | CategoryController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/categories/trash | CategoryController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/categories/{category} | CategoryController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/categories/{category}/edit | CategoryController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/chart-of-accounts | ChartOfAccountController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/chart-of-accounts/generate-code | ChartOfAccountController@generateCode | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/chart-of-accounts/resolve-from-parent | ChartOfAccountController@resolveFromParent | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/chart-of-accounts/{chart_of_account} | ChartOfAccountController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/cheque-layouts | ChequePrintController@layouts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/cheque-records | ChequeRecordController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/cities | CityController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/cities/create | CityController@create | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/cities/trash | CityController@trash | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/cities/{city} | CityController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/cities/{city}/edit | CityController@edit | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/commission-agents | CommissionAgentController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/commission-agents/create | CommissionAgentController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/commission-agents/trash | CommissionAgentController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/commission-agents/{commission_agent} | CommissionAgentController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/commission-agents/{commission_agent}/edit | CommissionAgentController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/companies | CompanyController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/companies/create | CompanyController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/companies/generate-code | CompanyController@generateCode | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/companies/trash | CompanyController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/companies/{company} | CompanyController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/companies/{company}/edit | CompanyController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/company-settings/{companyId} | CompanySettingController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/consignment/settlements | ConsignmentController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/consignment/settlements/{id} | ConsignmentController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/consignment/stock | ConsignmentController@stock | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/consumers | ConsumerController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/consumers/create | ConsumerController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/consumers/trash | ConsumerController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/consumers/{consumer} | ConsumerController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/consumers/{consumer}/edit | ConsumerController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/contacts/duplicates | ContactDuplicateController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/cost-centers | CostCenterController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/cost-centers/{id} | CostCenterController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/countries | CountryController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/countries/create | CountryController@create | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/countries/trash | CountryController@trash | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/countries/{country} | CountryController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/countries/{country}/edit | CountryController@edit | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/coupons | CouponController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/coupons/trash | CouponController@trash | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/coupons/{coupon} | CouponController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/credit-debit-note-approvals | VoucherApprovalController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/credit-debit-note-approvals/{id} | VoucherApprovalController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/credit-debit-notes | CreditDebitNoteController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/credit-debit-notes/create | CreditDebitNoteController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/credit-debit-notes/voucher-no | CreditDebitNoteController@voucherNo | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/credit-debit-notes/{credit_debit_note} | CreditDebitNoteController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/credit-debit-notes/{credit_debit_note}/edit | CreditDebitNoteController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/credit-limit-approvals | CreditLimitApprovalController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/crm/allocation-rules | CrmAutomationController@rules | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/crmanalytics/conversion | CrmReportController@conversion | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/crmanalytics/pipeline | CrmReportController@pipeline | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/crmanalytics/sales-activity | CrmReportController@salesActivity | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/crmanalytics/salesperson-performance | CrmReportController@salespersonPerformance | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/currencies | CurrencyController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/currencies/create | CurrencyController@create | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/currencies/trash | CurrencyController@trash | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/currencies/{currency} | CurrencyController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/currencies/{currency}/edit | CurrencyController@edit | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/currency-rates | CurrencyRateController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/custom-fields | DocumentCenterController@fields | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/custom-values/{entity}/{id} | DataCenterController@customValues | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customer-groups | CustomerGroupController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customer-groups/create | CustomerGroupController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customer-groups/trash | CustomerGroupController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customer-groups/{customer_group} | CustomerGroupController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customer-groups/{customer_group}/edit | CustomerGroupController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customer-subscription-billing/reminders | CustomerSubscriptionBillingController@reminders | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customer-subscriptions/{id}/refund-quote | CustomerSubscriptionBillingController@quote | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customers | CustomerController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customers/create | CustomerController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customers/generate-code | CustomerController@generateCode | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customers/trash | CustomerController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customers/{customer} | CustomerController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customers/{customer}/edit | CustomerController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customers/{id}/attachments | ContactAttachmentController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customers/{id}/attachments/{attachmentId}/download | ContactAttachmentController@download | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customersubscriptionanalytics/churn | CustomerSubscriptionReportController@churn | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customersubscriptionanalytics/ltv | CustomerSubscriptionReportController@ltv | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customersubscriptionanalytics/renewal-due | CustomerSubscriptionReportController@renewalDue | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customersubscriptionanalytics/revenue | CustomerSubscriptionReportController@revenue | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customersubscriptionanalytics/summary | CustomerSubscriptionReportController@summary | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customersubscriptioninvoices | CustomerSubscriptionInvoiceController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customersubscriptioninvoices/{id} | CustomerSubscriptionInvoiceController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customersubscriptionplans | CustomerSubscriptionPlanController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customersubscriptionplans/trash | CustomerSubscriptionPlanController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customersubscriptionplans/{customersubscriptionplan} | CustomerSubscriptionPlanController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customersubscriptions | CustomerSubscriptionController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/customersubscriptions/{id} | CustomerSubscriptionController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/dashboard-insights | DashboardInsightsController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/dashboard-preferences | DashboardInsightsController@preferences | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/dashboard-tools/kpi-catalog | DashboardToolsController@kpiCatalog | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/dashboard-tools/kpi-figures/{report} | DashboardToolsController@kpiFigures | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/dashboard-tools/kpis | DashboardToolsController@kpis | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/dashboard-tools/layout | DashboardToolsController@layout | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/dashboard-tools/role-layouts | DashboardToolsController@roleLayouts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/dashboard-tools/summary | DashboardToolsController@summary | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/dashboard-tools/summary/preview | DashboardToolsController@previewSummary | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/dashboard/{widget} | DashboardController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/data-center/export/{entity} | DataCenterController@export | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/data-center/history | DataCenterController@history | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/data-center/template/{entity} | DataCenterController@template | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/departments | DepartmentController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/departments/create | DepartmentController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/departments/trash | DepartmentController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/departments/{department} | DepartmentController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/departments/{department}/edit | DepartmentController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/deposit-approvals | VoucherApprovalController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/deposit-approvals/{id} | VoucherApprovalController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/deposits | DepositController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/deposits/create | DepositController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/deposits/voucher-no | DepositController@voucherNo | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/deposits/{deposit} | DepositController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/deposits/{deposit}/edit | DepositController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/depreciation | FixedAssetDepreciationController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/depreciation/preview | FixedAssetDepreciationController@preview | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/discounts | DiscountController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/discounts/create | DiscountController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/discounts/trash | DiscountController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/discounts/{discount} | DiscountController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/discounts/{discount}/edit | DiscountController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/dispatch/board | DispatchController@board | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/docs/openapi | IntegrationsController@openApi | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/docs/postman | IntegrationsController@postman | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/document-center/meta | DocumentCenterController@meta | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/document-delivery | DocumentCenterController@delivery | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/document-reminders | DocumentCenterController@reminders | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/document-settings/{group} | DocumentSettingController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/document-templates | DocumentCenterController@templates | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/documents/{id}/attachments | DocumentOutputController@attachments | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/documents/{id}/attachments/{attachmentId} | DocumentOutputController@downloadAttachment | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/documents/{id}/custom-fields | DocumentOutputController@customValues | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/documents/{id}/export | DocumentOutputController@export | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/documents/{id}/render | DocumentOutputController@render | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/dunning/candidates | DunningController@candidates | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/dunning/cases | DunningController@cases | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/dunning/events | DunningController@events | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/dunning/levels | DunningController@levels | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/early-payment-discounts | PaymentTermController@eligible | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/ecommerce-connections | EcommerceConnectionController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/ecommerce-connections/{id}/orders | EcommerceConnectionController@orders | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/expense-approvals | VoucherApprovalController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/expense-approvals/{id} | VoucherApprovalController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/expense-claims | ExpenseClaimController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/expense-claims/{id} | ExpenseClaimController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/expense-claims/{id}/receipts/{receiptId} | ExpenseClaimController@downloadReceipt | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/expenses | ExpenseController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/expenses/create | ExpenseController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/expenses/voucher-no | ExpenseController@voucherNo | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/expenses/{expense} | ExpenseController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/expenses/{expense}/edit | ExpenseController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fbr-settings | FbrController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fbr-submissions | FbrController@submissions | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchallaccounts | ChartOfAccountController@fetchAllAccounts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchbankissuers | BankIssuerController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchbanks | BankController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchbranches | BranchController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchbrands | BrandController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchcategories | CategoryController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchchildaccounts | ChartOfAccountController@fetchChildAccounts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchcommissionagents | CommissionAgentController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchcompanies | CompanyController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchconsumers | ConsumerController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchcontactdetail | SupplierController@contactDetail | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchcontrolaccounts | ChartOfAccountController@fetchControlAccounts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchcostcenters | CostCenterController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchcoupons | CouponController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/fetchcurrencies | CurrencyController@fetch | throttle:120,1 | controller check | none |
| GET | /api/fetchcustomergroups | CustomerGroupController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchcustomers | ContactController@fetchCustomers | auth:sanctum, ValidateBulkActionBody | none | company scoped |
| GET | /api/fetchcustomersubscriptionplans | CustomerSubscriptionPlanController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchdepartments | DepartmentController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchdiscounts | DiscountController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchfinancialyears | FinancialYearController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchgiftcards | GiftCardController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchitemtypes | ItemTypeController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchleads | LeadController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchleadsources | LeadSourceController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchledger | PartyLedgerReportController@fetchLedger | auth:sanctum, ValidateBulkActionBody | none | company scoped |
| GET | /api/fetchmenus | MenuController@fetchmenus | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/fetchobaccounts | ChartOfAccountController@fetchObAccounts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchopportunities | OpportunityController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchparentaccounts | ChartOfAccountController@fetchParentAccounts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchparentpurchaseaccounts | ChartOfAccountController@fetchParentPurchaseAccounts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchparentsaleaccounts | ChartOfAccountController@fetchParentSaleAccounts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchpermenus | MenuController@fetchpermenus | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/fetchpermissions | PermissionController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchpipelinestages | PipelineStageController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchproducts | ProductController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchroles | RoleController@fetchroles | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchsubcategories | CategoryController@fetchsub | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchsubscriptionplans | SubscriptionPlanController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/fetchsuppliers | SupplierController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchtaxes | TaxController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchtimezones | TimezoneController@fetch | throttle:120,1 | controller check | none |
| GET | /api/fetchtransporters | TransporterController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchunits | UnitController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchusers | UserController@fetchusers | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchvariations | VariationController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchwarehouses | WarehouseController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fetchwarranties | WarrantyController@fetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/financialyears | FinancialYearController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/financialyears/create | FinancialYearController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/financialyears/{financialyear} | FinancialYearController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/financialyears/{financialyear}/edit | FinancialYearController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fixed-assets | FixedAssetController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fixed-assets/{id} | FixedAssetController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fixed-assets/{id}/history | FixedAssetAcquisitionController@history | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/foreign-currency | ForeignCurrencyController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/franchise/overview | HelpCenterController@franchise | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/fund-transfer-approvals | VoucherApprovalController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/fund-transfer-approvals/{id} | VoucherApprovalController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/fundtransfers | FundTransferController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fundtransfers/create | FundTransferController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fundtransfers/voucher-no | FundTransferController@voucherNo | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fundtransfers/{fundtransfer} | FundTransferController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/fundtransfers/{fundtransfer}/edit | FundTransferController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/getpermissions | MenuController@getpermission | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/gift-cards | GiftCardController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/gift-cards/create | GiftCardController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/gift-cards/orphan-refunds | GiftCardController@orphanRefunds | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/gift-cards/trash | GiftCardController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/gift-cards/{gift_card} | GiftCardController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/gift-cards/{gift_card}/edit | GiftCardController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/help-articles | HelpCenterController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/help-articles/{slug} | HelpCenterController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/hrm/attendance | HrmAttendanceController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/bonuses | HrmPayrollController@bonuses | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/components | HrmController@components | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/devices | HrmAttendanceController@devices | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/documents/expiring | HrmController@expiring | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/documents/{id}/download | HrmController@downloadDocument | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/employees | HrmController@employees | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/employees/{id} | HrmController@showEmployee | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/employees/{id}/documents | HrmController@documents | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/leave-balance | HrmAttendanceController@balance | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/leave-requests | HrmAttendanceController@leaveRequests | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/leave-types | HrmController@leaveTypes | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/loans | HrmPayrollController@loans | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/meta | HrmController@meta | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/org-tree | HrmController@orgTree | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/payroll | HrmPayrollController@runs | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/payroll/preview | HrmPayrollController@preview | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/payroll/{id} | HrmPayrollController@showRun | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/payroll/{id}/bank-file | HrmPayrollController@bankFile | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/payslips/{lineId} | HrmPayrollController@payslip | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/rates | HrmController@rates | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/roster | HrmController@roster | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/settings | HrmController@settings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/hrm/shifts | HrmController@shifts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/import-purchases | ImportPurchaseController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/import-purchases/{id} | ImportPurchaseController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/inbound-shipments | InboundShipmentController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/inbound-shipments/{id} | InboundShipmentController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/installment-plans | InstallmentPlanController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/installment-plans/{id} | InstallmentPlanController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/integrations/accounts | IntegrationsController@accounts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/integrations/courier/track | IntegrationsController@courierTrack | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/interbranch | InterBranchController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/interbranch/consolidation | InterBranchController@consolidation | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/inventory-close | InventoryCloseController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/issue-notes | IssueNoteController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/issue-notes/create | IssueNoteController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/issue-notes/eligible-sells | IssueNoteController@eligibleSells | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/issue-notes/sell/{id} | IssueNoteController@sellLines | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/issue-notes/trash | IssueNoteController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/issue-notes/{issue_note} | IssueNoteController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/issue-notes/{issue_note}/edit | IssueNoteController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/item-types | ItemTypeController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/item-types/create | ItemTypeController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/item-types/trash | ItemTypeController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/item-types/{item_type} | ItemTypeController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/item-types/{item_type}/edit | ItemTypeController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/journal-entries | JournalEntryController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/journal-entries/create | JournalEntryController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/journal-entries/voucher-no | JournalEntryController@voucherNo | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/journal-entries/{journal_entry} | JournalEntryController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/journal-entries/{journal_entry}/edit | JournalEntryController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/journal-entry-approvals | VoucherApprovalController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/journal-entry-approvals/{id} | VoucherApprovalController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/journal-schedules | JournalScheduleController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/journal-schedules/preview | JournalScheduleController@preview | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/journal-schedules/{id}/runs | JournalScheduleController@runs | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/landed-costs | LandedCostController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/landed-costs/{id} | LandedCostController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/lead-sources | LeadSourceController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/lead-sources/trash | LeadSourceController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/lead-sources/{lead_source} | LeadSourceController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/leads | LeadController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/leads/trash | LeadController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/leads/{lead} | LeadController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/loans | LoanController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/loans/{id} | LoanController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/lowstock | LowStockController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/loyalty | LoyaltyController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/loyalty/settings | LoyaltyController@settings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/loyalty/{id} | LoyaltyController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/manufacturing/boms | ManufacturingController@boms | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/manufacturing/boms/{id} | ManufacturingController@showBom | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/manufacturing/plans | ManufacturingController@plans | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/manufacturing/products | ManufacturingController@products | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/manufacturing/settings | ManufacturingController@settings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/manufacturing/work-orders | ManufacturingController@workOrders | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/manufacturing/work-orders/{id} | ManufacturingController@showWorkOrder | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/menus | MenuController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/menus/create | MenuController@create | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/menus/trash | MenuController@trash | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/menus/{menu} | MenuController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/menus/{menu}/edit | MenuController@edit | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/message-templates | DocumentCenterController@messages | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/messaging-settings | MessagingSettingController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/my-notifications | MyNotificationsController@index | auth:sanctum, ValidateBulkActionBody | none | none |
| GET | /api/notifications/counts-by-type | DashboardController@notifications | auth | controller check | company scoped |
| GET | /api/opening-stock | OpeningStockController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/opportunities | OpportunityController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/opportunities/board | OpportunityController@board | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/opportunities/trash | OpportunityController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/opportunities/{opportunity} | OpportunityController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/payment-approvals | VoucherApprovalController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/payment-approvals/{id} | VoucherApprovalController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/payment-terms | PaymentTermController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/payments | PaymentController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/payments/create | PaymentController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/payments/voucher-no | PaymentController@voucherNo | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/payments/{payment} | PaymentController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/payments/{payment}/edit | PaymentController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/period-locks | PeriodLockController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/petty-cash/funds | PettyCashController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/petty-cash/funds/{id}/entries | PettyCashController@entries | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/pipeline-stages | PipelineStageController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/pipeline-stages/trash | PipelineStageController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/pipeline-stages/{pipeline_stage} | PipelineStageController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/portal | PortalController@show | auth:sanctum, ValidateBulkActionBody | none | company scoped |
| GET | /api/portal-requests | PortalRequestController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/portal-requests/{id}/document | PortalRequestController@document | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/portal-users | PortalUserController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/portal/bills | PortalSupplierController@bills | auth:sanctum, ValidateBulkActionBody, throttle:portal | none | company scoped |
| GET | /api/portal/bills/{id}/file | PortalSupplierController@billFile | auth:sanctum, ValidateBulkActionBody, throttle:portal | none | company scoped |
| GET | /api/portal/invoices | PortalCustomerController@invoices | auth:sanctum, ValidateBulkActionBody, throttle:portal | none | company scoped |
| GET | /api/portal/invoices/{id} | PortalCustomerController@invoice | auth:sanctum, ValidateBulkActionBody, throttle:portal | none | company scoped |
| GET | /api/portal/payment-info | PortalController@paymentInfo | auth:sanctum, ValidateBulkActionBody, throttle:portal | none | company scoped |
| GET | /api/portal/products | PortalCustomerController@products | auth:sanctum, ValidateBulkActionBody, throttle:portal | none | company scoped |
| GET | /api/portal/purchase-orders | PortalSupplierController@purchaseOrders | auth:sanctum, ValidateBulkActionBody, throttle:portal | none | company scoped |
| GET | /api/portal/purchase-orders/{id} | PortalSupplierController@purchaseOrder | auth:sanctum, ValidateBulkActionBody, throttle:portal | none | company scoped |
| GET | /api/portal/requests | PortalCustomerController@requests | auth:sanctum, ValidateBulkActionBody, throttle:portal | none | company scoped |
| GET | /api/portal/statement | PortalCustomerController@statement | auth:sanctum, ValidateBulkActionBody, throttle:portal | none | company scoped |
| GET | /api/portal/subscription-invoices | PortalSubscriptionController@invoices | auth:sanctum, ValidateBulkActionBody | none | company scoped |
| GET | /api/portal/subscription-plans | PortalSubscriptionController@availablePlans | auth:sanctum, ValidateBulkActionBody | none | company scoped |
| GET | /api/portal/subscriptions | PortalSubscriptionController@index | auth:sanctum, ValidateBulkActionBody | none | company scoped |
| GET | /api/pos-shifts | PosShiftController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/pos-shifts/current | PosShiftController@current | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/pos-shifts/{id} | PosShiftController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/pos/offline/catalog | PosOfflineController@catalog | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/pos/offline/sales | PosOfflineController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/pricelist-approvals | PriceListApprovalController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/pricelists | PriceListController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/pricelists/create | PriceListController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/pricelists/search-products | PriceListController@searchProducts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/pricelists/trash | PriceListController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/pricelists/{pricelist} | PriceListController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/pricelists/{pricelist}/edit | PriceListController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/pricing/resolve | PricingController@resolve | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/printlabels/search-products | PrintLabelController@searchProducts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/product-attributes | ProductAttributeController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/product-components | ProductComponentController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/product-discount-rules | ProductDiscountRuleController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/product-discount-rules/resolve | ProductDiscountRuleController@resolve | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/products | ProductController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/products/create | ProductController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/products/export-all | ProductBulkController@export | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/products/lookup | ProductBulkController@lookup | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/products/trash | ProductController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/products/{id}/components | ProductComponentController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/products/{product} | ProductController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/products/{product}/edit | ProductController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/project-work/{projectId} | ProjectWorkController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/projects | ProjectController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/projects/report | ProjectController@report | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-approval-rules | PurchaseApprovalRuleController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-approvals | PurchaseApprovalController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-approvals/{id} | PurchaseApprovalController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-payments | PurchasePaymentController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-payments/accounts | PurchasePaymentController@paymentAccounts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-payments/create | PurchasePaymentController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-payments/eligible-purchases | PurchasePaymentController@eligiblePurchases | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-payments/purchase/{id} | PurchasePaymentController@purchase | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-payments/{purchase_payment} | PurchasePaymentController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-payments/{purchase_payment}/edit | PurchasePaymentController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-requisition-approvals | PurchaseRequisitionApprovalController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-requisition-approvals/{id} | PurchaseRequisitionApprovalController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-requisitions | PurchaseRequisitionController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-requisitions/create | PurchaseRequisitionController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-requisitions/eligible | PurchaseRequisitionController@eligible | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-requisitions/{id}/lines | PurchaseRequisitionController@linesForConversion | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-requisitions/{purchase_requisition} | PurchaseRequisitionController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-requisitions/{purchase_requisition}/edit | PurchaseRequisitionController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-return-approvals | PurchaseReturnApprovalController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-returns | PurchaseReturnController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-returns/create | PurchaseReturnController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-returns/eligible-purchases | PurchaseReturnController@eligiblePurchases | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-returns/purchase/{id} | PurchaseReturnController@purchaseLines | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-returns/trash | PurchaseReturnController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-returns/{purchase_return} | PurchaseReturnController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchase-returns/{purchase_return}/edit | PurchaseReturnController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchases | PurchaseController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchases/create | PurchaseController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchases/search-products | PurchaseController@searchProducts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchases/trash | PurchaseController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchases/{purchase} | PurchaseController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/purchases/{purchase}/edit | PurchaseController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/push/key | BusinessSettingsController@pushKey | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/quality-inspections | QualityInspectionController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/quality-inspections/purchase/{transactionId}/lines | QualityInspectionController@lines | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/quality-inspections/{id} | QualityInspectionController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/quotations | QuotationController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/receiving-notes | ReceivingNoteController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/receiving-notes/create | ReceivingNoteController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/receiving-notes/eligible-purchases | ReceivingNoteController@eligiblePurchases | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/receiving-notes/purchase/{id} | ReceivingNoteController@purchaseLines | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/receiving-notes/trash | ReceivingNoteController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/receiving-notes/{receiving_note} | ReceivingNoteController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/receiving-notes/{receiving_note}/edit | ReceivingNoteController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/recurring-invoices | RecurringInvoiceController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/recurring-invoices/{id} | RecurringInvoiceController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/rental/availability | RentalController@availability | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/rental/bookings | RentalController@bookings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/rental/bookings/{id} | RentalController@showBooking | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/rental/calendar | RentalController@calendar | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/rental/items | RentalController@items | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/rental/settings | RentalController@settings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reorder-suggestions | ReorderSuggestionController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/report-builder | ReportBuilderController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/report-builder/meta | ReportBuilderController@meta | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/report-builder/{id}/run | ReportBuilderController@run | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/report-filters | ReportFilterController@index | auth:sanctum, ValidateBulkActionBody | none | company scoped |
| GET | /api/report-schedules | ReportScheduleController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/report-schedules/{id}/runs | ReportScheduleController@runs | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/abc-analysis | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/account-ledger | LedgerReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/activity-summary | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/advance | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/attendance-summary | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/backorder | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/balance-sheet | FinancialReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/balance-sheet-comparative | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/batch-expiry | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/budget-vs-actual | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/cash-collection | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/cash-flow | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/cash-flow-indirect | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/change-history | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/commission-report | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/consolidated-branch | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/cost-center-analysis | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/customer-aging | PartyReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/customer-group | PartyReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/customer-outstanding | PartyReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/customer-supplier | PartyReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/expense | TransactionReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/fbr-audit-trail | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/financial-ratios | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/fsn | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/item-profit-loss | ProductReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/item-purchase | ProductReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/item-sell | ProductReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/loan-balances | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/material-variance | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/payment-account | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/payment-age | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/payroll-register | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/price-history | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/product-purchase | ProductReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/product-sell | ProductReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/product-sell-summary | ProductReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/production-summary | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/profit-by-branch | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/profit-by-customer | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/profit-by-salesperson | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/profit-by-warehouse | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/profit-loss | FinancialReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/profit-loss-comparative | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/purchase | TransactionReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/purchase-payment | TransactionReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/purchase-price-trend | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/purchase-return | TransactionReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/purchase-sale | ProductReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/register | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/sales-by-payment-method | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/sales-by-salesperson | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/sales-by-warehouse | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/sales-discount | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/sales-forecast | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/scrap-report | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/sell | TransactionReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/sell-payment | TransactionReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/sell-return | TransactionReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/serial-traceability | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/statutory-liability | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/stock | StockReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/stock-adjustment | TransactionReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/stock-ageing | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/stock-movement-history | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/stock-take | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/stock-transfer | StockReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/supplier-aging | PartyReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/supplier-outstanding | PartyReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/supplier-performance | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/supplier-price-comparison | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/target-achievement | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/tax | ProductReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/tax-exemption | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/tax-liability | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/trending-products | ProductReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/trial-balance | LedgerReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/vouchers | LedgerReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/warehouse-stock | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/warehouse-usage | AnalyticsReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/wip-report | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/reports/withholding-tax | InsightReportController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/riders | DispatchController@riders | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/rma | RmaController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/roles | RoleController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/roles/create | RoleController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/roles/trash | RoleController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/roles/{role} | RoleController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/roles/{role}/edit | RoleController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/saas/overview | SaasAdminController@overview | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/saas/partners/{partnerId}/statement | SaasAdminController@statement | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/saas/usage/{companyId} | SaasAdminController@usage | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sales-kpi/statement | SalesKpiController@statement | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sales-kpi/targets | SalesKpiController@targets | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sales-kpi/tiers | SalesKpiController@tiers | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sales-orders | SalesOrderController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sales-orders/{id} | SalesOrderController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sales-routes | SalesRouteController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sales-routes/sheet | SalesRouteController@sheet | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/search | App\Http\Controllers\UniversalSearchController | auth:sanctum, ValidateBulkActionBody | - | - |
| GET | /api/security/devices | SecurityCenterController@devices | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/security/ip-rules | SecurityCenterController@ipRules | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/security/login-events | SecurityCenterController@loginEvents | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/security/privacy | SecurityCenterController@privacy | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/security/sessions | SecurityCenterController@sessions | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/security/settings | SecurityCenterController@settings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sell-approvals | SellApprovalController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sell-approvals/{id} | SellApprovalController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sell-payments | SellPaymentController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sell-payments/accounts | SellPaymentController@paymentAccounts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sell-payments/create | SellPaymentController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sell-payments/eligible-sells | SellPaymentController@eligibleSells | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sell-payments/sell/{id} | SellPaymentController@sell | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sell-payments/{sell_payment} | SellPaymentController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sell-payments/{sell_payment}/edit | SellPaymentController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sell-returns | SellReturnController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sell-returns/create | SellReturnController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sell-returns/eligible-sells | SellReturnController@eligibleSells | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sell-returns/sell/{id} | SellReturnController@sellLines | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sell-returns/trash | SellReturnController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sell-returns/{sell_return} | SellReturnController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sell-returns/{sell_return}/edit | SellReturnController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sells | SellController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sells/create | SellController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sells/search-products | SellController@searchProducts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sells/trash | SellController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sells/{id}/receipt-shares | ReceiptShareController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sells/{sell} | SellController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/sells/{sell}/edit | SellController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/service/jobs | ServiceJobController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/service/jobs/{id} | ServiceJobController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/service/jobs/{id}/report | ServiceJobController@report | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/service/meta | ServiceJobController@meta | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/service/my-jobs | ServiceJobController@myJobs | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/setup-wizard | BusinessSettingsController@wizard | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/software-settings | SettingController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/states | StateController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/states/create | StateController@create | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/states/trash | StateController@trash | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/states/{state} | StateController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/states/{state}/edit | StateController@edit | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/stock-adjustment-approvals | StockAdjustmentApprovalController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stock-reservations | StockReservationController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stock-reservations/availability | StockReservationController@availability | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stock-takes | StockTakeController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stock-takes/trash | StockTakeController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stock-takes/{stock_take} | StockTakeController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stock-tracking/available-batches | StockTrackingController@availableBatches | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stock-tracking/available-serials | StockTrackingController@availableSerials | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stock-tracking/batches | StockTrackingController@batches | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stock-tracking/serials | StockTrackingController@serials | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stock-transfer-approvals | StockTransferApprovalController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stock-writeoffs | StockWriteoffController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stockadjustments | StockAdjustmentController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stockadjustments/create | StockAdjustmentController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stockadjustments/search-products | StockAdjustmentController@searchProducts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stockadjustments/trash | StockAdjustmentController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stockadjustments/{stockadjustment} | StockAdjustmentController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stockadjustments/{stockadjustment}/edit | StockAdjustmentController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stocktransfers | StockTransferController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stocktransfers/create | StockTransferController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stocktransfers/search-products | StockTransferController@searchProducts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stocktransfers/trash | StockTransferController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stocktransfers/{stocktransfer} | StockTransferController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/stocktransfers/{stocktransfer}/edit | StockTransferController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/store-credit | StoreCreditController@balance | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/store-credits | StoreCreditController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/storefront-admin/orders | StorefrontController@orders | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/storefront-admin/products | StorefrontController@products | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/storefront-admin/settings | StorefrontController@settings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/storefront/{code} | PublicStorefrontController@catalog | throttle:120,1 | none | company scoped |
| GET | /api/subscriptioninvoices | SubscriptionInvoiceController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/subscriptioninvoices/{id} | SubscriptionInvoiceController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/subscriptionplans | SubscriptionPlanController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/subscriptionplans/trash | SubscriptionPlanController@trash | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/subscriptionplans/{subscriptionplan} | SubscriptionPlanController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/supplier-payments | SupplierPaymentController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/supplier-payments/{id} | SupplierPaymentController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/suppliers | SupplierController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/suppliers/create | SupplierController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/suppliers/generate-code | SupplierController@generateCode | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/suppliers/trash | SupplierController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/suppliers/{id}/attachments | ContactAttachmentController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/suppliers/{id}/attachments/{attachmentId}/download | ContactAttachmentController@download | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/suppliers/{supplier} | SupplierController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/suppliers/{supplier}/edit | SupplierController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/support-tickets | TenantSelfServiceController@tickets | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/support-tickets/{id} | TenantSelfServiceController@ticket | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/system/backup-remote | SystemCenterController@backupRemote | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/system/demo-companies | SystemCenterController@demos | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/system/franchise-groups | SystemCenterController@groups | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/system/help-articles | SystemCenterController@articles | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/system/logs | SystemCenterController@logs | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/system/push | SystemCenterController@push | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/system/retention | SystemCenterController@retention | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/tax-exemptions | TaxExemptionController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/tax-exemptions/{id} | TaxExemptionController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/tax-payments | TaxPaymentController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/tax-payments/summary | TaxPaymentController@summary | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/taxes | TaxController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/taxes/create | TaxController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/taxes/{tax} | TaxController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/taxes/{tax}/edit | TaxController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/tenant-billing/exports/{id}/download | TenantSelfServiceController@downloadExport | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/tenant-billing/overview | TenantSelfServiceController@overview | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/tenants | TenantController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/tenants/{id} | TenantController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/timezones | TimezoneController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/timezones/create | TimezoneController@create | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/timezones/trash | TimezoneController@trash | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/timezones/{timezone} | TimezoneController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/timezones/{timezone}/edit | TimezoneController@edit | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/transporters | TransporterController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/transporters/create | TransporterController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/transporters/trash | TransporterController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/transporters/{transporter} | TransporterController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/transporters/{transporter}/edit | TransporterController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/units | UnitController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/units/create | UnitController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/units/trash | UnitController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/units/{unit} | UnitController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/units/{unit}/edit | UnitController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/user | Closure | auth:sanctum | - | - |
| GET | /api/users | UserController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/users/create | UserController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/users/trash | UserController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/users/{user} | UserController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/users/{user}/edit | UserController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/v1/customers | PublicApiCustomerController@index | auth:sanctum, throttle:public-api, LogPublicApiRequest | controller check | none |
| GET | /api/v1/customers/{id} | PublicApiCustomerController@show | auth:sanctum, throttle:public-api, LogPublicApiRequest | controller check | none |
| GET | /api/v1/invoices | PublicApiInvoiceController@index | auth:sanctum, throttle:public-api, LogPublicApiRequest | controller check | none |
| GET | /api/v1/invoices/{id} | PublicApiInvoiceController@show | auth:sanctum, throttle:public-api, LogPublicApiRequest | controller check | none |
| GET | /api/v1/products | PublicApiProductController@index | auth:sanctum, throttle:public-api, LogPublicApiRequest | controller check | none |
| GET | /api/v1/products/{id} | PublicApiProductController@show | auth:sanctum, throttle:public-api, LogPublicApiRequest | controller check | none |
| GET | /api/valuation | ValuationController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/valuation/recalculation | ValuationController@preview | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/valuation/reconciliation | ValuationController@reconciliation | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/valuation/variance | ValuationController@variance | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/variations | VariationController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/variations/create | VariationController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/variations/trash | VariationController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/variations/{variation} | VariationController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/variations/{variation}/edit | VariationController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/warehouse-locations | WarehouseLocationController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/warehouse-tasks | WarehouseTaskController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/warehouse-tasks/{id} | WarehouseTaskController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/warehouses | WarehouseController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/warehouses/create | WarehouseController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/warehouses/trash | WarehouseController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/warehouses/{warehouse} | WarehouseController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/warehouses/{warehouse}/edit | WarehouseController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/warranties | WarrantyController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/warranties/create | WarrantyController@create | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/warranties/trash | WarrantyController@trash | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/warranties/{warranty} | WarrantyController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/warranties/{warranty}/edit | WarrantyController@edit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/warranty-claims | WarrantyClaimController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/warranty-claims/{id} | WarrantyClaimController@show | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/webhooks | WebhookController@index | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/webhooks/trash | WebhookController@trash | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/webhooks/{id}/deliveries | WebhookController@deliveries | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/webhooks/{webhook} | WebhookController@show | auth:sanctum, ValidateBulkActionBody | controller check | none |
| GET | /api/year-close | YearCloseController@index | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/year-close/preview | YearCloseController@preview | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /api/year-rollover/preview | YearRolloverController@preview | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| GET | /apikeys | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /apilogs | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /approvalcenter | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /artisan/{cmd} | Closure | auth | - | - |
| GET | /assetapproval | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /assetcategory | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /auditlogs | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /backup | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /baddebt | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /bank | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /bank/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /bank/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /bankinstrument | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /bankissuer | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /bankissuer/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /bankissuer/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /bankissuer/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /bankreconciliation | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /bankreconciliation/{id} | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /barcode/settings | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /blog | BlogController@index | throttle:120,1 | none | none |
| GET | /blog/{slug} | BlogController@show | throttle:120,1 | none | none |
| GET | /blogposts | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /blogposts/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /blogposts/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /branch | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /branch/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /brand | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /brand/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /budget | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /bulkpriceupdate | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /business/settings | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /businesssettings | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /capture/{code} | PublicLeadCaptureController@show | throttle:60,1 | none | none |
| GET | /cashcollection | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /cashcollection/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /cashcollection/approval | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /cashcollection/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /cashcollection/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /cashcollection/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /cashflowforecast | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /cashier | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /category | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /category/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /chart-of-account | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /checkout/settings | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /chequemanagement | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /chequeprint | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /city | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /city/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /commissionagent | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /commissionagent/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /commissionagent/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /commissionagent/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /commissionagent/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /company | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /company/setting | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /company/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /consignment | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /consumer | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /consumer/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /consumer/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /consumer/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /contacts/duplicates | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /costcenter | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /country | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /country/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /coupons | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /coupons/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /coupons/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /creditdebitnote | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /creditdebitnote/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /creditdebitnote/approval | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /creditdebitnote/approval/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /creditdebitnote/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /creditdebitnote/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /creditlimit/approval | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /crmanalytics | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /currency | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /currency/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /currencyrate | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /customer | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /customer-group | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /customer-group/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /customer/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /customer/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /customersubscriptionanalytics | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /customersubscriptioninvoices | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /customersubscriptionplans | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /customersubscriptionplans/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /customersubscriptionplans/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /customersubscriptions | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /dashboard | \Inertia\Controller | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /dashboard/insights | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /department | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /department/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /deposit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /deposit/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /deposit/approval | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /deposit/approval/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /deposit/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /deposit/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /depreciation | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /discount | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /discount/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /discount/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /discount/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /discount/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /dispatch | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /documentcenter | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /dunning | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /ecommerce | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /email_template | SettingController@email_template | auth, verified, EnforcePageMenuPermission | page middleware | none |
| GET | /expense | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /expense/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /expense/approval | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /expense/approval/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /expense/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /expense/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /expenseclaim | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /filemanager | LfmController@show | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /filemanager/crop | CropController@getCrop | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /filemanager/cropimage | CropController@getCropImage | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /filemanager/cropnewimage | CropController@getNewCropImage | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /filemanager/delete | DeleteController@getDelete | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /filemanager/demo | DemoController@index | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /filemanager/domove | ItemsController@doMove | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /filemanager/doresize | ResizeController@performResize | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /filemanager/doresizenew | ResizeController@performResizeNew | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /filemanager/download | DownloadController@getDownload | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /filemanager/errors | LfmController@getErrors | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /filemanager/folders | FolderController@getFolders | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /filemanager/jsonitems | ItemsController@getItems | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /filemanager/move | ItemsController@move | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /filemanager/newfolder | FolderController@getAddfolder | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /filemanager/rename | RenameController@getRename | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /filemanager/resize | ResizeController@getResize | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /financialyear | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /fixedasset | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /foreigncurrency | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /franchise | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /fundtransfer | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /fundtransfer/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /fundtransfer/approval | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /fundtransfer/approval/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /fundtransfer/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /fundtransfer/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /giftcard | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /giftcard/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /giftcard/orphan-refunds | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /giftcard/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /giftcard/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /giftcard/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /help | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /hrm | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /idle-timeout-alert/check | HomeController@check | auth | none | none |
| GET | /importpurchase | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /inboundshipment | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /installment | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /integrations | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /interbranch | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /inventoryclose | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /invoice/settings | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /issuenote | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /issuenote/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /issuenote/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /issuenote/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /issuenote/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /itemtype | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /itemtype/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /journalentry | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /journalentry/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /journalentry/approval | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /journalentry/approval/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /journalentry/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /journalentry/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /journalschedule | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /landedcost | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /laravel-filemanager | LfmController@show | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /laravel-filemanager/crop | CropController@getCrop | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /laravel-filemanager/cropimage | CropController@getCropImage | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /laravel-filemanager/cropnewimage | CropController@getNewCropImage | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /laravel-filemanager/delete | DeleteController@getDelete | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /laravel-filemanager/demo | DemoController@index | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /laravel-filemanager/domove | ItemsController@doMove | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /laravel-filemanager/doresize | ResizeController@performResize | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /laravel-filemanager/doresizenew | ResizeController@performResizeNew | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /laravel-filemanager/download | DownloadController@getDownload | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /laravel-filemanager/errors | LfmController@getErrors | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /laravel-filemanager/folders | FolderController@getFolders | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /laravel-filemanager/jsonitems | ItemsController@getItems | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /laravel-filemanager/move | ItemsController@move | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /laravel-filemanager/newfolder | FolderController@getAddfolder | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /laravel-filemanager/rename | RenameController@getRename | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /laravel-filemanager/resize | ResizeController@getResize | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET | /leadallocation | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /leads | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /leads/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /leads/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /leads/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /leads/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /leadsources | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /leadsources/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /leadsources/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /leadsources/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /leadsources/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /loan | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /lowstock | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /loyalty | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /loyalty/settings | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /loyalty/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /manufacturing | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /menu | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /menu/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /messagingsetting | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /my-notifications | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /opening-balance | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /openingstock | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /opportunities | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /opportunities/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /opportunities/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /opportunities/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /opportunities/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /paymentterm | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /period-lock | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /pettycash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /pipeline | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /pipelinestages | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /pipelinestages/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /pipelinestages/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /pipelinestages/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /pipelinestages/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /portal | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /portalrequests | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /portalusers | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /posoffline | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /posshift | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /pricelist | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /pricelist/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /pricelist/approval | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /pricelist/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /pricelist/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /printlabel | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /product | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /product/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /product/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /product/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /productattribute | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /productbom | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /productdiscountrule | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /project | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /project/work | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchase | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchase/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchase/approval | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchase/approval/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchase/payment | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchase/return | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchase/return/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchase/return/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchase/return/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchase/return/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchase/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchase/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchase/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchaseapprovalrule | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchaserequisition | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchaserequisition/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchaserequisition/approval | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchaserequisition/approval/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchaserequisition/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchaserequisition/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /purchasereturn/approval | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /qualityinspection | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /quotation | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /r/{token} | ReceiptShareController@show | throttle:60,1 | controller check | company scoped |
| GET | /receipt/settings | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /receivingnote | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /receivingnote/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /receivingnote/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /receivingnote/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /receivingnote/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /recurringinvoice | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /register | PublicRegistrationController@create | guest | none | none |
| GET | /rental | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /reordersuggestion | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/abc-analysis | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/account-ledger | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/activity-summary | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/advance | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/attendance-summary | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/backorder | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/balance-sheet | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/balance-sheet-comparative | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/batch-expiry | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/budget-vs-actual | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/cash-collection | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/cash-flow | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/cash-flow-indirect | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/change-history | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/commission-report | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/consolidated-branch | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/cost-center-analysis | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/customer-aging | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/customer-group | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/customer-outstanding | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/customer-supplier | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/expense | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/fbr-audit-trail | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/financial-ratios | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/fsn | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/item-profit-loss | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/item-purchase | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/item-sell | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/ledger | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/loan-balances | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/material-variance | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/payment-account | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/payment-age | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/payroll-register | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/price-history | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/product-purchase | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/product-sell | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/product-sell-summary | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/production-summary | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/profit-by-branch | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/profit-by-customer | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/profit-by-salesperson | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/profit-by-warehouse | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/profit-loss | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/profit-loss-comparative | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/purchase | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/purchase-payment | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/purchase-price-trend | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/purchase-return | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/purchase-sale | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/register | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/sales-by-payment-method | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/sales-by-salesperson | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/sales-by-warehouse | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/sales-discount | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/sales-forecast | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/scrap-report | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/sell | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/sell-payment | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/sell-return | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/serial-traceability | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/statutory-liability | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/stock | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/stock-adjustment | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/stock-ageing | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/stock-movement-history | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/stock-take | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/stock-transfer | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/supplier-aging | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/supplier-outstanding | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/supplier-performance | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/supplier-price-comparison | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/target-achievement | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/tax | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/tax-exemption | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/tax-liability | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/trending-products | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/trial-balance | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/vouchers | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/warehouse-stock | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/warehouse-usage | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/wip-report | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /report/withholding-tax | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /reportbuilder | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /rider | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /rma | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /role | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /role/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /role/{id}/permission | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /saasadmin | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /salesimport | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /saleskpi | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /salesorder | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /salesroute | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /security | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/approval | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/approval/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/draft | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/payment | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/pos/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/pos/display | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/pos/offline | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/quotation | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/return | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/return/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/return/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/return/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/return/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/shipment | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/{id}/invoice | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/{id}/receipt | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /sell/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /service | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /service/work | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /setting | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /settings/appearance | \Inertia\Controller | auth, verified | - | - |
| GET | /settings/profile | ProfileController@edit | auth | none | none |
| GET | /settings/security | SecurityController@edit | auth, verified, RequirePassword | none | none |
| GET | /setup | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /shop/{code} | PublicStorefrontController@page | throttle:60,1 | none | company scoped |
| GET | /software/setting | SettingController@index | auth, verified, EnforcePageMenuPermission | page middleware | none |
| GET | /state | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /state/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /stockadjustment | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /stockadjustment/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /stockadjustment/approval | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /stockadjustment/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /stockadjustment/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /stockreservation | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /stocktake | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /stocktake/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /stocktake/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /stocktake/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /stocktracking | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /stocktransfer | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /stocktransfer/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /stocktransfer/approval | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /stocktransfer/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /stocktransfer/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /stockwriteoff | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /storage/{path} | Closure |  | - | - |
| GET | /storecredit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /storefront | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /subscriptionbilling | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /subscriptioninvoices | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /subscriptionplans | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /subscriptionplans/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /subscriptionplans/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /supplier | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /supplier/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /supplier/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /supplierpayment | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /support | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /systemcenter | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /tax | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /taxexemption | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /taxpayment | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /tenant-billing | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /tenants | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /timezone | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /timezone/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /transporter | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /transporter/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /transporter/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /transporter/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /transporter/{id}/view | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /unit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /unit/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /up | Closure |  | - | - |
| GET | /user | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /user/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /valuation | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /variation | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /variation/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /warehouse | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /warehouse/add | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /warehouse/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /warehouse/{id}/edit | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /warehouselocation | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /warehousetask | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /warranty | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /warranty/trash | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /warrantyclaim | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /webhooks | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET | /yearclose | Closure | auth, verified, EnforcePageMenuPermission | page middleware | - |
| GET/POST/PUT/PATCH/DELETE/OPTIONS | /filemanager/upload | UploadController@upload | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET/POST/PUT/PATCH/DELETE/OPTIONS | /laravel-filemanager/upload | UploadController@upload | auth, UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder, UniSharp\LaravelFilemanager\Middlewares\MultiUser | none | none |
| GET/POST/PUT/PATCH/DELETE/OPTIONS | /settings | \Illuminate\Routing\RedirectController | auth | - | - |
| PATCH | /settings/profile | ProfileController@update | auth | none | none |
| POST | /api/account-balances | AccountBalanceController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/activities | ActivityController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/activities/bulk_delete | ActivityController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/activities/bulk_delete_per | ActivityController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/activities/restore_records | ActivityController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/activities/{id}/complete | ActivityController@complete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/allocation/apply | AllocationController@apply | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/api-keys | ApiKeyController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/approval-center/delegations | ApprovalCenterController@saveDelegation | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/approval-center/delegations/{id}/end | ApprovalCenterController@endDelegation | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/approval-center/discount-codes/{id}/{decision} | ApprovalCenterController@decideDiscount | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/approval-center/workflows | ApprovalCenterController@saveWorkflow | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/asset-approvals/{id}/approve | AssetApprovalController@approve | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/asset-approvals/{id}/reject | AssetApprovalController@reject | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/asset-categories | AssetCategoryController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/backups | BackupController@store | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/bad-debt/provision | BadDebtController@provision | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bad-debt/writeoffs | BadDebtController@request | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bad-debt/writeoffs/{id}/approve | BadDebtController@approve | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bad-debt/writeoffs/{id}/reject | BadDebtController@reject | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bad-debt/writeoffs/{id}/reverse | BadDebtController@reverse | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bank-instruments | BankInstrumentController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bank-issuers | BankIssuerController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bank-issuers/bulk_delete | BankIssuerController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bank-issuers/bulk_delete_per | BankIssuerController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bank-issuers/restore_records | BankIssuerController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bank-issuers/statusupdate | BankIssuerController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bank-reconciliations | BankReconciliationController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bank-reconciliations/parse | BankReconciliationController@parse | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bank-reconciliations/{id}/auto-match | BankReconciliationController@autoMatch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bank-reconciliations/{id}/lines/{lineId}/charge | BankReconciliationController@charge | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bank-reconciliations/{id}/lines/{lineId}/match | BankReconciliationController@match | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bank-reconciliations/{id}/lines/{lineId}/unmatch | BankReconciliationController@unmatch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bank-reconciliations/{id}/reconcile | BankReconciliationController@reconcile | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/banks | BankController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/banks/bulk_delete | BankController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/banks/bulk_delete_per | BankController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/banks/duplicate | BankController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/banks/restore_records | BankController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/banks/statusupdate | BankController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/banks/{id}/link-coa | BankController@linkCoa | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/blog-posts | BlogPostController@store | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/blog-posts/bulk_delete | BlogPostController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/blog-posts/bulk_delete_per | BlogPostController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/blog-posts/restore_records | BlogPostController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/boms/{id}/approve | ManufacturingController@activateBom | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/branches | BranchController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/branches/bulk_delete | BranchController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/branches/bulk_delete_per | BranchController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/branches/duplicate | BranchController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/branches/import | BranchController@import | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/branches/restore_records | BranchController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/branches/statusupdate | BranchController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/brands | BrandController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/brands/bulk_delete | BrandController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/brands/bulk_delete_per | BrandController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/brands/check-name | BrandController@checkName | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/brands/duplicate | BrandController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/brands/import | BrandController@import | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/brands/restore_records | BrandController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/brands/statusupdate | BrandController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/budgets | BudgetController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/budgets/{id}/restore | BudgetController@restore | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bulk-price-update/apply | BulkPriceUpdateController@apply | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/bulk-price-update/preview | BulkPriceUpdateController@preview | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/business-settings/rate-feed/sync | BusinessSettingsController@syncRates | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/cash-collection-approvals/{id}/approve | CashCollectionApprovalController@approve | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/cash-collections | CashCollectionController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/cash-collections/bulk_delete | CashCollectionController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/cash-collections/bulk_delete_per | CashCollectionController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/cash-collections/restore_records | CashCollectionController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/cash-collections/{id}/apply-advance | CashCollectionController@applyAdvance | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/cash-collections/{id}/cancel | CashCollectionController@cancel | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/cash-collections/{id}/complete | CashCollectionController@complete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/cash-collections/{id}/refund-advance | CashCollectionController@refundAdvance | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/cash-collections/{id}/reverse | CashCollectionController@reverse | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/cashiers | CashierController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/cashiers/verify-pin | CashierController@verifyPin | auth:sanctum, ValidateBulkActionBody, throttle:30,1 | controller check | company scoped |
| POST | /api/categories | CategoryController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/categories/bulk_delete | CategoryController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/categories/bulk_delete_per | CategoryController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/categories/check-name | CategoryController@checkName | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/categories/duplicate | CategoryController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/categories/import | CategoryController@import | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/categories/restore_records | CategoryController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/categories/statusupdate | CategoryController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/chart-of-accounts | ChartOfAccountController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/chart-of-accounts/check-code | ChartOfAccountController@checkCode | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/cheque-layouts | ChequePrintController@storeLayout | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/cheque-print | ChequePrintController@render | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/cheque-records | ChequeRecordController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/cheque-records/{id}/status | ChequeRecordController@transition | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/cities | CityController@store | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/cities/bulk_delete | CityController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/cities/bulk_delete_per | CityController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/cities/check-name | CityController@checkName | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/cities/fetch-from-api | CityController@fetchFromApi | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/cities/restore_records | CityController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/cities/statusupdate | CityController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/commission-agents | CommissionAgentController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/commission-agents/bulk_delete | CommissionAgentController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/commission-agents/bulk_delete_per | CommissionAgentController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/commission-agents/restore_records | CommissionAgentController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/commission-agents/statusupdate | CommissionAgentController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/companies | CompanyController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/companies/bulk_delete | CompanyController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/companies/bulk_delete_per | CompanyController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/companies/check-admin-identity | CompanyController@checkAdminIdentity | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/companies/check-code | CompanyController@checkCode | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/companies/duplicate | CompanyController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/companies/import | CompanyController@import | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/companies/restore_records | CompanyController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/companies/statusupdate | CompanyController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/companies/{id}/send-credentials | CompanyController@sendCredentials | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/consignment/settlements | ConsignmentController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/consignment/settlements/{id}/settle | ConsignmentController@settle | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/consumers | ConsumerController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/consumers/bulk_delete | ConsumerController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/consumers/bulk_delete_per | ConsumerController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/consumers/restore_records | ConsumerController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/consumers/statusupdate | ConsumerController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/contact-ledger-watches | PartyLedgerReportController@saveLedgerWatch | auth:sanctum, ValidateBulkActionBody | none | company scoped |
| POST | /api/contacts/duplicates/merge | ContactDuplicateController@merge | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/cost-centers | CostCenterController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/countries | CountryController@store | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/countries/bulk_delete | CountryController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/countries/bulk_delete_per | CountryController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/countries/check-iso2 | CountryController@checkIso2 | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/countries/check-name | CountryController@checkName | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/countries/fetch-from-api | CountryController@fetchFromApi | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/countries/restore_records | CountryController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/countries/statusupdate | CountryController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/coupons | CouponController@store | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/coupons/bulk_delete | CouponController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/coupons/bulk_delete_per | CouponController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/coupons/restore_records | CouponController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/credit-debit-note-approvals/{id}/approve | VoucherApprovalController@approve | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/credit-debit-note-approvals/{id}/reject | VoucherApprovalController@reject | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/credit-debit-notes | CreditDebitNoteController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/credit-debit-notes/bulk_delete | CreditDebitNoteController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/credit-debit-notes/duplicate | CreditDebitNoteController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/credit-limit-approvals/{id}/approve | CreditLimitApprovalController@approve | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/credit-limit-approvals/{id}/reject | CreditLimitApprovalController@reject | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/credit-limit-requests | CreditLimitRequestController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/crm/allocate-unassigned | CrmAutomationController@allocateUnassigned | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/crm/allocation-rules | CrmAutomationController@saveRule | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/crm/run-reminders | CrmAutomationController@runReminders | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/currencies | CurrencyController@store | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/currencies/bulk_delete | CurrencyController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/currencies/bulk_delete_per | CurrencyController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/currencies/check-code | CurrencyController@checkCode | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/currencies/fetch-from-api | CurrencyController@fetchFromApi | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/currencies/restore_records | CurrencyController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/currencies/statusupdate | CurrencyController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/custom-fields | DocumentCenterController@saveField | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customer-groups | CustomerGroupController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customer-groups/bulk_delete | CustomerGroupController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customer-groups/bulk_delete_per | CustomerGroupController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customer-groups/check-name | CustomerGroupController@checkName | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customer-groups/duplicate | CustomerGroupController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customer-groups/restore_records | CustomerGroupController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customer-groups/statusupdate | CustomerGroupController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customer-subscription-billing/run | CustomerSubscriptionBillingController@run | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customer-subscriptions/{id}/cancel-prorated | CustomerSubscriptionBillingController@cancel | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customers | CustomerController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customers/bulk_delete | CustomerController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customers/bulk_delete_per | CustomerController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customers/duplicate | CustomerController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customers/import | CustomerController@import | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customers/restore_records | CustomerController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customers/statusupdate | CustomerController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customers/{id}/attachments | ContactAttachmentController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customers/{id}/link-coa | CustomerController@linkCoa | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customersubscriptioninvoices/generate | CustomerSubscriptionInvoiceController@generate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customersubscriptioninvoices/{id}/cancel | CustomerSubscriptionInvoiceController@cancel | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customersubscriptioninvoices/{id}/mark-paid | CustomerSubscriptionInvoiceController@markPaid | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customersubscriptioninvoices/{id}/refund | CustomerSubscriptionInvoiceController@refund | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customersubscriptionplans | CustomerSubscriptionPlanController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customersubscriptionplans/bulk_delete | CustomerSubscriptionPlanController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customersubscriptionplans/bulk_delete_per | CustomerSubscriptionPlanController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customersubscriptionplans/restore_records | CustomerSubscriptionPlanController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customersubscriptions | CustomerSubscriptionController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customersubscriptions/{id}/cancel | CustomerSubscriptionController@cancel | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customersubscriptions/{id}/change-plan | CustomerSubscriptionController@changePlan | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customersubscriptions/{id}/pause | CustomerSubscriptionController@pause | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customersubscriptions/{id}/resume | CustomerSubscriptionController@resume | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/customersubscriptions/{id}/usage | CustomerSubscriptionController@recordUsage | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/dashboard-tools/kpis | DashboardToolsController@saveKpi | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/dashboard-tools/summary/send | DashboardToolsController@sendSummary | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/data-center/history/{id}/undo | DataCenterController@undo | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/data-center/migrate | DataCenterController@migrate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/departments | DepartmentController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/departments/bulk_delete | DepartmentController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/departments/bulk_delete_per | DepartmentController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/departments/check-name | DepartmentController@checkName | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/departments/duplicate | DepartmentController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/departments/import | DepartmentController@import | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/departments/restore_records | DepartmentController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/departments/statusupdate | DepartmentController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/deposit-approvals/{id}/approve | VoucherApprovalController@approve | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/deposit-approvals/{id}/reject | VoucherApprovalController@reject | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/deposits | DepositController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/deposits/bulk_delete | DepositController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/deposits/duplicate | DepositController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/depreciation/run | FixedAssetDepreciationController@run | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/discounts | DiscountController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/discounts/apply | DiscountController@apply | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/discounts/bulk_delete | DiscountController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/discounts/bulk_delete_per | DiscountController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/discounts/restore_records | DiscountController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/discounts/statusupdate | DiscountController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/dispatch/assign | DispatchController@assign | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/dispatch/{id}/move | DispatchController@move | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/document-reminders | DocumentCenterController@saveReminder | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/document-reminders/run | DocumentCenterController@runReminders | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/document-templates | DocumentCenterController@saveTemplate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/document-templates/preview | DocumentCenterController@preview | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/document-templates/{id}/default | DocumentCenterController@makeDefault | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/documents/{id}/attachments | DocumentOutputController@attach | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/documents/{id}/send | DocumentOutputController@send | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/dunning/cases | DunningController@setCase | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/dunning/send | DunningController@send | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/early-payment-discounts | PaymentTermController@applyDiscount | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/ecommerce-connections | EcommerceConnectionController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/ecommerce-connections/{id}/orders/{orderId}/retry | EcommerceConnectionController@retry | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/ecommerce-connections/{id}/sync | EcommerceConnectionController@sync | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/expense-approvals/{id}/approve | VoucherApprovalController@approve | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/expense-approvals/{id}/reject | VoucherApprovalController@reject | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/expense-claims | ExpenseClaimController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/expense-claims/{id}/approve | ExpenseClaimController@approve | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/expense-claims/{id}/cancel | ExpenseClaimController@cancel | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/expense-claims/{id}/pay | ExpenseClaimController@pay | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/expense-claims/{id}/receipts | ExpenseClaimController@uploadReceipt | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/expense-claims/{id}/reject | ExpenseClaimController@reject | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/expense-claims/{id}/submit | ExpenseClaimController@submit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/expenses | ExpenseController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/expenses/bulk_delete | ExpenseController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/expenses/duplicate | ExpenseController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/fbr-submissions/{transactionId} | FbrController@submit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/fetchcities | CityController@fetch | throttle:120,1 | controller check | none |
| POST | /api/fetchcountries | CountryController@fetch | throttle:120,1 | controller check | none |
| POST | /api/fetchstates | StateController@fetch | throttle:120,1 | controller check | none |
| POST | /api/financialyears | FinancialYearController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/fixed-assets | FixedAssetController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/fixed-assets/acquire | FixedAssetAcquisitionController@acquire | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/fixed-assets/{id}/capitalise | FixedAssetAcquisitionController@capitalise | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/fixed-assets/{id}/cwip-cost | FixedAssetAcquisitionController@cwipCost | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/fixed-assets/{id}/dispose | FixedAssetLifecycleController@dispose | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/fixed-assets/{id}/impair | FixedAssetLifecycleController@impair | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/fixed-assets/{id}/revalue | FixedAssetLifecycleController@revalue | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/fixed-assets/{id}/transfer | FixedAssetTransferController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/foreign-currency/rates | ForeignCurrencyController@addRate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/foreign-currency/settle | ForeignCurrencyController@settle | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/fund-transfer-approvals/{id}/approve | VoucherApprovalController@approve | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/fund-transfer-approvals/{id}/reject | VoucherApprovalController@reject | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/fundtransfers | FundTransferController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/fundtransfers/bulk_delete | FundTransferController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/fundtransfers/duplicate | FundTransferController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/gateway/stripe/webhook/{company} | IntegrationsController@stripeWebhook | throttle:60,1 | controller check | company scoped |
| POST | /api/gift-cards | GiftCardController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/gift-cards/bulk_delete | GiftCardController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/gift-cards/bulk_delete_per | GiftCardController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/gift-cards/lookup | GiftCardController@lookup | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/gift-cards/orphan-refunds/{id}/resolve | GiftCardController@resolveOrphanRefund | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/gift-cards/restore_records | GiftCardController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/gift-cards/statusupdate | GiftCardController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/gift-cards/{id}/redeem | GiftCardController@redeem | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/gift-cards/{id}/topup | GiftCardController@topup | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/attendance | HrmAttendanceController@record | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/attendance/import | HrmAttendanceController@import | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/attendance/process-punches | HrmAttendanceController@processPunches | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/bonuses | HrmPayrollController@saveBonus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/components | HrmController@saveComponent | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/device/punch | HrmAttendanceController@devicePunch | throttle:120,1 | controller check | company scoped |
| POST | /api/hrm/devices | HrmAttendanceController@createDevice | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/employees | HrmController@saveEmployee | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/employees/{id}/documents | HrmController@uploadDocument | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/employees/{id}/salary | HrmController@saveSalary | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/leave-requests | HrmAttendanceController@requestLeave | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/leave-requests/{id}/cancel | HrmAttendanceController@cancelLeave | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/leave-types | HrmController@saveLeaveType | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/loans | HrmPayrollController@saveLoan | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/loans/{id}/close | HrmPayrollController@closeLoan | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/payroll | HrmPayrollController@createRun | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/payroll/{id}/cancel | HrmPayrollController@cancelRun | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/payroll/{id}/post | HrmPayrollController@postRun | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/payroll/{id}/recalculate | HrmPayrollController@recalculate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/payslips/{lineId}/email | HrmPayrollController@emailPayslip | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/rates | HrmController@saveRate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/shifts | HrmController@saveShift | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/hrm/tax-slabs | HrmController@saveSlab | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/inbound-shipments | InboundShipmentController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/installment-plans | InstallmentPlanController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/installment-plans/{id}/cancel | InstallmentPlanController@cancel | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/installment-plans/{id}/pay | InstallmentPlanController@pay | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/integrations/bank-feed/fetch | IntegrationsController@bankFeedFetch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/integrations/courier/book | IntegrationsController@courierBook | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/integrations/gateway/checkout | IntegrationsController@gatewayCheckout | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/interbranch | InterBranchController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/interbranch/{id}/reverse | InterBranchController@reverse | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/inventory-close | InventoryCloseController@close | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/inventory-close/reopen | InventoryCloseController@reopen | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/issue-notes | IssueNoteController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/issue-notes/bulk_delete | IssueNoteController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/issue-notes/bulk_delete_per | IssueNoteController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/issue-notes/restore_records | IssueNoteController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/item-types | ItemTypeController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/item-types/bulk_delete | ItemTypeController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/item-types/bulk_delete_per | ItemTypeController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/item-types/check-name | ItemTypeController@checkName | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/item-types/duplicate | ItemTypeController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/item-types/import | ItemTypeController@import | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/item-types/restore_records | ItemTypeController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/item-types/statusupdate | ItemTypeController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/journal-entries | JournalEntryController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/journal-entries/bulk_delete | JournalEntryController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/journal-entries/duplicate | JournalEntryController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/journal-entry-approvals/{id}/approve | VoucherApprovalController@approve | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/journal-entry-approvals/{id}/reject | VoucherApprovalController@reject | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/journal-schedules | JournalScheduleController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/journal-schedules/run | JournalScheduleController@run | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/journal-schedules/{id}/cancel | Closure | auth:sanctum, ValidateBulkActionBody | - | - |
| POST | /api/journal-schedules/{id}/pause | Closure | auth:sanctum, ValidateBulkActionBody | - | - |
| POST | /api/journal-schedules/{id}/resume | Closure | auth:sanctum, ValidateBulkActionBody | - | - |
| POST | /api/lead-sources | LeadSourceController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/lead-sources/bulk_delete | LeadSourceController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/lead-sources/bulk_delete_per | LeadSourceController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/lead-sources/restore_records | LeadSourceController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/lead-sources/statusupdate | LeadSourceController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/leadcapture | PublicLeadCaptureController@store | throttle:lead-capture | none | none |
| POST | /api/leads | LeadController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/leads/bulk_delete | LeadController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/leads/bulk_delete_per | LeadController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/leads/import | LeadController@import | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/leads/restore_records | LeadController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/leads/statusupdate | LeadController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/leave-requests/{id}/approve | HrmAttendanceController@approveLeave | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/leave-requests/{id}/reject | HrmAttendanceController@rejectLeave | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/loans | LoanController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/loans/{id}/pay | LoanController@pay | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/loyalty/adjust | LoyaltyController@adjust | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/loyalty/earn | LoyaltyController@earn | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/loyalty/redeem | LoyaltyController@redeem | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/manufacturing/boms | ManufacturingController@saveBom | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/manufacturing/boms/{id}/archive | ManufacturingController@archiveBom | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/manufacturing/boms/{id}/new-version | ManufacturingController@newBomVersion | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/manufacturing/plans | ManufacturingController@savePlan | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/manufacturing/work-orders | ManufacturingController@createWorkOrder | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/manufacturing/work-orders/{id}/cancel | ManufacturingController@cancelWorkOrder | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/manufacturing/work-orders/{id}/checks | ManufacturingController@check | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/manufacturing/work-orders/{id}/close | ManufacturingController@close | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/manufacturing/work-orders/{id}/issue | ManufacturingController@issue | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/manufacturing/work-orders/{id}/labor | ManufacturingController@labor | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/manufacturing/work-orders/{id}/receive | ManufacturingController@receive | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/manufacturing/work-orders/{id}/scrap | ManufacturingController@scrap | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/manufacturing/work-orders/{id}/standard-labor | ManufacturingController@standardLabor | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/menus | MenuController@store | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/menus/bulk_delete | MenuController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/menus/bulk_delete_per | MenuController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/menus/duplicate | MenuController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/menus/import | MenuController@import | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/menus/restore_records | MenuController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/menus/statusupdate | MenuController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/menus/{id}/sort-order | MenuController@updateSortOrder | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/message-templates | DocumentCenterController@saveMessage | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/my-notifications/read-all | MyNotificationsController@readAll | auth:sanctum, ValidateBulkActionBody | none | none |
| POST | /api/my-notifications/{id}/read | MyNotificationsController@read | auth:sanctum, ValidateBulkActionBody | none | none |
| POST | /api/opening-stock | OpeningStockController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/opportunities | OpportunityController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/opportunities/bulk_delete | OpportunityController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/opportunities/bulk_delete_per | OpportunityController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/opportunities/restore_records | OpportunityController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/opportunities/{id}/move-stage | OpportunityController@moveStage | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/payment-approvals/{id}/approve | VoucherApprovalController@approve | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/payment-approvals/{id}/reject | VoucherApprovalController@reject | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/payment-terms | PaymentTermController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/payment-terms/assign | PaymentTermController@assign | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/payments | PaymentController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/payments/bulk_delete | PaymentController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/payments/duplicate | PaymentController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/payroll-runs/{id}/approve | HrmPayrollController@approveRun | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/period-locks | PeriodLockController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/permissions | PermissionController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/permissions/statusupdate | PermissionController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/petty-cash/funds | PettyCashController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/petty-cash/funds/{id}/expense | PettyCashController@expense | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/petty-cash/funds/{id}/replenish | PettyCashController@replenish | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/pipeline-stages | PipelineStageController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/pipeline-stages/bulk_delete | PipelineStageController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/pipeline-stages/bulk_delete_per | PipelineStageController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/pipeline-stages/restore_records | PipelineStageController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/pipeline-stages/statusupdate | PipelineStageController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/portal-requests/{id}/approve | PortalRequestController@approve | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/portal-requests/{id}/reject | PortalRequestController@reject | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/portal-users | PortalUserController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/portal-users/{id}/reset-password | PortalUserController@resetPassword | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/portal/bills | PortalSupplierController@storeBill | auth:sanctum, ValidateBulkActionBody, throttle:portal-submit | none | company scoped |
| POST | /api/portal/change-password | PortalController@changePassword | auth:sanctum, ValidateBulkActionBody | none | company scoped |
| POST | /api/portal/requests | PortalCustomerController@storeRequest | auth:sanctum, ValidateBulkActionBody, throttle:portal-submit | none | company scoped |
| POST | /api/portal/requests/{id}/cancel | PortalCustomerController@cancelRequest | auth:sanctum, ValidateBulkActionBody, throttle:portal-submit | none | company scoped |
| POST | /api/portal/subscriptions/{id}/cancel | PortalSubscriptionController@cancel | auth:sanctum, ValidateBulkActionBody | none | company scoped |
| POST | /api/portal/subscriptions/{id}/change-plan | PortalSubscriptionController@changePlan | auth:sanctum, ValidateBulkActionBody | none | company scoped |
| POST | /api/portal/subscriptions/{id}/pause | PortalSubscriptionController@pause | auth:sanctum, ValidateBulkActionBody | none | company scoped |
| POST | /api/portal/subscriptions/{id}/resume | PortalSubscriptionController@resume | auth:sanctum, ValidateBulkActionBody | none | company scoped |
| POST | /api/pos-shifts/open | PosShiftController@open | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/pos-shifts/{id}/close | PosShiftController@close | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/pos-shifts/{id}/movement | PosShiftController@movement | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/pos/offline/sales/{id}/resolve | PosOfflineController@resolve | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/pos/offline/sync | PosOfflineController@sync | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/pricelist-approvals/{id}/approve | PriceListApprovalController@approve | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/pricelists | PriceListController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/pricelists/bulk_delete | PriceListController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/pricelists/bulk_delete_per | PriceListController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/pricelists/restore_records | PriceListController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/product-attributes | ProductAttributeController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/product-discount-rules | ProductDiscountRuleController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/products | ProductController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/products/bulk-update | ProductBulkController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/products/bulk_delete | ProductController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/products/bulk_delete_per | ProductController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/products/check-name | ProductController@checkName | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/products/duplicate | ProductController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/products/generate-variants | ProductController@generateVariants | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/products/import | ProductController@import | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/products/restore_records | ProductController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/products/statusupdate | ProductController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/products/{id}/assemble | ProductComponentController@assemble | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/project-work/{projectId}/milestones | ProjectWorkController@saveMilestone | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/project-work/{projectId}/milestones/{id}/status | ProjectWorkController@milestoneStatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/project-work/{projectId}/tasks | ProjectWorkController@saveTask | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/project-work/{projectId}/tasks/{id}/status | ProjectWorkController@taskStatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/projects | ProjectController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/projects/tag | ProjectController@tag | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchase-approval-rules | PurchaseApprovalRuleController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchase-approvals/{id}/approve | PurchaseApprovalController@approve | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchase-payments | PurchasePaymentController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchase-payments/bulk_delete | PurchasePaymentController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchase-requisition-approvals/{id}/approve | PurchaseRequisitionApprovalController@approve | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchase-requisition-approvals/{id}/reject | PurchaseRequisitionApprovalController@reject | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchase-requisitions | PurchaseRequisitionController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchase-requisitions/bulk_delete | PurchaseRequisitionController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchase-return-approvals/{id}/approve | PurchaseReturnApprovalController@approve | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchase-returns | PurchaseReturnController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchase-returns/bulk_delete | PurchaseReturnController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchase-returns/bulk_delete_per | PurchaseReturnController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchase-returns/restore_records | PurchaseReturnController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchases | PurchaseController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchases/bulk_delete | PurchaseController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchases/bulk_delete_per | PurchaseController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchases/duplicate | PurchaseController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchases/restore_records | PurchaseController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/purchases/statusupdate | PurchaseController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/push/subscribe | BusinessSettingsController@subscribe | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/push/test | BusinessSettingsController@pushTest | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/push/unsubscribe | BusinessSettingsController@unsubscribe | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/quality-inspections | QualityInspectionController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/quotations/{id}/convert | QuotationController@convert | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/receiving-notes | ReceivingNoteController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/receiving-notes/bulk_delete | ReceivingNoteController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/receiving-notes/bulk_delete_per | ReceivingNoteController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/receiving-notes/restore_records | ReceivingNoteController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/recurring-invoices | RecurringInvoiceController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/recurring-invoices/generate | RecurringInvoiceController@generate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/rental/bookings | RentalController@book | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/rental/bookings/{id}/cancel | RentalController@cancel | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/rental/bookings/{id}/pickup | RentalController@pickup | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/rental/bookings/{id}/return | RentalController@return | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/rental/items | RentalController@saveItem | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/reorder-suggestions/purchase-orders | ReorderSuggestionController@createOrders | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/report-builder | ReportBuilderController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/report-builder/preview | ReportBuilderController@preview | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/report-filters | ReportFilterController@store | auth:sanctum, ValidateBulkActionBody | none | company scoped |
| POST | /api/report-schedules | ReportScheduleController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/report-schedules/{id}/run | ReportScheduleController@runNow | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/riders | DispatchController@storeRider | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/rma | RmaController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/rma/{id}/status | RmaController@transition | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/roles | RoleController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/roles/bulk_delete | RoleController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/roles/bulk_delete_per | RoleController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/roles/check-name | RoleController@checkName | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/roles/duplicate | RoleController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/roles/import | RoleController@import | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/roles/restore_records | RoleController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/roles/statusupdate | RoleController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/saas/addons | SaasAdminController@saveAddon | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/saas/billing/run | SaasAdminController@runBilling | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/saas/companies/{companyId}/addons | SaasAdminController@assignAddon | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/saas/companies/{companyId}/reactivate | SaasAdminController@reactivate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/saas/deletions/purge-due | SaasAdminController@purgeDue | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/saas/partners | SaasAdminController@savePartner | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/saas/usage/snapshot | SaasAdminController@snapshot | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sales-kpi/targets | SalesKpiController@setTarget | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sales-kpi/tiers | SalesKpiController@storeTier | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sales-orders | SalesOrderController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sales-orders/{id}/approve | SalesOrderController@approve | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sales-orders/{id}/cancel | SalesOrderController@cancel | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sales-orders/{id}/invoice | SalesOrderController@invoice | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sales-orders/{id}/submit | SalesOrderController@submit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sales-routes | SalesRouteController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sales-routes/{id}/visit | SalesRouteController@visit | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/security/ip-rules | SecurityCenterController@addIpRule | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/security/privacy/anonymize | SecurityCenterController@privacyAnonymize | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/security/privacy/export | SecurityCenterController@privacyExport | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/security/sessions/{id}/revoke | SecurityCenterController@revokeSession | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/security/users/{userId}/revoke-sessions | SecurityCenterController@revokeUserSessions | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sell-approvals/{id}/approve | SellApprovalController@approve | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sell-payments | SellPaymentController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sell-payments/bulk_delete | SellPaymentController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sell-returns | SellReturnController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sell-returns/bulk_delete | SellReturnController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sell-returns/bulk_delete_per | SellReturnController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sell-returns/restore_records | SellReturnController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sells | SellController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sells/bulk_delete | SellController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sells/bulk_delete_per | SellController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sells/duplicate | SellController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sells/import | SaleImportController@import | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sells/restore_records | SellController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sells/statusupdate | SellController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/sells/{id}/receipt-share | ReceiptShareController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/service/jobs | ServiceJobController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/service/jobs/{id}/assign | ServiceJobController@assign | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/service/jobs/{id}/invoice | ServiceJobController@invoice | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/service/jobs/{id}/parts | ServiceJobController@addPart | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/service/jobs/{id}/signature | ServiceJobController@signature | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/service/jobs/{id}/status | ServiceJobController@status | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/software-settings/test-send | SettingController@testSend | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/software-settings/test-smtp | SettingController@testSmtp | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/states | StateController@store | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/states/bulk_delete | StateController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/states/bulk_delete_per | StateController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/states/check-name | StateController@checkName | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/states/fetch-from-api | StateController@fetchFromApi | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/states/restore_records | StateController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/states/statusupdate | StateController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/stock-adjustment-approvals/{id}/approve | StockAdjustmentApprovalController@approve | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stock-reservations | StockReservationController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stock-reservations/{id}/close | StockReservationController@close | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stock-takes | StockTakeController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stock-takes/bulk_delete | StockTakeController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stock-takes/bulk_delete_per | StockTakeController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stock-takes/restore_records | StockTakeController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stock-takes/{id}/complete | StockTakeController@complete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stock-tracking/batches | StockTrackingController@registerBatch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stock-tracking/batches/{id}/write-off | StockTrackingController@writeOffBatch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stock-tracking/serials | StockTrackingController@registerSerials | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stock-tracking/serials/{id}/write-off | StockTrackingController@writeOffSerial | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stock-transfer-approvals/{id}/approve | StockTransferApprovalController@approve | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stock-writeoffs | StockWriteoffController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stockadjustments | StockAdjustmentController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stockadjustments/bulk_delete | StockAdjustmentController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stockadjustments/bulk_delete_per | StockAdjustmentController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stockadjustments/restore_records | StockAdjustmentController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stockadjustments/statusupdate | StockAdjustmentController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stocktransfers | StockTransferController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stocktransfers/bulk_delete | StockTransferController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stocktransfers/bulk_delete_per | StockTransferController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stocktransfers/restore_records | StockTransferController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/stocktransfers/statusupdate | StockTransferController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/store-credit/apply | StoreCreditController@apply | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/store-credit/issue | StoreCreditController@issue | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/store-credit/refund | StoreCreditController@refund | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/storefront-admin/orders/{id}/convert | StorefrontController@convert | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/storefront-admin/orders/{id}/reject | StorefrontController@rejectOrder | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/storefront-admin/products | StorefrontController@saveProduct | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/storefront/{code}/orders | PublicStorefrontController@order | throttle:20,1 | none | company scoped |
| POST | /api/subscriptioninvoices/generate | SubscriptionInvoiceController@generate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/subscriptioninvoices/{id}/cancel | SubscriptionInvoiceController@cancel | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/subscriptioninvoices/{id}/mark-paid | SubscriptionInvoiceController@markPaid | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/subscriptionplans | SubscriptionPlanController@store | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/subscriptionplans/bulk_delete | SubscriptionPlanController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/subscriptionplans/bulk_delete_per | SubscriptionPlanController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/subscriptionplans/restore_records | SubscriptionPlanController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/supplier-payments | SupplierPaymentController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/supplier-payments/{id}/apply-advance | SupplierPaymentController@applyAdvance | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/supplier-payments/{id}/refund-advance | SupplierPaymentController@refundAdvance | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/supplier-payments/{id}/reverse | SupplierPaymentController@reverse | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/suppliers | SupplierController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/suppliers/bulk_delete | SupplierController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/suppliers/bulk_delete_per | SupplierController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/suppliers/duplicate | SupplierController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/suppliers/import | SupplierController@import | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/suppliers/restore_records | SupplierController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/suppliers/statusupdate | SupplierController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/suppliers/{id}/attachments | ContactAttachmentController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/suppliers/{id}/link-coa | SupplierController@linkCoa | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/support-tickets | TenantSelfServiceController@openTicket | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/support-tickets/{id}/reply | TenantSelfServiceController@replyTicket | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/system/backups/{id}/copy | SystemCenterController@copyBackup | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/system/backups/{id}/restore | SystemCenterController@restoreBackup | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/system/demo-companies | SystemCenterController@createDemo | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/system/franchise-groups | SystemCenterController@saveGroup | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/system/help-articles | SystemCenterController@saveArticle | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/system/push/keys | SystemCenterController@generatePushKeys | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/tax-exemptions | TaxExemptionController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/tax-payments | TaxPaymentController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/taxes | TaxController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/taxes/bulk_delete | TaxController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/taxes/preview | App\Http\Controllers\TaxPreviewController | auth:sanctum, ValidateBulkActionBody | - | - |
| POST | /api/taxes/statusupdate | TaxController@updateStatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/tenant-billing/cancel | TenantSelfServiceController@cancel | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/tenant-billing/deletion/cancel | TenantSelfServiceController@cancelDeletion | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/tenant-billing/deletion/confirm | TenantSelfServiceController@confirmDeletion | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/tenant-billing/deletion/request | TenantSelfServiceController@requestDeletion | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/tenant-billing/domains | TenantSelfServiceController@addDomain | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/tenant-billing/domains/{id}/verify | TenantSelfServiceController@verifyDomain | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/tenant-billing/exports | TenantSelfServiceController@export | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/tenant-billing/renew | TenantSelfServiceController@renew | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/tenants/{id}/plan | TenantController@changePlan | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/tenants/{id}/status | TenantController@updateStatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/timezones | TimezoneController@store | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/timezones/bulk_delete | TimezoneController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/timezones/bulk_delete_per | TimezoneController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/timezones/check-name | TimezoneController@checkName | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/timezones/fetch-from-api | TimezoneController@fetchFromApi | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/timezones/restore_records | TimezoneController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/transporters | TransporterController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/transporters/bulk_delete | TransporterController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/transporters/bulk_delete_per | TransporterController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/transporters/restore_records | TransporterController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/transporters/statusupdate | TransporterController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/units | UnitController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/units/bulk_delete | UnitController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/units/bulk_delete_per | UnitController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/units/check-name | UnitController@checkName | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/units/duplicate | UnitController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/units/import | UnitController@import | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/units/restore_records | UnitController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/units/statusupdate | UnitController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/users | UserController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/users/bulk_delete | UserController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/users/bulk_delete_per | UserController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/users/check-identity | UserController@checkIdentity | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/users/duplicate | UserController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/users/import | UserController@import | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/users/restore_records | UserController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/users/statusupdate | UserController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/users/{id}/send-credentials | UserController@sendCredentials | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/valuation/method | ValuationController@setMethod | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/valuation/recalculate | ValuationController@recalculate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/valuation/standard-costs | ValuationController@setStandardCosts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/variations | VariationController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/variations/bulk_delete | VariationController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/variations/bulk_delete_per | VariationController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/variations/duplicate | VariationController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/variations/import | VariationController@import | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/variations/restore_records | VariationController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/variations/statusupdate | VariationController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warehouse-locations | WarehouseLocationController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warehouse-tasks | WarehouseTaskController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warehouse-tasks/{id}/cancel | WarehouseTaskController@cancel | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warehouse-tasks/{id}/complete | WarehouseTaskController@complete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warehouse-tasks/{id}/progress | WarehouseTaskController@progress | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warehouses | WarehouseController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warehouses/bulk_delete | WarehouseController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warehouses/bulk_delete_per | WarehouseController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warehouses/restore_records | WarehouseController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warehouses/statusupdate | WarehouseController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warranties | WarrantyController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warranties/bulk_delete | WarrantyController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warranties/bulk_delete_per | WarrantyController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warranties/check-name | WarrantyController@checkName | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warranties/duplicate | WarrantyController@duplicate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warranties/import | WarrantyController@import | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warranties/restore_records | WarrantyController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warranties/statusupdate | WarrantyController@updatestatus | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warranty-claims | WarrantyClaimController@store | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/warranty-claims/{id}/status | WarrantyClaimController@transition | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/webhooks | WebhookController@store | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/webhooks/bulk_delete | WebhookController@bulk_delete | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/webhooks/bulk_delete_per | WebhookController@bulk_delete_per | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/webhooks/restore_records | WebhookController@restore_records | auth:sanctum, ValidateBulkActionBody | controller check | none |
| POST | /api/work-orders/{id}/approve | ManufacturingController@releaseWorkOrder | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/year-close | YearCloseController@run | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /api/year-rollover | YearRolloverController@run | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| POST | /checkSMTP | HomeController@check_smtp | auth | none | none |
| POST | /email_setting/test-send | SettingController@email_test_send | auth, verified, EnforcePageMenuPermission | page middleware | none |
| POST | /idle-timeout-alert/ping | Closure | auth | - | - |
| POST | /register | PublicRegistrationController@store | guest, throttle:self-signup | none | none |
| POST | /set_cookie | Closure |  | - | - |
| PUT | /api/approval-center/settings | ApprovalCenterController@saveSettings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/approval-center/workflows/{id} | ApprovalCenterController@saveWorkflow | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/asset-categories/{id} | AssetCategoryController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/bad-debt/settings | BadDebtController@saveSettings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/bank-instruments/{id} | BankInstrumentController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/budgets/{id} | BudgetController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/business-settings | BusinessSettingsController@save | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/business-settings/branches/{id}/parent | BusinessSettingsController@moveBranch | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/business-settings/notification-templates/{kind} | BusinessSettingsController@saveNotificationTemplate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/business-settings/rate-feed | BusinessSettingsController@saveRateFeed | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/business-settings/retention | BusinessSettingsController@saveRetention | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/cashiers/{id} | CashierController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/cheque-layouts/{id} | ChequePrintController@updateLayout | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/company-settings/{companyId} | CompanySettingController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/cost-centers/{id} | CostCenterController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/crm/allocation-rules/{id} | CrmAutomationController@saveRule | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/currency-rates | CurrencyRateController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/custom-fields/{id} | DocumentCenterController@saveField | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/custom-values/{entity}/{id} | DataCenterController@setCustomValues | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/dashboard-preferences | DashboardInsightsController@savePreferences | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/dashboard-tools/role-layouts/{roleId} | DashboardToolsController@saveRoleLayout | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/dashboard-tools/summary | DashboardToolsController@saveSummary | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/document-delivery | DocumentCenterController@saveDelivery | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/document-reminders/{id} | DocumentCenterController@saveReminder | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/document-settings/{group} | DocumentSettingController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/document-templates/{id} | DocumentCenterController@saveTemplate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/documents/{id}/custom-fields | DocumentOutputController@setCustomValues | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/dunning/levels/{level} | DunningController@updateLevel | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/ecommerce-connections/{id} | EcommerceConnectionController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/expense-claims/{id} | ExpenseClaimController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/fbr-settings | FbrController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/fixed-assets/{id} | FixedAssetController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/foreign-currency/settings | ForeignCurrencyController@saveSettings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/hrm/components/{id} | HrmController@saveComponent | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/hrm/employees/{id} | HrmController@saveEmployee | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/hrm/leave-types/{id} | HrmController@saveLeaveType | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/hrm/rates/{id} | HrmController@saveRate | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/hrm/roster | HrmController@saveRoster | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/hrm/settings | HrmController@saveSettings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/hrm/shifts/{id} | HrmController@saveShift | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/hrm/tax-slabs/{id} | HrmController@saveSlab | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/import-purchases/{id} | ImportPurchaseController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/inbound-shipments/{id} | InboundShipmentController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/integrations/accounts | IntegrationsController@saveAccount | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/interbranch/settings | InterBranchController@saveSettings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/journal-schedules/{id} | JournalScheduleController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/landed-costs/{id} | LandedCostController@update | auth:sanctum, ValidateBulkActionBody | controller check | none |
| PUT | /api/loyalty/settings | LoyaltyController@updateSettings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/manufacturing/boms/{id} | ManufacturingController@saveBom | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/manufacturing/settings | ManufacturingController@saveSettings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/message-templates/{id} | DocumentCenterController@saveMessage | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/messaging-settings/{channel} | MessagingSettingController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/payment-terms/{id} | PaymentTermController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/period-locks/rules | PeriodLockController@updateRules | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/petty-cash/funds/{id} | PettyCashController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/product-attributes/{id} | ProductAttributeController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/product-discount-rules/{id} | ProductDiscountRuleController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/products/{id}/components | ProductComponentController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/project-work/{projectId}/milestones/{id} | ProjectWorkController@saveMilestone | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/project-work/{projectId}/tasks/{id} | ProjectWorkController@saveTask | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/projects/{id} | ProjectController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/purchase-approval-rules/{id} | PurchaseApprovalRuleController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/quotations/{id} | QuotationController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/recurring-invoices/{id} | RecurringInvoiceController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/rental/items/{id} | RentalController@saveItem | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/rental/settings | RentalController@saveSettings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/report-builder/{id} | ReportBuilderController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/report-filters/{id}/favourite | ReportFilterController@favourite | auth:sanctum, ValidateBulkActionBody | none | company scoped |
| PUT | /api/report-schedules/{id} | ReportScheduleController@toggle | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/riders/{id} | DispatchController@updateRider | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/saas/addons/{id} | SaasAdminController@saveAddon | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/saas/companies/{companyId}/partner | SaasAdminController@assignPartner | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/saas/partners/{id} | SaasAdminController@savePartner | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/saas/plans/{id} | SaasAdminController@savePlan | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/sales-routes/{id} | SalesRouteController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/security/settings | SecurityCenterController@saveSettings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/sells/{id}/shipping | SellController@updateShipping | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/service/jobs/{id} | ServiceJobController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/setup-wizard/{key} | BusinessSettingsController@skipStep | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/software-settings | SettingController@update | auth:sanctum, ValidateBulkActionBody | controller check | none |
| PUT | /api/stock-takes/{id}/counts | StockTakeController@counts | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/storefront-admin/settings | StorefrontController@saveSettings | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/system/backup-remote | SystemCenterController@chooseBackupDisk | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/system/franchise-groups/{id} | SystemCenterController@saveGroup | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/system/help-articles/{id} | SystemCenterController@saveArticle | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/system/push/subject | SystemCenterController@pushSubject | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/system/retention | SystemCenterController@saveRetention | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/tax-exemptions/{id} | TaxExemptionController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/tenant-billing/branding | TenantSelfServiceController@saveBranding | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /api/warehouse-locations/{id} | WarehouseLocationController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT | /settings/password | SecurityController@update | auth, verified, throttle:6,1 | none | none |
| PUT | /storage/{path} | Closure |  | - | - |
| PUT/PATCH | /api/activities/{activity} | ActivityController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/bank-issuers/{bank_issuer} | BankIssuerController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/banks/{bank} | BankController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/blog-posts/{blog_post} | BlogPostController@update | auth:sanctum, ValidateBulkActionBody | controller check | none |
| PUT/PATCH | /api/branches/{branch} | BranchController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/brands/{brand} | BrandController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/cash-collections/{cash_collection} | CashCollectionController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/categories/{category} | CategoryController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/chart-of-accounts/{chart_of_account} | ChartOfAccountController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/cities/{city} | CityController@update | auth:sanctum, ValidateBulkActionBody | controller check | none |
| PUT/PATCH | /api/commission-agents/{commission_agent} | CommissionAgentController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/companies/{company} | CompanyController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/consumers/{consumer} | ConsumerController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/countries/{country} | CountryController@update | auth:sanctum, ValidateBulkActionBody | controller check | none |
| PUT/PATCH | /api/coupons/{coupon} | CouponController@update | auth:sanctum, ValidateBulkActionBody | controller check | none |
| PUT/PATCH | /api/credit-debit-notes/{credit_debit_note} | CreditDebitNoteController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/currencies/{currency} | CurrencyController@update | auth:sanctum, ValidateBulkActionBody | controller check | none |
| PUT/PATCH | /api/customer-groups/{customer_group} | CustomerGroupController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/customers/{customer} | CustomerController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/customersubscriptionplans/{customersubscriptionplan} | CustomerSubscriptionPlanController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/departments/{department} | DepartmentController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/deposits/{deposit} | DepositController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/discounts/{discount} | DiscountController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/expenses/{expense} | ExpenseController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/financialyears/{financialyear} | FinancialYearController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/fundtransfers/{fundtransfer} | FundTransferController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/gift-cards/{gift_card} | GiftCardController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/issue-notes/{issue_note} | IssueNoteController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/item-types/{item_type} | ItemTypeController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/journal-entries/{journal_entry} | JournalEntryController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/lead-sources/{lead_source} | LeadSourceController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/leads/{lead} | LeadController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/menus/{menu} | MenuController@update | auth:sanctum, ValidateBulkActionBody | controller check | none |
| PUT/PATCH | /api/opportunities/{opportunity} | OpportunityController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/payments/{payment} | PaymentController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/pipeline-stages/{pipeline_stage} | PipelineStageController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/pricelists/{pricelist} | PriceListController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/products/{product} | ProductController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/purchase-payments/{purchase_payment} | PurchasePaymentController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/purchase-requisitions/{purchase_requisition} | PurchaseRequisitionController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/purchase-returns/{purchase_return} | PurchaseReturnController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/purchases/{purchase} | PurchaseController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/receiving-notes/{receiving_note} | ReceivingNoteController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/roles/{role} | RoleController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/sell-payments/{sell_payment} | SellPaymentController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/sell-returns/{sell_return} | SellReturnController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/sells/{sell} | SellController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/states/{state} | StateController@update | auth:sanctum, ValidateBulkActionBody | controller check | none |
| PUT/PATCH | /api/stockadjustments/{stockadjustment} | StockAdjustmentController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/stocktransfers/{stocktransfer} | StockTransferController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/subscriptionplans/{subscriptionplan} | SubscriptionPlanController@update | auth:sanctum, ValidateBulkActionBody | controller check | none |
| PUT/PATCH | /api/suppliers/{supplier} | SupplierController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/taxes/{tax} | TaxController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/timezones/{timezone} | TimezoneController@update | auth:sanctum, ValidateBulkActionBody | controller check | none |
| PUT/PATCH | /api/transporters/{transporter} | TransporterController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/units/{unit} | UnitController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/users/{user} | UserController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/variations/{variation} | VariationController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/warehouses/{warehouse} | WarehouseController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/warranties/{warranty} | WarrantyController@update | auth:sanctum, ValidateBulkActionBody | controller check | company scoped |
| PUT/PATCH | /api/webhooks/{webhook} | WebhookController@update | auth:sanctum, ValidateBulkActionBody | controller check | none |
